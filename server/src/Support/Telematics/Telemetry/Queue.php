<?php

namespace Fleetbase\FleetOps\Support\Telematics\Telemetry;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;

class Queue
{
    /** Unlike PendingDispatch, release the uniqueness lease when broker dispatch fails. */
    public static function dispatch(ShouldBeUnique $job): bool
    {
        $lock = new UniqueLock(Cache::store());
        if (!$lock->acquire($job)) {
            return false;
        }
        try {
            app(Dispatcher::class)->dispatch($job);

            return true;
        } catch (\Throwable $e) {
            $lock->release($job);
            throw $e;
        }
    }
}
