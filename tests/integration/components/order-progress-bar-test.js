import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order-progress-bar', function (hooks) {
    setupRenderingTest(hooks);

    test('it sizes the progression and truck to the progress and follows updates', async function (assert) {
        this.set('progress', 25);
        this.set('first', true);
        this.set('last', false);

        await render(hbs`<OrderProgressBar @progress={{this.progress}} @firstWaypointCompleted={{this.first}} @lastWaypointCompleted={{this.last}} />`);

        assert.dom('.order-progress-bar').hasClass('has-progress');
        assert.dom('.order-progress-bar-progression').hasAttribute('style', 'width: calc(25% - 2rem);');
        assert.dom('.order-progress-bar-truck-icon').hasAttribute('style', 'padding-left: calc(25% - 2rem);');
        assert.dom('.order-progress-bar-marker-wrapper:first-child').hasClass('completed');
        assert.dom('.order-progress-bar-marker-wrapper:last-child').doesNotHaveClass('completed');

        this.set('progress', 100);
        this.set('last', true);
        assert.dom('.order-progress-bar-progression').hasAttribute('style', 'width: calc(100% - 2rem);');
        assert.dom('.order-progress-bar-marker-wrapper:last-child').hasClass('completed');

        this.set('progress', undefined);
        assert.dom('.order-progress-bar-progression').hasAttribute('style', 'width: calc(0% - 2rem);', 'an undefined update resets to zero');
        assert.dom('.order-progress-bar').doesNotHaveClass('has-progress');
    });

    test('it starts at zero without a progress argument', async function (assert) {
        await render(hbs`<OrderProgressBar />`);

        assert.dom('.order-progress-bar').doesNotHaveClass('has-progress');
        assert.dom('.order-progress-bar-progression').hasAttribute('style', 'width: calc(0% - 2rem);');
        assert.dom('svg[data-icon="truck"]').exists();
    });
});
