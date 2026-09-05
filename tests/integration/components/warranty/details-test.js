import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function badges() {
    return findAll('.status-badge').map((element) => element.textContent.trim());
}

module('Integration | Component | warranty/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
    });

    test('it renders an active warranty with days remaining', async function (assert) {
        this.set('resource', {
            provider: 'Acme Assurance',
            policy_number: 'POL-42',
            is_active: true,
            vendor_name: 'Acme',
            subject_name: 'Van 12',
            coverage_summary: 'Engine and gearbox',
            start_date: '2026-01-01',
            end_date: '2026-12-31',
            days_remaining: 120,
            terms: 'Standard terms',
            policy: 'policy.pdf',
        });

        await render(hbs`<Warranty::Details @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('Acme Assurance').includesText('POL-42').includesText('Acme').includesText('Van 12').includesText('Engine and gearbox');
        assert.dom(this.element).includesText('Standard terms').includesText('policy.pdf').includesText('120 days');
        assert.deepEqual(badges(), ['Active'], 'far-off expiry shows no warning');
        assert.dom('[data-test-custom-fields]').exists();
    });

    test('it warns when the warranty expires within thirty days', async function (assert) {
        this.set('resource', { is_active: true, days_remaining: 10 });

        await render(hbs`<Warranty::Details @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('10 days');
        assert.deepEqual(badges(), ['Active', 'Expiring Soon']);
    });

    test('an expired warranty is badged twice and an inactive one falls back', async function (assert) {
        this.set('resource', { is_expired: true, days_remaining: 0 });
        await render(hbs`<Warranty::Details @resource={{this.resource}} />`);
        assert.deepEqual(badges(), ['Expired', 'Expired'], 'status and days remaining both report the expiry');

        this.set('resource', {});
        await render(hbs`<Warranty::Details @resource={{this.resource}} />`);
        assert.deepEqual(badges(), ['Inactive']);
        assert.dom(this.element).includesText('N/A');
        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 7, 'every text field is dashed');
    });
});
