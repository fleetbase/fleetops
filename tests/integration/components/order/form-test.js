import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs, { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | order/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        class OrderConfigActionsStub extends Service {
            allOrderConfigs = [];
            loadAll = {
                perform() {},
            };
        }

        this.owner.register('service:order-config-actions', OrderConfigActionsStub);
        this.owner.register('service:abilities', AbilitiesStub);
        stubFormInputs(this.owner);
        // The panels this form composes are covered by their own suites; stand them in so this
        // one asserts the composition — which panels the form yields, and in what order.
        // `hbs` is a build-time tag, so each stand-in needs its own literal template.
        const panels = {
            details: hbs`<div data-test-panel="details"></div>`,
            route: hbs`<div data-test-panel="route"></div>`,
            payload: hbs`<div data-test-panel="payload"></div>`,
            'service-rate': hbs`<div data-test-panel="service-rate"></div>`,
            notes: hbs`<div data-test-panel="notes"></div>`,
            documents: hbs`<div data-test-panel="documents"></div>`,
            'orchestrator-constraints': hbs`<div data-test-panel="orchestrator-constraints"></div>`,
            metadata: hbs`<div data-test-panel="metadata"></div>`,
            'custom-fields': hbs`<div data-test-panel="custom-fields"></div>`,
        };
        for (const [panel, template] of Object.entries(panels)) {
            registerTemplateOnly(this.owner, `order/form/${panel}`, template);
        }

        this.set('resource', makeRecord('order', { files: [], meta: {}, required_skills: [] }));
    });

    test('without a block it renders every panel in order', async function (assert) {
        await render(hbs`<Order::Form @resource={{this.resource}} class="probe" />`);

        assert.dom('.form-wrapper').hasClass('probe');
        assert.deepEqual(
            [...this.element.querySelectorAll('[data-test-panel]')].map((panel) => panel.getAttribute('data-test-panel')),
            ['details', 'route', 'payload', 'service-rate', 'notes', 'documents', 'orchestrator-constraints', 'metadata']
        );
        assert.dom('[data-test-registry="fleet-ops:component:order:form:start"]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:order:form"]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:order:form:end"]').exists();
    });

    test('it exposes orchestrator constraints between documents and metadata', async function (assert) {
        await render(hbs`
            <Order::Form @resource={{this.resource}} as |Form|>
                <Form.Documents />
                <Form.OrchestratorConstraints />
                <Form.Metadata />
            </Order::Form>
        `);

        assert.deepEqual(
            [...this.element.querySelectorAll('[data-test-panel]')].map((panel) => panel.getAttribute('data-test-panel')),
            ['documents', 'orchestrator-constraints', 'metadata'],
            'the block form yields the panels the caller asks for, in the caller order'
        );
    });

    test('the yielded hash exposes every panel and registry the form composes', async function (assert) {
        await render(hbs`
            <Order::Form @resource={{this.resource}} @customFields={{this.customFields}} as |Form|>
                <Form.RegistryYieldStart />
                <Form.Details />
                <Form.CustomFields />
                <Form.Route />
                <Form.RegistryYield />
                <Form.Payload />
                <Form.ServiceRate />
                <Form.Notes />
                <Form.Documents />
                <Form.OrchestratorConstraints />
                <Form.Metadata />
                <Form.RegistryYieldEnd />
            </Order::Form>
        `);

        assert.deepEqual(
            [...this.element.querySelectorAll('[data-test-panel]')].map((panel) => panel.getAttribute('data-test-panel')),
            ['details', 'custom-fields', 'route', 'payload', 'service-rate', 'notes', 'documents', 'orchestrator-constraints', 'metadata']
        );
        assert.deepEqual(
            [...this.element.querySelectorAll('[data-test-registry]')].map((registry) => registry.getAttribute('data-test-registry')),
            ['fleet-ops:component:order:form:start', 'fleet-ops:component:order:form', 'fleet-ops:component:order:form:end']
        );
    });
});
