import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class RegistryServiceStub extends Service {
    profiles = [];

    getRegistry(section, list) {
        return section === 'fleet-ops:order-presentation' && list === 'profiles' ? this.profiles : [];
    }
}

module('Unit | Service | order-presentation', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:universe/registry-service', RegistryServiceStub);
        this.service = this.owner.lookup('service:order-presentation');
        this.registry = this.owner.lookup('service:universe/registry-service');
        this.order = { order_config: { meta: { presentation_profile: 'acme' } } };
    });

    test('a profile hides actions by their built-in ids', function (assert) {
        this.registry.profiles = [{ id: 'acme', hidden: { actions: ['dispatch', 'assign-driver', 'view-metadata'] } }];

        assert.true(this.service.isHidden(this.order, 'actions', 'dispatch'));
        assert.true(this.service.isHidden(this.order, 'actions', 'assign-driver'));
        assert.true(this.service.isHidden(this.order, 'actions', 'view-metadata'));
        assert.false(this.service.isHidden(this.order, 'actions', 'unassign-driver'), 'each id is hidden on its own');
        assert.false(this.service.isHidden(this.order, 'actions', 'delete'));
    });

    test('nothing is hidden without a matching profile', function (assert) {
        this.registry.profiles = [{ id: 'acme', hidden: { fields: ['internal-id'], actions: ['dispatch'] } }];

        assert.true(this.service.isHidden(this.order, 'fields', 'internal-id'));
        assert.false(this.service.isHidden({ order_config: { meta: {} } }, 'actions', 'dispatch'));
        assert.false(this.service.isHidden({ order_config: { meta: { presentation_profile: 'other' } } }, 'actions', 'dispatch'));
    });

    test('a profile that is not enabled does not apply', function (assert) {
        this.registry.profiles = [{ id: 'acme', isEnabled: () => false, hidden: { actions: ['dispatch'] } }];

        assert.strictEqual(this.service.profileFor(this.order), null);
        assert.false(this.service.isHidden(this.order, 'actions', 'dispatch'));
    });
});
