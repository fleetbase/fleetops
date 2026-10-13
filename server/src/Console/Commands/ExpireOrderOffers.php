<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Events\OrderOfferChanged;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Notifications\DriverNotAccepted;
use Fleetbase\Support\NotificationRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Expire driver offers that were not answered in time.
 *
 * An order assigned to a driver carries an offer (`meta.offer`) that expires after
 * the company's `fleetops.offer_timeout_minutes`. Each offer past its `expires_at`
 * is marked expired, raises the `driver_not_accepted` operational alert (once per
 * order, like the alerts of fleetops:process-operational-alerts) and broadcasts
 * `order.offer_expired` on the order and company channels. The driver stays
 * assigned: dispatch decides whether to reassign.
 */
class ExpireOrderOffers extends Command
{
    public const ALERT_KEY = 'driver_not_accepted';

    protected $signature = 'fleetops:expire-offers
        {--days=2 : Only include orders updated in the last N days}
        {--chunk=250 : How many orders to process per chunk}
        {--no-lock : Skip process locking}
        {--dry : Dry run without updating orders}';

    protected $description = 'Expire driver order offers that were not accepted in time and alert dispatch.';

    public function handle(): int
    {
        $days     = max(1, (int) $this->option('days'));
        $perChunk = max(50, (int) $this->option('chunk'));
        $dryRun   = (bool) $this->option('dry');
        $lock     = null;

        if (!$this->option('no-lock')) {
            $lock = Cache::lock('fleetops:expire-offers', 300);
            if (!$lock->get()) {
                $this->warn('Another offer expiry run appears to be in progress.');

                return self::SUCCESS;
            }
        }

        try {
            $now     = Carbon::now();
            $expired = 0;

            $this->ordersQuery($now->copy()->subDays($days))->orderBy('id')->chunkById($perChunk, function ($orders) use ($now, $dryRun, &$expired) {
                foreach ($orders as $order) {
                    if ($this->expire($order, $now, $dryRun)) {
                        $expired++;
                    }
                }
            });

            $this->info(($dryRun ? '[Dry Run] ' : '') . "Offers expired: {$expired}");

            return self::SUCCESS;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Orders with an open offer that are not finished or under way.
     */
    protected function ordersQuery(Carbon $cutoff)
    {
        return Order::query()
            ->withoutGlobalScopes()
            ->where('meta->offer->status', Order::OFFER_OFFERED)
            ->whereNotIn('status', ['completed', 'canceled', 'expired'])
            ->where(fn ($query) => $query->whereNull('started')->orWhere('started', false))
            ->whereNull('deleted_at')
            ->whereNotNull('company_uuid')
            ->where('updated_at', '>=', $cutoff);
    }

    /**
     * Expire the order's offer when it has run out. Returns whether it was expired.
     */
    public function expire(Order $order, Carbon $now, bool $dryRun = false): bool
    {
        $offer     = $order->getOffer();
        $expiresAt = data_get($offer, 'expires_at');

        if (data_get($offer, 'status') !== Order::OFFER_OFFERED || !$expiresAt || Carbon::parse($expiresAt)->isAfter($now)) {
            return false;
        }

        // An offer left behind on an order that no longer has a driver is not chased
        if (!$order->driver_assigned_uuid) {
            if (!$dryRun) {
                $order->setMeta('offer', null);
                $order->saveQuietly();
            }

            return false;
        }

        if ($dryRun) {
            return true;
        }

        session(['company' => $order->company_uuid]);

        $order->expireOffer();
        $this->raiseAlert($order, (array) $offer);
        $this->broadcast($order);

        return true;
    }

    protected function raiseAlert(Order $order, array $offer): void
    {
        $metaKey = 'operational_alerts.' . self::ALERT_KEY;
        $context = [
            'driver'     => $offer['driver'] ?? null,
            'offered_at' => $offer['offered_at'] ?? null,
            'expires_at' => $offer['expires_at'] ?? null,
        ];

        // once per offer: a later offer to another driver may alert again
        if (data_get($order->getMeta($metaKey), 'context.offered_at') === $context['offered_at']) {
            return;
        }

        try {
            NotificationRegistry::notify(DriverNotAccepted::class, $order, $context);
        } catch (\Throwable $exception) {
            Log::warning('Unable to raise driver_not_accepted alert', ['order' => $order->public_id, 'error' => $exception->getMessage()]);
        }

        $order->setMeta($metaKey, [
            'notified_at' => Carbon::now()->toDateTimeString(),
            'context'     => $context,
        ]);
        $order->saveQuietly();
        $order->invalidateApiCache();
    }

    protected function broadcast(Order $order): void
    {
        try {
            event(new OrderOfferChanged($order, Order::OFFER_EXPIRED));
        } catch (\Throwable $exception) {
            Log::warning('Unable to broadcast order.offer_expired', ['order' => $order->public_id, 'error' => $exception->getMessage()]);
        }
    }
}
