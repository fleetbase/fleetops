import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import config from 'ember-get-config';

module('Unit | Service | osrm', function (hooks) {
    setupTest(hooks);

    function stubRouting(owner, routing) {
        owner.register(
            'service:current-user',
            class extends Service {
                getOption(key) {
                    return key === 'routing' ? routing : undefined;
                }
            }
        );
    }

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:osrm');
        assert.ok(service);
    });

    test('it calls the organization or admin OSRM server the routing settings resolved', function (assert) {
        stubRouting(this.owner, { osrm_host: 'https://own.osrm.example.com', osrm_host_source: 'organization' });

        assert.strictEqual(this.owner.lookup('service:osrm').routingHost(), 'https://own.osrm.example.com');
    });

    test('it falls back to the console OSRM host when only the environment or the demo server is set', function (assert) {
        stubRouting(this.owner, { osrm_host: 'https://router.project-osrm.org', osrm_host_source: 'default' });

        assert.strictEqual(this.owner.lookup('service:osrm').routingHost(), config.osrm.host);
    });
});
