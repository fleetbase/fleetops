import { module, test } from 'qunit';
import LeafletTrackingMarkerComponent from 'dummy/components/leaflet-tracking-marker';

module('Unit | Component | leaflet-tracking-marker', function (hooks) {
    hooks.beforeEach(function () {
        this.L = window.leaflet || window.L;
        this.originalEdit = this.L.Edit;
        this.originalTrackingMarker = this.L.TrackingMarker;
    });

    hooks.afterEach(function () {
        this.L.Edit = this.originalEdit;
        this.L.TrackingMarker = this.originalTrackingMarker;
    });

    test('it restores the Leaflet Draw edit namespace before marker construction', function (assert) {
        assert.expect(2);
        const L = this.L;

        L.Edit = undefined;
        L.TrackingMarker = function TrackingMarker() {
            assert.deepEqual(L.Edit, {});
        };

        const component = Object.create(LeafletTrackingMarkerComponent.prototype);
        component.args = { location: [1, 2] };
        component.requiredOptions = [[1, 2]];
        component.options = {};

        assert.ok(component.createLayer());
    });
});
