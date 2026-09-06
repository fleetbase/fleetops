import { module, test } from 'qunit';
import registerHelper from '@fleetbase/fleetops-engine/utils/register-helper';

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

module('Unit | Utility | register-helper', function () {
    test('it registers the helper under its dasherized name', function (assert) {
        const owner = fakeOwner();
        const helper = () => 'formatted';

        registerHelper(owner, helper, 'formatDuration');
        registerHelper(owner, helper, 'is-active', {});

        assert.strictEqual(owner.registrations.get('helper:format-duration'), helper);
        assert.strictEqual(owner.registrations.get('helper:is-active'), helper);
    });

    test('an existing registration is never overwritten', function (assert) {
        const owner = fakeOwner(['helper:format-duration']);

        registerHelper(owner, () => null, 'formatDuration');

        assert.strictEqual(owner.registrations.get('helper:format-duration'), 'existing');
    });
});
