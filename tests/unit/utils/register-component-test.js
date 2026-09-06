import { module, test } from 'qunit';
import registerComponent from '@fleetbase/fleetops-engine/utils/register-component';

function fakeOwner(existing = []) {
    const registrations = new Map(existing.map((name) => [name, 'existing']));

    return {
        registrations,
        hasRegistration(name) {
            return registrations.has(name);
        },
        register(name, value) {
            registrations.set(name, value);
        },
    };
}

class FleetPanelComponent {}

module('Unit | Utility | register-component', function () {
    test('it derives the registration name from the class name', function (assert) {
        const owner = fakeOwner();

        registerComponent(owner, FleetPanelComponent);

        assert.strictEqual(owner.registrations.get('component:fleet-panel'), FleetPanelComponent);
    });

    test('the as option names the registration explicitly', function (assert) {
        const owner = fakeOwner();

        registerComponent(owner, FleetPanelComponent, { as: 'custom/fleet-panel' });

        assert.strictEqual(owner.registrations.get('component:custom/fleet-panel'), FleetPanelComponent);
        assert.false(owner.hasRegistration('component:fleet-panel'));
    });

    test('an existing registration is never overwritten', function (assert) {
        const owner = fakeOwner(['component:fleet-panel']);

        registerComponent(owner, FleetPanelComponent, null);

        assert.strictEqual(owner.registrations.get('component:fleet-panel'), 'existing');
    });
});
