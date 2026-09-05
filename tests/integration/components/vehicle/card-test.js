import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | vehicle/card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register(
            'service:vehicle-actions',
            class extends Service {
                transition = { view: (resource) => calls.push(['view', resource]), edit: (resource) => calls.push(['edit', resource]) };
                delete = (resource) => calls.push(['delete', resource]);
            }
        );
        registerTemplateOnly(this.owner, 'registry-yield', hbs`<div data-test-registry={{@registry}}></div>`);
    });

    test('it renders the vehicle identity, image, registries and footer actions', async function (assert) {
        this.set('resource', { name: 'Van 12', plate_number: 'SGX 1234', photo_url: '/van.png', updatedAt: '2026-01-02' });

        await render(hbs`<Vehicle::Card @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('Van 12').includesText('SGX 1234').includesText('Last Modified: 2026-01-02');
        assert.dom('img.card-img-lg').exists();
        for (const registry of ['header:start', 'header:end', 'footer:start', 'footer:end']) {
            assert.dom(`[data-test-registry="fleet-ops:component:vehicle:card:${registry}"]`).exists();
        }

        await click(find('svg[data-icon="eye"]').closest('button'));
        await click(find('svg[data-icon="pencil"]').closest('button'));
        await click(find('svg[data-icon="trash"]').closest('button'));
        assert.deepEqual(
            this.calls.map(([name, resource]) => [name, resource === this.resource]),
            [
                ['view', true],
                ['edit', true],
                ['delete', true],
            ]
        );
    });

    test('it falls back through the identity fields and yields the named blocks', async function (assert) {
        this.set('resource', { yearMakeModel: '2020 Ford Transit', vin: 'VIN-1' });

        await render(hbs`
            <Vehicle::Card @resource={{this.resource}}>
                <:header>header block</:header>
                <:body>body block</:body>
                <:footer>footer block</:footer>
            </Vehicle::Card>
        `);
        assert.dom(this.element).includesText('2020 Ford Transit').includesText('VIN-1').includesText('header block').includesText('body block').includesText('footer block');

        this.set('resource', { public_id: 'vehicle_9' });
        await render(hbs`<Vehicle::Card @resource={{this.resource}} />`);
        assert.dom(this.element).includesText('vehicle_9');
    });
});
