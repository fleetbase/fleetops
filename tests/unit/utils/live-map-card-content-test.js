import { module, test } from 'qunit';
import { buildVehicleLiveMapContent, resolveVehicleTrailers, resolveVehicleDevices } from '@fleetbase/fleetops-engine/utils/live-map-card-content';

module('Unit | Utility | live-map-card-content', function () {
    test('vehicle popover lists the trailers and devices currently linked to the vehicle', function (assert) {
        const html = buildVehicleLiveMapContent({
            displayName: 'CEN-01',
            status: 'available',
            online: true,
            trailers: [
                { display_name: 'Reefer 12', online: false },
                { name: 'Flatbed <3>', online: true },
            ],
            devices: [{ name: 'Tracker 600', online: true }],
        });

        assert.ok(html.includes('Trailers'), 'renders a trailers cell');
        assert.ok(html.includes('Devices'), 'renders a devices cell');
        assert.ok(html.includes('Reefer 12'), 'lists each linked trailer by display name');
        assert.ok(html.includes('Flatbed &lt;3&gt;'), 'escapes trailer names before injecting them as HTML');
        assert.notOk(html.includes('Flatbed <3>'), 'raw markup from a trailer name never reaches the popover');
        assert.ok(html.includes('Tracker 600'), 'lists each linked device');
    });

    test('vehicle popover shows a dash when nothing is linked and accepts array-like relationships', function (assert) {
        const html = buildVehicleLiveMapContent({ displayName: 'EAS-02', status: 'available' });
        const trailersCell = html.slice(html.indexOf('Trailers'), html.indexOf('Devices'));

        assert.ok(trailersCell.includes('-'), 'an unattached vehicle shows a dash for trailers');
        assert.deepEqual(resolveVehicleTrailers({}), []);
        assert.deepEqual(
            resolveVehicleTrailers({ trailers: { toArray: () => [{ name: 'Dolly 1' }] } }).map((trailer) => trailer.name),
            ['Dolly 1'],
            'Ember Data many-arrays are unwrapped through toArray'
        );
        assert.deepEqual(resolveVehicleDevices({ devices: [{ name: 'Unit A' }] }).length, 1);
    });
});
