import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    if (!label) return null;
    const value = label.nextElementSibling;
    const copyable = value.querySelector('.click-to-copy--value');
    return (copyable ?? value).textContent.replace(/\s+/g, ' ').trim();
}

module('Integration | Component | contact/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders the contact fields with copyable email and phone', async function (assert) {
        this.set('resource', {
            name: 'Ada Lovelace',
            title: 'Dispatcher',
            email: 'ada@example.com',
            phone: '+15550100',
            internal_id: 'INT-1',
            type: 'customer_contact',
            address: '1 Main St',
        });

        await render(hbs`<Contact::Details @resource={{this.resource}} class="probe" />`);

        assert.dom('.details-wrapper').hasClass('probe');
        assert.dom('.panel-title').hasText('Contact Details');
        assert.strictEqual(field('Name'), 'Ada Lovelace');
        assert.strictEqual(field('Title'), 'Dispatcher');
        assert.strictEqual(field('Email'), 'ada@example.com');
        assert.strictEqual(field('Phone'), '+15550100');
        assert.strictEqual(field('Internal ID'), 'INT-1');
        assert.strictEqual(field('Type'), 'Customer Contact');
        assert.strictEqual(field('Address'), '1 Main St');
        assert.dom('.click-to-copy').exists({ count: 2 });
        assert.dom('[data-test-custom-fields]').exists();
    });

    test('blank fields fall back to a dash', async function (assert) {
        this.set('resource', {});

        await render(hbs`<Contact::Details @resource={{this.resource}} />`);

        assert.strictEqual(field('Name'), '-');
        assert.strictEqual(field('Email'), '-');
        assert.strictEqual(field('Phone'), '-');
        assert.strictEqual(field('Address'), '-');
    });
});
