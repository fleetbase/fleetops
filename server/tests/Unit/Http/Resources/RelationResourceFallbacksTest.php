<?php

use Fleetbase\FleetOps\Http\Resources\v1\PurchaseRate;
use Fleetbase\FleetOps\Http\Resources\v1\TrackingStatus;

/**
 * Covers the relation accessors on PurchaseRate and TrackingStatus when the
 * wrapped resource is not an Eloquent model. Both first try to eager load the
 * relation off the underlying model; with nothing model-like to load from, the
 * accessor must fall through to null rather than error.
 *
 * Each accessor previously carried a middle branch guarded by
 * `method_exists($this, 'loadMissing')`, which could never be true: no class in
 * the resource hierarchy (PurchaseRate/TrackingStatus -> FleetbaseResource ->
 * JsonResource) declares `loadMissing`, it only resolves through `__call`, which
 * `method_exists` does not see. That branch has since been removed.
 */
test('relation accessors fall through to null without a loadable model', function () {
    // A resource wrapping nothing at all has no relation to resolve
    expect((new PurchaseRate(null))->serviceQuote())->toBeNull()
        ->and((new TrackingStatus(null))->trackingNumber())->toBeNull();

    // A plain object exposes no loadMissing, so the eager-load path is skipped
    $plainRate            = new stdClass();
    $plainRate->public_id = 'purchase_rate_plain';

    $plainStatus            = new stdClass();
    $plainStatus->public_id = 'tracking_status_plain';

    expect((new PurchaseRate($plainRate))->serviceQuote())->toBeNull()
        ->and((new TrackingStatus($plainStatus))->trackingNumber())->toBeNull();
});
