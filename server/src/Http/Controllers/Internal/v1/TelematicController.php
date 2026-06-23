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
            $provider = $this->registry->resolve($key);
            if (!$provider) {
                return response()->error('Unable to resolve telematic provider.');
            }
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
