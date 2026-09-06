import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | driver-onboard-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(
            this.owner,
            'array-input',
            hbs`<button type="button" data-test-array-input {{on "click" (fn @onDataChanged (array "passport" 42 "licence"))}}>{{@addButtonText}}</button>`
        );
        const test = this;
        const calls = (this.calls = []);
        this.loaded = {
            companyId: 'company_1',
            enableDriverOnboardFromApp: true,
            driverOnboardAppMethod: 'invite',
            driverMustProvideOnboardDocuments: true,
            requiredOnboardDocuments: ['id'],
        };
        this.postFails = false;
        this.owner.register(
            'service:fetch',
            class extends Service {
                async get(url) {
                    calls.push(['get', url]);
                    return { driverOnboardSettings: test.loaded };
                }

                async post(url, payload) {
                    calls.push(['post', url, payload]);
                    if (test.postFails) {
                        throw new Error('nope');
                    }
                    return { driverOnboardSettings: { ...payload.driverOnboardSettings, saved: true } };
                }
            }
        );
        this.owner.register(
            'service:current-user',
            class extends Service {
                companyId = 'company_1';
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
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
    });

    test('it loads the company settings and edits every field', async function (assert) {
        await render(hbs`<DriverOnboardSettings />`);

        assert.deepEqual(this.calls[0], ['get', 'fleet-ops/settings/driver-onboard-settings/company_1']);
        assert.strictEqual(findAll('[role="checkbox"]').length, 2, 'both toggles show when onboarding is enabled');
        assert.dom('select').hasValue('invite');
        assert.dom('[data-test-array-input]').exists('documents are required, so the document list shows');

        await fillIn('select', 'button');
        await click('[data-test-array-input]');
        await click(findAll('button').find((element) => element.textContent.includes('Save')));

        const [, , payload] = this.calls.find(([name]) => name === 'post');
        assert.deepEqual(
            payload.driverOnboardSettings,
            { ...this.loaded, driverOnboardAppMethod: 'button', requiredOnboardDocuments: ['passport', 'licence'] },
            'non-string document names are dropped'
        );
        assert.dom('select').hasValue('button', 'settings are kept when onboarding stays enabled');

        await click(findAll('[role="checkbox"]')[1]);
        assert.dom('[data-test-array-input]').doesNotExist('documents are no longer required');

        await click(findAll('[role="checkbox"]')[0]);
        assert.strictEqual(findAll('[role="checkbox"]').length, 1, 'disabling onboarding hides the rest');
        await click(findAll('button').find((element) => element.textContent.includes('Save')));
        assert.true(this.calls.at(-1)[2].driverOnboardSettings.enableDriverOnboardFromApp === false);
        assert.dom(this.element).exists('after saving disabled settings the response replaces the local copy');
    });

    test('a company without settings starts from the defaults, and a null payload is tolerated', async function (assert) {
        this.loaded = {};
        await render(hbs`<DriverOnboardSettings />`);
        assert.strictEqual(findAll('[role="checkbox"]').length, 1, 'defaults start with onboarding disabled');

        this.loaded = null;
        await render(hbs`<DriverOnboardSettings />`);
        assert.strictEqual(findAll('[role="checkbox"]').length, 1);
    });

    test('a failed save is reported', async function (assert) {
        this.postFails = true;
        await render(hbs`<DriverOnboardSettings />`);
        await click(findAll('button').find((element) => element.textContent.includes('Save')));

        assert.deepEqual(this.calls.at(-1), ['serverError', 'nope']);
    });
});
