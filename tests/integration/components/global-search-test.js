import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, triggerKeyEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';

module('Integration | Component | global-search', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.results = [];
        this.queryFails = false;
        this.owner.register(
            'service:global-search',
            class extends Service {
                @tracked visible = true;

                hide() {
                    calls.push(['hide']);
                    this.visible = false;
                }
            }
        );
        this.owner.register(
            'service:order-actions',
            class extends Service {
                transition = { view: (order) => calls.push(['view', order]) };
            }
        );
        this.owner.register(
            'service:store',
            class extends Service {
                async query(model, params) {
                    calls.push(['query', model, params]);
                    if (test.queryFails) {
                        throw new Error('search down');
                    }
                    return test.results;
                }
            }
        );
    });

    test('it renders nothing while hidden', async function (assert) {
        this.owner.lookup('service:global-search').visible = false;
        await render(hbs`<GlobalSearch />`);
        assert.dom('.next-map-search-bar-container').doesNotExist();
    });

    test('typing searches orders after a debounce and renders the route and entities of each result', async function (assert) {
        const order = {
            tracking: 'TRK-1',
            status: 'active',
            createdAt: '2026-01-02',
            has_driver_assigned: true,
            driver_name: 'Ada',
            payload: {
                pickup: { address: 'Pickup Street' },
                waypoints: [{ address: 'Stop 1' }],
                dropoff: { address: 'Dropoff Street' },
                entities: [{ name: 'Parcel', photo_url: '/p.png' }],
            },
        };
        this.results = [order, { tracking: 'TRK-2', has_driver_assigned: false, payload: {} }];

        await render(hbs`<GlobalSearch @itemClass="custom-stop" />`);
        assert.dom('input').isFocused('the search input takes focus');

        await fillIn('input', 'TRK');
        assert.deepEqual(this.calls.at(-1), ['query', 'order', { query: 'TRK' }]);
        assert.strictEqual(findAll('.next-map-search-result').length, 2);
        assert
            .dom(this.element)
            .includesText('TRK-1')
            .includesText('Ada')
            .includesText('Pickup Street')
            .includesText('Stop 1')
            .includesText('Dropoff Street')
            .includesText('Parcel')
            .includesText('Unassigned');
        assert.strictEqual(findAll('.custom-stop').length, 3, 'pickup, waypoint and dropoff stops');
        assert.dom('.next-map-search-results').hasClass('has-results');

        await click('.next-map-search-result');
        assert.deepEqual(this.calls.at(-1), ['view', order]);

        await fillIn('input', '');
        assert.strictEqual(findAll('.next-map-search-result').length, 0, 'an empty query clears the results without searching');
        assert.strictEqual(this.calls.filter(([name]) => name === 'query').length, 1);
    });

    test('a non-array response or a failed search yields no results, and escape hides the search', async function (assert) {
        this.results = { not: 'an array' };
        await render(hbs`<GlobalSearch />`);
        await fillIn('input', 'x');
        assert.strictEqual(findAll('.next-map-search-result').length, 0);

        this.queryFails = true;
        await fillIn('input', 'y');
        assert.strictEqual(findAll('.next-map-search-result').length, 0);

        await triggerKeyEvent('.next-map-search-bar-container', 'keydown', 'Enter');
        assert.dom('.next-map-search-bar-container').exists('other keys do nothing');
        await triggerKeyEvent('.next-map-search-bar-container', 'keydown', 'Escape');
        assert.deepEqual(this.calls.at(-1), ['hide']);
        assert.dom('.next-map-search-bar-container').doesNotExist();
    });
});
