<?php

use Fleetbase\FleetOps\Models\Order;

function fleetopsOrderPayloadResolutionProbe(bool $hasExistingUuid)
{
    return new class($hasExistingUuid) extends Order {
        public function __construct(private bool $hasExistingUuid = false)
        {
            parent::__construct();
        }

        public function shouldResolveForTest(array $attributes, string $role): bool
        {
            return $this->shouldResolvePayloadPlace($attributes, $role);
        }

        protected function hasExistingPayloadPlaceUuid(?array $attributes, string $role): bool
        {
            return $this->hasExistingUuid;
        }
    };
}

test('order payload creation prefers existing place uuid over embedded place object', function () {
    $order = fleetopsOrderPayloadResolutionProbe(true);

    expect($order->shouldResolveForTest([
        'pickup_uuid' => '628892cc-271c-89fd-cdaa-39d02b40bd13',
        'pickup'      => [
            'uuid'     => '628892cc-271c-89fd-cdaa-39d02b40bd13',
            'location' => [
                'bbox'        => [103.851, 1.2816, 103.851, 1.2816],
                'type'        => 'Point',
                'coordinates' => [103.851, 1.2816],
            ],
        ],
    ], 'pickup'))->toBeFalse();
});

test('order payload creation resolves embedded place when no valid uuid exists', function () {
    $order = fleetopsOrderPayloadResolutionProbe(false);

    expect($order->shouldResolveForTest([
        'pickup' => [
            'location' => [
                'bbox'        => [103.851, 1.2816, 103.851, 1.2816],
                'type'        => 'Point',
                'coordinates' => [103.851, 1.2816],
            ],
        ],
    ], 'pickup'))->toBeTrue();
});
