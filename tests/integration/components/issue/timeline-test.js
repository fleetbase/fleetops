import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render, settled, waitFor } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | issue/timeline', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.getFails = false;
        this.holdGet = false;
        this.response = {
            events: [
                {
                    tone: 'success',
                    icon: 'check',
                    label: 'Issue resolved',
                    actor_name: 'Sam Driver',
                    created_at: new Date(2026, 8, 1, 9, 5),
                    description: 'Replaced the tyre.',
                    meta: { file_url: 'https://cdn.example.com/report.pdf', file_name: 'report.pdf' },
                },
                { tone: 'info', icon: 'plus', label: 'Issue reported', created_at: new Date(2026, 7, 31, 8, 0) },
            ],
        };
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path, query, options) {
                    calls.push(['get', path, options?.namespace]);
                    if (test.getFails) {
                        return Promise.reject(new Error('timeline unavailable'));
                    }
                    if (test.holdGet) {
                        return new Promise((resolve) => {
                            test.releaseGet = () => resolve(test.response);
                        });
                    }
                    return Promise.resolve(test.response);
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
    });

    test('it loads and lists the issue timeline', async function (assert) {
        this.holdGet = true;
        this.set('resource', { id: 'issue_1' });

        const rendering = render(hbs`<Issue::Timeline @resource={{this.resource}} class="probe" />`);
        await waitFor('.issue-timeline-loading');
        assert.dom().includesText('Loading timeline...');
        this.releaseGet();
        await rendering;

        assert.dom('.issue-timeline').hasClass('probe');
        assert.deepEqual(this.calls, [['get', 'issues/issue_1/timeline', 'int/v1']]);
        assert.dom('.issue-timeline-event').exists({ count: 2 });

        const [first, second] = findAll('.issue-timeline-event');
        assert.dom(first).hasClass('tone-success');
        assert.dom(first).includesText('Issue resolved');
        assert.dom(first).includesText('Sam Driver');
        assert.dom(first).includesText('01 Sep 2026 09:05');
        assert.dom(first).includesText('Replaced the tyre.');
        assert.dom(first.querySelector('.issue-timeline-link')).hasAttribute('href', 'https://cdn.example.com/report.pdf');
        assert.dom(first.querySelector('.issue-timeline-link')).includesText('report.pdf');
        assert.dom(second).includesText('Someone', 'a missing actor falls back');
        assert.dom(second.querySelector('.issue-timeline-link')).doesNotExist('no link without a file url');
        assert.dom(second).doesNotIncludeText('Replaced');
    });

    test('it reads a timeline key and reloads when the issue changes', async function (assert) {
        this.response = { timeline: [{ label: 'Issue reported', created_at: new Date(2026, 7, 31, 8, 0) }] };
        this.set('resource', { uuid: 'issue_uuid_1' });

        await render(hbs`<Issue::Timeline @resource={{this.resource}} />`);

        assert.deepEqual(this.calls, [['get', 'issues/issue_uuid_1/timeline', 'int/v1']], 'the uuid identifies the issue when there is no id');
        assert.dom('.issue-timeline-event').exists({ count: 1 });

        this.set('resource', { public_id: 'issue_public_1' });
        await settled();
        assert.deepEqual(this.calls.at(-1), ['get', 'issues/issue_public_1/timeline', 'int/v1'], 'changing the issue reloads');
    });

    test('an issue with no identifier makes no request and shows the empty state', async function (assert) {
        this.set('resource', {});

        await render(hbs`<Issue::Timeline @resource={{this.resource}} />`);

        assert.deepEqual(this.calls, []);
        assert.dom().includesText('No issue activity yet.');
        assert.dom('.issue-timeline-event').doesNotExist();
    });

    test('a failed load is reported and leaves the empty state', async function (assert) {
        this.getFails = true;
        this.set('resource', { id: 'issue_1' });

        await render(hbs`<Issue::Timeline @resource={{this.resource}} />`);

        assert.deepEqual(this.calls.at(-1), ['serverError', 'timeline unavailable']);
        assert.dom().includesText('No issue activity yet.');
    });

    test('a response with neither key renders the empty state', async function (assert) {
        this.response = {};
        this.set('resource', { id: 'issue_1' });

        await render(hbs`<Issue::Timeline @resource={{this.resource}} />`);

        assert.dom().includesText('No issue activity yet.');
    });
});
