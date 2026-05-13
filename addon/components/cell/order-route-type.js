import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { buildRouteTypeSummary } from '../../utils/order-route-summary';

export default class CellOrderRouteTypeComponent extends Component {
    @service intl;
    @tracked isLoadingPayload = false;
    @tracked loadPayloadError = null;
    _payloadLoadPromise = null;

    get order() {
        return this.args.row;
    }

    get payload() {
        return this.order?.payload;
    }

    get intermediateStopCount() {
        const loadedWaypointCount = this.payload?.waypoints?.length;
        const indexedWaypointCount = Number(this.payload?.waypoints_count ?? 0);

        if (typeof loadedWaypointCount === 'number') {
            return Math.max(loadedWaypointCount, indexedWaypointCount);
        }

        return indexedWaypointCount;
    }

    get baseSummary() {
        return buildRouteTypeSummary({
            hasIntermediateWaypoints: this.order?.hasIntermediateWaypoints ?? this.payload?.hasIntermediateWaypoints,
            intermediateStopCount: this.intermediateStopCount,
            hasPickup: Boolean(this.payload?.pickup_uuid),
            hasDropoff: Boolean(this.payload?.dropoff_uuid),
        });
    }

    get summary() {
        return {
            ...this.baseSummary,
            label: this.intl.t(this.baseSummary.translationKey, this.baseSummary.translationOptions),
        };
    }

    get hasRoutePreview() {
        return this.baseSummary.kind === 'multi_stop' || this.baseSummary.kind === 'pickup_dropoff_stops';
    }

    get hasLoadedWaypointPayload() {
        const waypoints = this.payload?.waypoints;
        const loadedWaypointCount = waypoints?.length;

        if (typeof loadedWaypointCount === 'number') {
            if (loadedWaypointCount > 0) {
                return true;
            }

            return this.intermediateStopCount === 0;
        }

        return (typeof waypoints?.toArray === 'function' || isArray(waypoints)) && this.intermediateStopCount === 0;
    }

    @action async ensurePayloadLoaded() {
        if (!this.hasRoutePreview || this.hasLoadedWaypointPayload || this.isLoadingPayload) {
            return;
        }

        if (typeof this.order?.loadPayload !== 'function') {
            return;
        }

        this.isLoadingPayload = true;
        this.loadPayloadError = null;

        try {
            this._payloadLoadPromise ??= this.order.loadPayload();
            await this._payloadLoadPromise;
        } catch (error) {
            this.loadPayloadError = error?.message ?? this.intl.t('common.unable-to-load-route');
        } finally {
            this.isLoadingPayload = false;
            this._payloadLoadPromise = null;
        }
    }
}
