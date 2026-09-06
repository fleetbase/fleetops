import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, waitUntil } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

const FILE_ID = '0f9b6f0e-1234-4abc-9def-1234567890ab';
const URL = 'https://cdn.example.test/avatar.svg';

module('Integration | Component | avatar-picker', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        // ember-ui's FetchSelect loads its options over the network as soon as it is constructed.
        registerTemplateOnly(
            this.owner,
            'fetch-select',
            hbs`<div data-test-fetch-select={{@endpoint}} data-test-selected={{@selected}}>
                <button type="button" data-test-pick="url" {{on "click" (fn @onChange "https://cdn.example.test/avatar.svg")}}></button>
                <button type="button" data-test-pick="uuid" {{on "click" (fn @onChange "0f9b6f0e-1234-4abc-9def-1234567890ab")}}></button>
                <button type="button" data-test-pick="empty" {{on "click" (fn @onChange "")}}></button>
            </div>`
        );
        const calls = (this.calls = []);
        const test = this;
        this.peeked = null;
        this.findFails = false;
        // ember-core's isModel only accepts Ember Data records, so the fixture is a real one whose
        // store lookups are intercepted on the instance.
        const store = this.owner.lookup('service:store');
        store.peekRecord = (type, id) => {
            calls.push(['peek', type, id]);
            return test.peeked;
        };
        store.findRecord = async (type, id) => {
            calls.push(['find', type, id]);
            if (test.findFails) {
                throw new Error('not found');
            }
            return { id, url: '/files/' + id + '.png' };
        };
        this.makeVehicle = (attributes = {}) => store.createRecord('vehicle', { display_name: 'Truck 1', avatar_url: null, avatar_custom_url: null, ...attributes });
        this.selected = [];
        this.set('onSelect', (model, url) => this.selected.push(url));
    });

    test('it derives the endpoint from the model and previews the current avatar', async function (assert) {
        this.set('model', this.makeVehicle({ avatar_url: URL, avatar_value: URL }));

        await render(hbs`<AvatarPicker @model={{this.model}} @defaultAvatar="/default.svg" />`);

        assert.dom('[data-test-fetch-select]').hasAttribute('data-test-fetch-select', 'vehicles/avatars');
        assert.dom('[data-test-fetch-select]').hasAttribute('data-test-selected', URL);
        assert.dom('img').hasAttribute('src', URL);
        assert.dom('img').hasAttribute('alt', 'Truck 1');

        this.set('model', this.makeVehicle({ avatar_url: 'file_1', avatar_custom_url: '/files/custom.png' }));
        await render(hbs`<AvatarPicker @model={{this.model}} @endpoint="custom/avatars" />`);
        assert.dom('[data-test-fetch-select]').hasAttribute('data-test-fetch-select', 'custom/avatars');
        assert.dom('img').hasAttribute('src', '/files/custom.png');
    });

    test('choosing a URL sets it and choosing it again only notifies', async function (assert) {
        this.set('model', this.makeVehicle());

        await render(hbs`<AvatarPicker @model={{this.model}} @onSelect={{this.onSelect}} />`);

        await click('[data-test-pick="url"]');
        assert.strictEqual(this.model.get('avatar_url'), URL);
        assert.strictEqual(this.model.get('avatar_custom_url'), null);
        assert.deepEqual(this.selected, [URL]);

        await click('[data-test-pick="url"]');
        assert.deepEqual(this.selected, [URL, URL], 'the fast path still notifies');
        assert.strictEqual(this.model.get('avatar_url'), URL);
    });

    test('choosing a file uses the peeked record, or loads it once', async function (assert) {
        this.peeked = { id: FILE_ID, url: '/files/peeked.png' };
        this.set('model', this.makeVehicle());

        await render(hbs`<AvatarPicker @model={{this.model}} @onSelect={{this.onSelect}} />`);

        await click('[data-test-pick="uuid"]');
        await waitUntil(() => this.selected.length === 1);
        assert.strictEqual(this.model.get('avatar_url'), FILE_ID);
        assert.strictEqual(this.model.get('avatar_custom_url'), '/files/peeked.png');
        assert.deepEqual(this.calls, [['peek', 'file', FILE_ID]]);

        await click('[data-test-pick="uuid"]');
        await waitUntil(() => this.selected.length === 2);
        assert.strictEqual(this.model.get('avatar_custom_url'), '/files/peeked.png', 'the fast path leaves the model alone');

        // The picker copies @model once in its constructor, so a new model needs a new render.
        this.peeked = null;
        this.set('model', this.makeVehicle());
        await render(hbs`<AvatarPicker @model={{this.model}} @onSelect={{this.onSelect}} />`);
        await click('[data-test-pick="uuid"]');
        await waitUntil(() => this.selected.length === 3);
        assert.deepEqual(this.calls.at(-1), ['find', 'file', FILE_ID]);
        assert.strictEqual(this.model.get('avatar_custom_url'), '/files/' + FILE_ID + '.png');
        assert.deepEqual(this.selected, ['/files/peeked.png', '/files/peeked.png', '/files/' + FILE_ID + '.png']);
    });

    test('a file that cannot be loaded leaves the model untouched', async function (assert) {
        this.findFails = true;
        this.set('model', this.makeVehicle({ avatar_url: URL }));

        await render(hbs`<AvatarPicker @model={{this.model}} @onSelect={{this.onSelect}} />`);

        await click('[data-test-pick="uuid"]');
        await waitUntil(() => this.calls.length === 2);
        assert.deepEqual(this.calls, [
            ['peek', 'file', FILE_ID],
            ['find', 'file', FILE_ID],
        ]);
        assert.strictEqual(this.model.get('avatar_url'), URL);
        assert.deepEqual(this.selected, []);
    });

    test('clearing resets both urls and only notifies when something changed', async function (assert) {
        this.set('model', this.makeVehicle({ avatar_url: URL, avatar_custom_url: '/files/custom.png' }));

        await render(hbs`<AvatarPicker @model={{this.model}} @onSelect={{this.onSelect}} />`);

        await click('[data-test-pick="empty"]');
        assert.strictEqual(this.model.get('avatar_url'), null);
        assert.strictEqual(this.model.get('avatar_custom_url'), null);
        assert.deepEqual(this.selected, [null]);

        await click('[data-test-pick="empty"]');
        assert.deepEqual(this.selected, [null], 'clearing an already clear model is silent');
    });

    test('it works without an onSelect callback', async function (assert) {
        this.peeked = { id: FILE_ID, url: '/files/peeked.png' };
        this.set('model', this.makeVehicle({ avatar_url: URL }));

        await render(hbs`<AvatarPicker @model={{this.model}} />`);

        await click('[data-test-pick="empty"]');
        assert.strictEqual(this.model.get('avatar_url'), null);
        await click('[data-test-pick="url"]');
        await click('[data-test-pick="url"]');
        assert.strictEqual(this.model.get('avatar_url'), URL);
        await click('[data-test-pick="uuid"]');
        await waitUntil(() => this.model.get('avatar_url') === FILE_ID);
        await click('[data-test-pick="uuid"]');
        await waitUntil(() => this.calls.length === 2);
        assert.strictEqual(this.model.get('avatar_custom_url'), '/files/peeked.png');
    });
});
