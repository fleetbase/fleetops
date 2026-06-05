import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';

const PARTIAL_STATUS = {
    is_completed: false,
    progress: { completed: 1, total: 4, percent: 25 },
    next_step: 'create_order',
    steps: [
        { key: 'add_driver', title: 'Add a driver', description: 'Create your first driver', estimate: '2 min', completed: true, icon: 'id-card', route: 'drivers.new' },
        { key: 'create_order', title: 'Create an order', description: 'Create the first order', estimate: '3 min', completed: false, icon: 'box', route: 'orders.new' },
    ],
    recommendations: [
        {
            key: 'route_optimization',
            title: 'Route Optimization',
            description: 'Plan better routes.',
            icon: 'route',
            accent: 'blue',
            docs_url: 'https://www.fleetbase.io/docs/fleet-ops/orchestrator',
        },
        {
            key: 'live_fleet',
            title: 'Live Fleet Map',
            description: 'Track work in real time.',
            icon: 'map-location-dot',
            accent: 'green',
            docs_url: 'https://www.fleetbase.io/docs/fleet-ops/live-map',
        },
    ],
};

class StubGettingStartedService extends Service {
    @tracked data = PARTIAL_STATUS;
    error = null;

    get isCompleted() {
        return this.data.is_completed;
    }

    get steps() {
        return this.data.steps;
    }

    get recommendations() {
        return this.data.recommendations;
    }

    get progress() {
        return this.data.progress;
    }

    get nextStepKey() {
        return this.data.next_step;
    }

    get nextStep() {
        return this.steps.find((step) => step.key === this.nextStepKey);
    }

    @task *load() {
        return yield this.data;
    }
}

class StubRouterService extends Service {
    lastRoute = null;

    transitionTo(route) {
        this.lastRoute = route;
    }
}

class StubDocsPanelService extends Service {
    lastUrl = null;

    open(url) {
        this.lastUrl = url;
    }
}

module('Integration | Component | home/getting-started-guidance', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:getting-started', StubGettingStartedService);
        this.owner.register('service:router', StubRouterService);
        this.owner.register('service:docs-panel', StubDocsPanelService);
    });

    test('it renders guidance above the dashboard while onboarding is incomplete', async function (assert) {
        await render(hbs`<Home::GettingStartedGuidance />`);
        await waitFor('.fleet-ops-home-guidance');

        assert.dom('.fleet-ops-home-guidance').includesText('Get Started with Fleet-Ops');
        assert.dom('.fleet-ops-home-guidance').includesText('1/4');
        assert.dom('.fleet-ops-home-guidance').includesText('Create an order');
        assert.dom('.fleet-ops-home-guidance').includesText('Recommended features for you');
        assert.dom('.fleet-ops-recommended-feature').exists({ count: 2 });

        await click(document.querySelectorAll('.fleet-ops-get-started-step')[1]);
        assert.strictEqual(this.owner.lookup('service:router').lastRoute, 'orders.new');

        await click('.fleet-ops-recommended-feature');
        assert.strictEqual(this.owner.lookup('service:docs-panel').lastUrl, 'https://www.fleetbase.io/docs/fleet-ops/orchestrator');
    });

    test('it hides itself when onboarding is complete', async function (assert) {
        this.owner.lookup('service:getting-started').data = {
            ...PARTIAL_STATUS,
            is_completed: true,
            progress: { completed: 4, total: 4, percent: 100 },
        };

        await render(hbs`<Home::GettingStartedGuidance />`);

        assert.dom('.fleet-ops-home-guidance').doesNotExist();
    });
});
