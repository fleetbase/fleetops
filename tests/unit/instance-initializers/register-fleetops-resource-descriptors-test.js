import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { initialize } from '@fleetbase/fleetops-engine/instance-initializers/register-fleetops-resource-descriptors';

module('Unit | Instance Initializer | register-fleetops-resource-descriptors', function (hooks) {
    setupTest(hooks);

    test('it registers every FleetOps descriptor through the resource-registry service', function (assert) {
        const registered = [];
        const engine = {
            lookup(name) {
                if (name === 'service:resource-registry') {
                    return {
                        registerDescriptors(list) {
                            registered.push(...list);
                        },
                    };
                }

                return null;
            },
        };

        initialize(engine);

        assert.strictEqual(registered.length, 53, '34 first-class resources, 16 sub-records and 3 polymorphic bases');
        assert.ok(registered.every((descriptor) => typeof descriptor.key === 'string'));
        assert.ok(registered.some((descriptor) => descriptor.key === 'vehicle' && typeof descriptor.open === 'function'));
    });

    test('it does nothing on a host without the registry service', function (assert) {
        initialize({ lookup: () => null });
        initialize({
            lookup() {
                throw new Error('no such service');
            },
        });

        assert.ok(true, 'nothing thrown');
    });
});
