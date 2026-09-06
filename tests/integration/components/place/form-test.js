import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import { next } from '@ember/runloop';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

const SELECTED = { address: '2 New Street', street1: '2 New Street', city: 'Singapore', location: { type: 'Point', coordinates: [103.9, 1.4] } };
const SELECTED_WITHOUT_LOCATION = { address: '3 Old Street', street1: '3 Old Street' };

/** Offers both autocomplete results as buttons wired to the form's `@onSelect`. */
class AutocompleteInputStub extends Component {
    selected = SELECTED;
    selectedWithoutLocation = SELECTED_WITHOUT_LOCATION;
}
setComponentTemplate(
    hbs`<div data-test-autocomplete ...attributes><button type="button" data-test-pick="with-location" {{on "click" (fn @onSelect this.selected)}}></button><button type="button" data-test-pick="without-location" {{on "click" (fn @onSelect this.selectedWithoutLocation)}}></button></div>`,
    AutocompleteInputStub
);

/** Hands itself to the form through `@onInputReady` (when asked to) and records coordinate updates. */
function registerCoordinatesInput(owner, ready) {
    const updates = [];
    class CoordinatesInputStub extends Component {
        constructor() {
            super(...arguments);
            // The real input reports itself after render; doing it during render would write the
            // form's tracked field inside the computation that just read it.
            if (ready) {
                next(() => this.args.onInputReady(this));
            }
        }

        updateCoordinates(location) {
            updates.push(location);
        }
    }
    setComponentTemplate(hbs`<div data-test-coordinates-input disabled={{@disabled}}></div>`, CoordinatesInputStub);
    owner.register('component:model-coordinates-input', CoordinatesInputStub);
    return updates;
}

module('Integration | Component | place/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        this.owner.register('component:autocomplete-input', AutocompleteInputStub);
        const test = this;
        this.allow = true;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return test.allow;
                }
            }
        );
    });

    test('it renders the address inputs and pushes autocomplete picks into the record and the coordinates input', async function (assert) {
        const updates = registerCoordinatesInput(this.owner, true);
        this.set('resource', makeRecord('place', { name: 'Depot', street1: '1 Harbour Road', phone: '+65' }));

        await render(hbs`<Place::Form @resource={{this.resource}} />`);

        assert.dom('input[placeholder="Name"]').hasValue('Depot');
        assert.dom('[data-test-country-select]').isNotDisabled();
        assert.dom('[data-test-phone-input]').hasValue('+65');
        assert.dom('[data-test-avatar-picker]').exists();
        assert.dom('[data-test-custom-fields]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:place:form"]').exists();

        await click('[data-test-pick="with-location"]');
        assert.strictEqual(this.resource.street1, '2 New Street');
        assert.strictEqual(this.resource.city, 'Singapore');
        assert.deepEqual(updates, [SELECTED.location], 'the coordinates input follows the picked location');

        await click('[data-test-pick="without-location"]');
        assert.strictEqual(this.resource.address, '3 Old Street');
        assert.strictEqual(updates.length, 1, 'a pick without a location leaves the coordinates alone');
    });

    test('a pick before the coordinates input is ready only updates the record', async function (assert) {
        const updates = registerCoordinatesInput(this.owner, false);
        this.set('resource', makeRecord('place'));

        await render(hbs`<Place::Form @resource={{this.resource}} />`);
        await click('[data-test-pick="with-location"]');

        assert.strictEqual(this.resource.street1, '2 New Street');
        assert.deepEqual(updates, []);
    });

    test('inputs are disabled without write access', async function (assert) {
        registerCoordinatesInput(this.owner, false);
        this.allow = false;
        this.set('resource', makeRecord('place', {}, { isNew: false }));

        await render(hbs`<Place::Form @resource={{this.resource}} />`);

        assert.dom('input[placeholder="Name"]').isDisabled();
        assert.dom('[data-test-country-select]').isDisabled();
        assert.dom('[data-test-coordinates-input]').hasAttribute('disabled');
    });
});
