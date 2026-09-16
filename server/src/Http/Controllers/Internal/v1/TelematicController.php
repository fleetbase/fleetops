<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\TelematicExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\Telematic;
use Fleetbase\FleetOps\Support\Telematics\TelematicProviderRegistry;
use Fleetbase\FleetOps\Support\Telematics\TelematicService;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class TelematicController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'telematic';

    protected TelematicService $telematicService;
    protected TelematicProviderRegistry $registry;

    public function __construct(TelematicService $service, TelematicProviderRegistry $registry)
    {
        parent::__construct();
        $this->telematicService  = $service;
        $this->registry          = $registry;
    }

    /**
     * Export telematics to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format     = $request->input('format', 'xlsx');
        $selections = $request->array('selections');
        $fileName   = trim(Str::slug('telematics-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new TelematicExport($selections), $fileName);
    }

    /**
     * Query callback when querying record.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param Request                            $request
     */
    public static function onQueryRecord($query, $request): void
    {
        $query->with(['warranty']);
    }

    /**
     * List available providers.
     */
    public function providers(): JsonResponse
    {
        $providers = $this->registry->all()->map(fn ($p) => $p->toArray());

        return response()->json($providers->values());
    }

    /**
     * Test connection to provider.
     */
    public function testConnection(Request $request, string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);

        $async = $request->input('async', false);

        $result = $this->telematicService->testConnection($telematic, $async);

        if ($async) {
            return response()->json($result, 202);
        }

        return response()->json($result);
    }

    /**
     * Test connection to provider.
     */
    public function testCredentials(Request $request, string $key): JsonResponse
    {
        $credentials = $request->array('credentials', []);
        $async       = $request->input('async', false);

        try {
            $provider    = $this->registry->resolve($key);
            $result      = $provider->testConnection($credentials);
            $telematicId = $request->input('telematic_id');

            if ($telematicId) {
                $telematic = $this->findTelematic($telematicId);

                if ($telematic->provider === $key) {
                    $this->telematicService->recordConnectionTest($telematic, $result);
                }
            }
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }

        if ($async) {
            return response()->json($result, 202);
        }

        return response()->json($result);
    }

    /**
     * Discover devices from provider.
     */
    public function discover(Request $request, string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);

        $jobId = $this->telematicService->discoverDevices($telematic, [
            'limit'   => $request->input('limit'),
            'filters' => $request->input('filters', []),
        ]);

        return response()->json([
            'job_id'  => $jobId,
            'message' => 'Device discovery initiated',
        ], 202);
    }

    /**
     * Get devices for a telematic.
     */
    public function devices(Request $request, string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);

        $devices = $this->telematicService->getDevices($telematic, [
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ]);

        return response()->json([
            'data' => $devices,
        ]);
    }

    /**
     * Get persisted logs and audit entries for a telematic.
     */
    public function logs(Request $request, string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);
        $limit     = min((int) $request->input('limit', 50), 100);

        $activityLogs = Activity::with(['causer'])
            ->where('subject_type', Telematic::class)
            ->where('subject_id', $telematic->uuid)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Activity $activity) => $this->makeActivityLogEntry($activity));

        $logs = $this->makeTelematicMetadataLogs($telematic)
            ->merge($activityLogs)
            ->sortByDesc(fn ($log) => $log['created_at'] ?? '')
            ->values();

        return response()->json([
            'logs' => $logs,
        ]);
    }

    /**
     * Link a device to a telematic.
     */
    public function linkDevice(Request $request, string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);

        $request->validate([
            'external_id' => 'required_without:device_id|nullable|string',
            'device_id'   => 'required_without:external_id|nullable|string',
            'device_name' => 'nullable|string',
            'name'        => 'nullable|string',
        ]);

        $device = $this->telematicService->linkDevice($telematic, $request->all());

        return response()->json([
            'device' => $device,
        ], 201);
    }

    public function telemetryWebhook(Request $request, string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);
        $provider  = $this->registry->resolve($telematic->provider);
        abort_unless($provider instanceof \Fleetbase\FleetOps\Contracts\TelemetryProviderInterface, 422);
        abort_unless($provider->supportsWebhooks(), 422);
        $url = \Fleetbase\Support\Utils::apiUrl('webhooks/telematics/' . $telematic->provider);
        abort_unless(str_starts_with($url, 'https://'), 422, 'Configure the public API URL with HTTPS before registering the webhook.');
        $token = \Illuminate\Support\Facades\DB::transaction(function () use ($telematic, $request) {
            // Serialize first-time provisioning as well as rotation.
            Telematic::where('uuid', $telematic->uuid)->lockForUpdate()->firstOrFail();
            $stored = \Illuminate\Support\Facades\DB::table('telematic_webhook_credentials')->where('telematic_uuid', $telematic->uuid)->first();
            if ($stored && !$request->boolean('rotate')) {
                return \Illuminate\Support\Facades\Crypt::decryptString($stored->token);
            }
            $token = bin2hex(random_bytes(32));
            \Illuminate\Support\Facades\DB::table('telematic_webhook_credentials')->updateOrInsert(['telematic_uuid' => $telematic->uuid], [
                'token' => \Illuminate\Support\Facades\Crypt::encryptString($token), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $token;
        });

        return response()->json(['url' => $url . '?' . http_build_query(['telematic' => $telematic->public_id, 'key' => $token])]);
    }

    public function telemetryDiagnostics(string $id): JsonResponse
    {
        $telematic = $this->findTelematic($id);
        $provider  = $this->registry->resolve($telematic->provider);
        abort_unless($provider instanceof \Fleetbase\FleetOps\Contracts\TelemetryProviderInterface, 422);
        $options     = \Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration::options($provider);
        $query       = \Illuminate\Support\Facades\DB::table('telematic_deliveries')->where('telematic_uuid', $telematic->uuid);
        $last        = (clone $query)->where('source', 'webhook')->orderByDesc('received_at')->first(['uuid', 'status', 'received_at', 'processed_at', 'error']);
        $provisioned = \Illuminate\Support\Facades\DB::table('telematic_webhook_credentials')->where('telematic_uuid', $telematic->uuid)->exists();
        $state       = !$provisioned ? 'not_configured' : (!$last ? 'awaiting_first_delivery' : ($last->status === 'quarantined' || \Illuminate\Support\Carbon::parse($last->received_at)->lt(now()->subMinutes(10)) ? 'degraded' : 'receiving'));

        return response()->json([
            'polling_enabled'   => $options['polling_enabled'] ?? false,
            'webhooks_enabled'  => $options['webhooks_enabled'] ?? false,
            'webhook_state'     => $state, 'last_webhook' => $last,
            'last_poll'         => \Illuminate\Support\Facades\DB::table('telematic_sync_runs')->where('telematic_uuid', $telematic->uuid)->orderByDesc('created_at')->first(),
            'last_ingestion'    => (clone $query)->whereNotNull('processed_at')->orderByDesc('received_at')->first(['queue_delay_seconds', 'source_delay_seconds', 'processed_at']),
            'delivery_counts'   => (clone $query)->whereIn('status', ['pending', 'retry', 'processing'])->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'oldest_pending_at' => (clone $query)->whereIn('status', ['pending', 'retry', 'processing'])->min('received_at'),
            'recent_failures'   => (clone $query)->whereIn('status', ['quarantined', 'retry'])->orderByDesc('received_at')->limit(10)->get(['uuid', 'status', 'source', 'received_at', 'applied', 'failed', 'error']),
        ]);
    }

    public function replayTelemetryDelivery(string $id, string $delivery): JsonResponse
    {
        $telematic = $this->findTelematic($id);
        $provider  = $this->registry->resolve($telematic->provider);
        abort_unless($provider instanceof \Fleetbase\FleetOps\Contracts\TelemetryProviderInterface, 422);
        $options = \Fleetbase\FleetOps\Support\Telematics\Telemetry\Configuration::options($provider);
        $updated = \Illuminate\Support\Facades\DB::table('telematic_deliveries')->where('telematic_uuid', $telematic->uuid)->where('uuid', $delivery)->where('status', 'quarantined')
            ->update(['status' => 'pending', 'attempts' => 0, 'retry_payload' => null, 'invalid_count' => 0, 'failed' => 0, 'applied' => 0, 'error' => null, 'available_at' => now(), 'processed_at' => null, 'updated_at' => now()]);
        abort_unless($updated, 404);
        try {
            \Fleetbase\FleetOps\Support\Telematics\Telemetry\Queue::dispatch((new \Fleetbase\FleetOps\Jobs\ProcessTelematicDelivery($delivery))->onQueue($options['ingestion_queue'] ?? 'default'));
        } catch (\Throwable) {
            // The durable replay is recovered by the scheduled inbox drain.
        }

        return response()->json(['status' => 'queued'], 202);
    }

    protected function findTelematic(string $id): Telematic
    {
        return Telematic::where(function ($query) use ($id) {
            $query->where('uuid', $id)->orWhere('public_id', $id);
        })
            ->where('company_uuid', session('company'))
            ->firstOrFail();
    }

    protected function makeTelematicMetadataLogs(Telematic $telematic)
    {
        $meta = $telematic->meta ?? [];
        $logs = collect();

        if (data_get($meta, 'last_sync_result')) {
            $result = data_get($meta, 'last_sync_result');
            $failed = $result === 'failed';

            $logs->push([
                'id'          => 'sync-' . (data_get($meta, 'last_sync_job_id') ?? data_get($meta, 'last_sync_completed_at') ?? data_get($meta, 'last_sync_failed_at') ?? $telematic->uuid),
                'type'        => 'sync_' . $result,
                'label'       => $failed ? 'Device sync failed' : ($result === 'queued' ? 'Device sync queued' : 'Device sync completed'),
                'description' => $failed
                    ? $this->userFacingIssueMessage(data_get($meta, 'last_sync_error'), 'Device sync failed. Review the provider connection and server logs, then try again.')
                    : $this->syncSuccessDescription($meta),
                'status'      => $failed ? 'warning' : ($result === 'queued' ? 'info' : 'success'),
                'icon'        => $failed ? 'circle-exclamation' : 'satellite-dish',
                'created_at'  => data_get($meta, 'last_sync_failed_at') ?? data_get($meta, 'last_sync_completed_at') ?? data_get($meta, 'last_sync_started_at'),
                'actor_name'  => null,
                'metadata'    => [
                    'job_id'       => data_get($meta, 'last_sync_job_id'),
                    'result'       => $result,
                    'total'        => data_get($meta, 'last_sync_total'),
                    'error_type'   => data_get($meta, 'last_sync_error_type'),
                    'started_at'   => data_get($meta, 'last_sync_started_at'),
                    'completed_at' => data_get($meta, 'last_sync_completed_at'),
                    'failed_at'    => data_get($meta, 'last_sync_failed_at'),
                ],
            ]);
        }

        if (data_get($meta, 'last_test_result')) {
            $result = data_get($meta, 'last_test_result');
            $failed = $result === 'failed';

            $logs->push([
                'id'          => 'connection-test-' . (data_get($meta, 'last_connection_test') ?? $telematic->uuid),
                'type'        => 'connection_test_' . $result,
                'label'       => $failed ? 'Connection test failed' : 'Connection test verified',
                'description' => $failed
                    ? $this->userFacingIssueMessage(data_get($meta, 'last_error'), 'Connection test failed. Review the provider credentials and try again.')
                    : 'Provider credentials were verified successfully.',
                'status'      => $failed ? 'warning' : 'success',
                'icon'        => 'plug',
                'created_at'  => data_get($meta, 'last_connection_test'),
                'actor_name'  => null,
                'metadata'    => [
                    'result' => $result,
                ],
            ]);
        }

        return $logs;
    }

    protected function makeActivityLogEntry(Activity $activity): array
    {
        return [
            'id'          => $activity->uuid ?? $activity->id,
            'type'        => 'activity_' . ($activity->event ?? 'updated'),
            'label'       => $this->activityLogLabel($activity),
            'description' => $this->activityLogDescription($activity),
            'status'      => $activity->event === 'deleted' ? 'warning' : 'default',
            'icon'        => $activity->event === 'created' ? 'plus' : 'history',
            'created_at'  => $activity->created_at,
            'actor_name'  => data_get($activity, 'causer.name'),
            'metadata'    => [
                'event' => $activity->event,
            ],
        ];
    }

    protected function activityLogLabel(Activity $activity): string
    {
        return match ($activity->event) {
            'created' => 'Provider connection created',
            'deleted' => 'Provider connection deleted',
            default   => 'Provider connection updated',
        };
    }

    protected function activityLogDescription(Activity $activity): string
    {
        return match ($activity->event) {
            'created' => 'Provider connection details were created.',
            'deleted' => 'Provider connection details were removed.',
            default   => 'Provider connection details were updated.',
        };
    }

    protected function syncSuccessDescription(array $meta): string
    {
        $total = data_get($meta, 'last_sync_total');

        if (is_numeric($total)) {
            return "{$total} provider devices were synced.";
        }

        return 'Provider device sync completed successfully.';
    }

    protected function userFacingIssueMessage($message, string $fallback): string
    {
        if (!$message || $this->isSensitiveIssueMessage($message)) {
            return $fallback;
        }

        return (string) $message;
    }

    protected function isSensitiveIssueMessage($message): bool
    {
        $value = strtolower((string) $message);

        foreach (['sqlstate', 'insert into', 'update `', 'select ', 'schema', 'stack trace', 'connection:', 'pdoexception'] as $fragment) {
            if (str_contains($value, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
