import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import Resolver from 'ember-resolver';
import Diagnostics from '@fleetbase/fleetops-engine/components/modals/fuel-connection-diagnostics';

module('Unit | fuel integration | diagnostics rendering', function (hooks) {
    setupRenderingTest(hooks, { resolver: Resolver.create({ namespace: { modulePrefix: 'dummy' } }) });
    hooks.beforeEach(function () {
        this.owner.register('service:notifications', class extends Service {});
        this.owner.register('component:modal/default', setComponentTemplate(hbs`{{yield}}`, class extends Component {}));
        for (const name of ['button', 'spinner', 'fa-icon']) this.owner.register(`component:${name}`, setComponentTemplate(hbs`<span></span>`, class extends Component {}));
        const context = this;
        this.Diagnostics = class extends Diagnostics {
            constructor() {
                super(...arguments);
                context.diagnostics = this;
            }
        };
    });
    test('diagnostics waits for confirmation, shows progress and keeps successful results open', async function (assert) {
        let resolveRequest;
        let requests = 0;
        this.owner.register(
            'service:fetch',
            class extends Service {
                post(path, body, options) {
                    requests++;
                    assert.strictEqual(path, 'fuel-provider-connections/connection-123/test-connection');
                    assert.true(options.rawError);
                    return new Promise((resolve) => {
                        resolveRequest = resolve;
                    });
                }
            }
        );
        const options = {
            connection: { id: 'connection-123', name: 'PetroApp', environment: 'sandbox' },
            onTested: () => {
                throw new Error('Refresh failed');
            },
        };
        this.options = options;
        await render(hbs`<this.Diagnostics @options={{this.options}} />`);
        const component = this.diagnostics;
        assert.strictEqual(requests, 0);
        const pending = options.confirm();
        await waitUntil(() => Boolean(resolveRequest));
        assert.strictEqual(component.state, 'testing');
        resolveRequest({ success: true, message: 'Verified', metadata: { status: 200, total_vehicles: 123, token: 'never-display' } });
        await pending;
        assert.strictEqual(component.state, 'success');
        assert.true(options.keepOpen);
        assert.strictEqual(component.modalOptions.acceptButtonText, 'Test Again');
        assert.ok(component.refreshWarning, 'summary failure does not replace a successful test');
        assert.false(component.diagnosticsText.includes('never-display'));
        assert.ok(component.entries.every((entry) => /^\d{4}-\d{2}-\d{2}T.*Z$/.test(entry.time)));
    });

    test('failed connection diagnostics preserve provider error metadata and permit retry', async function (assert) {
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post() {
                    throw { message: 'Secret key is not found', metadata: { status: 401, host: 'provider.test' } };
                }
            }
        );
        this.options = { connection: { id: 'connection-123', environment: 'sandbox' } };
        await render(hbs`<this.Diagnostics @options={{this.options}} />`);
        const component = this.diagnostics;
        await component.runTest.perform();
        assert.strictEqual(component.state, 'failed');
        assert.strictEqual(component.message, 'Secret key is not found');
        assert.true(component.diagnosticsText.includes('HTTP status: 401'));
        assert.false(component.modalOptions.acceptButtonDisabled);
        assert.strictEqual(component.modalOptions.acceptButtonText, 'Test Again');
    });
});
