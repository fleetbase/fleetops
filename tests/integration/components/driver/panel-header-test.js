import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | driver/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'layout/resource/panel/header-actions', hbs`<div data-test-header-actions>{{yield}}</div>`);
    });

    test('it renders the name, the linked resource and the online badge', async function (assert) {
        this.set('resource', { name: 'Primary', vehicle: { displayName: 'Linked Resource' }, online: true });

        await render(hbs`<Driver::PanelHeader @resource={{this.resource}}>actions block</Driver::PanelHeader>`);

        assert.dom('h1').hasText('Primary');
        assert.dom('a').hasText('Linked Resource');
        assert.dom('.status-badge').hasText('Online');
        assert.dom('[data-test-header-actions]').exists();
    });

    test('without a linked resource it explains the gap and shows offline', async function (assert) {
        this.set('resource', { name: 'Solo', online: false });

        await render(hbs`<Driver::PanelHeader @resource={{this.resource}} />`);

        assert.dom('a').doesNotExist();
        assert.dom('.status-badge').hasText('Offline');
        assert.dom('h1 + div span').exists('the no-assignment note renders');
    });
});
