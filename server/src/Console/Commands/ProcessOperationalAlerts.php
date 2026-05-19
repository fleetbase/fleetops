<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Position;
use Fleetbase\FleetOps\Notifications\LateDeparture;
use Fleetbase\FleetOps\Notifications\ProlongedStoppage;
use Fleetbase\FleetOps\Notifications\RouteDeviation;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\Models\Setting;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class ProcessOperationalAlerts extends Command
{
    protected $signature = 'fleetops:process-operational-alerts
        {--days=2 : Only include orders created in the last N days}
        {--chunk=250 : How many orders to process per chunk}
        {--no-lock : Skip process locking}
        {--dry : Dry run without updating notification markers}';

    protected $description = 'Evaluate FleetOps operational alerts for late departures, route deviations, and prolonged stoppages.';

    public function handle(): int
    {
        $days     = max(1, (int) $this->option('days'));
        $perChunk = max(50, (int) $this->option('chunk'));
        $dryRun   = (bool) $this->option('dry');
        $useLock  = !$this->option('no-lock');
        $lock     = null;

        if ($useLock) {
            $lock = Cache::lock('fleetops:process-operational-alerts', 600);
            if (!$lock->get()) {
                $this->warn('Another operational alert run appears to be in progress.');

                return self::SUCCESS;
            }
        }

        try {
            $cutoff = Carbon::now()->subDays($days);
            $query  = $this->ordersQuery($cutoff);
            $total  = (clone $query)->count('id');

            if ($total === 0) {
                $this->info('No qualifying orders found.');

                return self::SUCCESS;
            }

            $triggered = 0;

            $query->orderBy('id')->chunkById($perChunk, function ($orders) use ($dryRun, &$triggered) {
                $orders->loadMissing(['driverAssigned', 'vehicleAssigned', 'route']);

                foreach ($orders as $order) {
                    session(['company' => $order->company_uuid]);
                    $settings = $this->alertSettings();

                    if ($this->processLateDeparture($order, $settings, $dryRun)) {
                        $triggered++;
                    }

                    if ($this->processRouteDeviation($order, $settings, $dryRun)) {
                        $triggered++;
                    }

                    if ($this->processProlongedStoppage($order, $settings, $dryRun)) {
                        $triggered++;
                    }
                }
            });

            $this->info(($dryRun ? '[Dry Run] ' : '') . "Operational alerts triggered: {$triggered}");

            return self::SUCCESS;
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    protected function ordersQuery(Carbon $cutoff)
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->whereNotIn('status', ['completed', 'canceled'])
            ->whereNull('deleted_at')
            ->whereNotNull('company_uuid')
            ->where('created_at', '>=', $cutoff);
    }

    protected function processLateDeparture(Order $order, array $settings, bool $dryRun): bool
    {
        if (!data_get($settings, 'late_departures.enabled')) {
            return false;
        }

        if (!$order->scheduled_at || $order->started_at || $order->started) {
            return false;
        }

        $graceMinutes = (int) data_get($settings, 'late_departures.grace_period_minutes', 15);
        if (Carbon::parse($order->scheduled_at)->addMinutes($graceMinutes)->isFuture()) {
            return false;
        }

        return $this->notifyOnce($order, 'late_departure', LateDeparture::class, [
            'scheduled_at'         => $order->scheduled_at,
            'grace_period_minutes' => $graceMinutes,
        ], $dryRun);
    }

    protected function processRouteDeviation(Order $order, array $settings, bool $dryRun): bool
    {
        if (!data_get($settings, 'route_deviations.enabled')) {
            return false;
        }

        $latestPosition = $this->latestPositionForOrder($order);
        if (!$latestPosition?->coordinates) {
            return false;
        }

        $routePoints = $this->routePoints($order);
        if (count($routePoints) < 2) {
            return false;
        }

        $thresholdMeters = (int) data_get($settings, 'route_deviations.distance_threshold_meters', 500);
        $distanceMeters  = $this->minimumDistanceToRoute($latestPosition->coordinates, $routePoints);

        if ($distanceMeters <= $thresholdMeters) {
            return false;
        }

        return $this->notifyOnce($order, 'route_deviation', RouteDeviation::class, [
            'distance_meters'           => round($distanceMeters, 2),
            'distance_threshold_meters' => $thresholdMeters,
            'position_id'               => $latestPosition->uuid,
        ], $dryRun);
    }

    protected function processProlongedStoppage(Order $order, array $settings, bool $dryRun): bool
    {
        if (!data_get($settings, 'prolonged_stoppages.enabled')) {
            return false;
        }

        if (!$order->started_at && !$order->started) {
            return false;
        }

        $latestPosition = $this->latestPositionForOrder($order);
        if (!$latestPosition || ((float) $latestPosition->speed) > 1) {
            return false;
        }

        $thresholdMinutes = (int) data_get($settings, 'prolonged_stoppages.duration_threshold_minutes', 30);
        if (Carbon::parse($latestPosition->created_at)->addMinutes($thresholdMinutes)->isFuture()) {
            return false;
        }

        return $this->notifyOnce($order, 'prolonged_stoppage', ProlongedStoppage::class, [
            'duration_threshold_minutes' => $thresholdMinutes,
            'position_id'                => $latestPosition->uuid,
            'stopped_since'              => $latestPosition->created_at,
        ], $dryRun);
    }

    protected function notifyOnce(Order $order, string $key, string $notificationClass, array $context, bool $dryRun): bool
    {
        $metaKey = "operational_alerts.{$key}.notified_at";
        if ($order->getMeta($metaKey)) {
            return false;
        }

        NotificationRegistry::notify($notificationClass, $order, $context);

        if (!$dryRun) {
            $order->setMeta("operational_alerts.{$key}", [
                'notified_at' => Carbon::now()->toDateTimeString(),
                'context'     => $context,
            ]);
            $order->saveQuietly();
        }

        return true;
    }

    protected function latestPositionForOrder(Order $order): ?Position
    {
        return Position::where('company_uuid', $order->company_uuid)
            ->where('order_uuid', $order->uuid)
            ->latest()
            ->first();
    }

    protected function routePoints(Order $order): array
    {
        $details = data_get($order, 'route.details', []);

        return $this->collectPoints($details);
    }

    protected function collectPoints(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($point = $this->pointFromPair($value)) {
            return [$point];
        }

        $points = [];
        foreach ($value as $item) {
            foreach ($this->collectPoints($item) as $point) {
                $points[] = $point;
            }
        }

        return $points;
    }

    protected function pointFromPair(array $value): ?Point
    {
        if (count($value) !== 2 || !is_numeric($value[0]) || !is_numeric($value[1])) {
            return null;
        }

        $first  = (float) $value[0];
        $second = (float) $value[1];

        if (abs($first) <= 90 && abs($second) <= 180) {
            return new Point($first, $second);
        }

        if (abs($first) <= 180 && abs($second) <= 90) {
            return new Point($second, $first);
        }

        return null;
    }

    protected function minimumDistanceToRoute(Point $position, array $routePoints): float
    {
        return collect($routePoints)
            ->map(fn (Point $point) => Utils::vincentyGreatCircleDistance($position, $point))
            ->min() ?? 0;
    }

    protected function alertSettings(): array
    {
        $settings = Setting::lookupCompany('tracking', []);
        $alerts   = data_get($settings, 'alerts', []);

        return [
            'late_departures' => [
                'enabled'              => (bool) data_get($alerts, 'late_departures.enabled', true),
                'grace_period_minutes' => (int) data_get($alerts, 'late_departures.grace_period_minutes', 15),
            ],
            'route_deviations' => [
                'enabled'                   => (bool) data_get($alerts, 'route_deviations.enabled', true),
                'distance_threshold_meters' => (int) data_get($alerts, 'route_deviations.distance_threshold_meters', 500),
            ],
            'prolonged_stoppages' => [
                'enabled'                    => (bool) data_get($alerts, 'prolonged_stoppages.enabled', true),
                'duration_threshold_minutes' => (int) data_get($alerts, 'prolonged_stoppages.duration_threshold_minutes', 30),
            ],
        ];
    }
}
