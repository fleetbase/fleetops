import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs, { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function group(label) {
    return findAll('.input-group').find((el) => el.querySelector('label')?.textContent.trim() === label);
}

async function choose(label, optionLabel) {
    await click(group(label).querySelector('.ember-power-select-trigger'));
    const option = [...document.querySelectorAll('.ember-power-select-option')].find((el) => el.querySelector('.font-semibold')?.textContent.trim() === optionLabel);
    await click(option);
}

module('Integration | Component | issue/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.unregister('component:registry-yield');
        registerTemplateOnly(this.owner, 'registry-probe', hbs`<div data-test-registry={{@registry}} data-test-controller={{@controller.name}} data-test-permission={{@permission}}></div>`);
        registerTemplateOnly(this.owner, 'registry-yield', hbs`{{yield (component "registry-probe" registry=@registry)}}`);
        registerTemplateOnly(this.owner, 'model-tag-input', hbs`<div data-test-tag-input={{@placeholder}} data-test-disabled={{@disabled}}></div>`);
        registerTemplateOnly(this.owner, 'model-coordinates-input', hbs`<div data-test-coordinates-input data-test-disabled={{@disabled}}></div>`);
    });

    test('it edits the issue report, relations, classification and status', async function (assert) {
        this.set('resource', makeRecord('issue', { title: '', report: '', tags: [] }));
        this.set('controller', { name: 'issues' });

        await render(hbs`<Issue::Form @resource={{this.resource}} @controller={{this.controller}} class="probe" />`);

        assert.dom('.form-wrapper').hasClass('probe');
        assert.dom('.panel-title').hasText('Issue Report');
        assert.deepEqual(
            findAll('[data-test-model-select]').map((el) => el.getAttribute('data-test-model-select')),
            ['user', 'user', 'driver', 'vehicle', 'order']
        );
        assert.dom('[data-test-tag-input="Add tags"]').exists();
        assert.dom('[data-test-coordinates-input]').exists();
        assert.dom('[data-test-custom-fields]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:issue:form:details"]').hasAttribute('data-test-controller', 'issues');
        assert.dom('[data-test-registry="fleet-ops:component:issue:form"]').hasAttribute('data-test-controller', 'issues');
        assert.dom('[data-test-registry="fleet-ops:component:issue:form"]').hasAttribute('data-test-permission', 'fleet-ops create issue');

        await fillIn(group('Title').querySelector('input'), 'Flat tyre');
        await fillIn('textarea', 'Rear left tyre flat on arrival.');
        assert.strictEqual(this.resource.title, 'Flat tyre');
        assert.strictEqual(this.resource.report, 'Rear left tyre flat on arrival.');

        await click(group('Reported By').querySelector('[data-test-model-select="user"]'));
        await click(group('Driver').querySelector('[data-test-model-select="driver"]'));
        assert.strictEqual(this.resource.reporter.id, 'picked_1');
        assert.strictEqual(this.resource.driver.id, 'picked_1');

        await choose('Issue Type', 'Driver');
        assert.strictEqual(this.resource.type, 'driver');
        await choose('Issue Category', 'Behavior Concerns');
        assert.strictEqual(this.resource.category, 'behavior_concerns');
        await choose('Issue Priority', 'High');
        assert.strictEqual(this.resource.priority, 'high');
        await choose('Status', 'In Progress');
        assert.strictEqual(this.resource.status, 'in_progress');
    });
});
