import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from './register-template-only';

/**
 * Stands in the heavy ember-ui inputs the Fleet-Ops forms compose (model selects that query the
 * store, country/date/phone/money/unit pickers, the avatar picker, registry and custom-field
 * yields) with template-only components that expose the same arguments as data attributes and
 * buttons, so a form test exercises the form's own template and actions without the network.
 */
export default function stubFormInputs(owner) {
    registerTemplateOnly(
        owner,
        'model-select',
        hbs`<button type="button" data-test-model-select={{@modelName}} disabled={{@disabled}} {{on "click" (fn (or @onChange (noop)) (hash id="picked_1" name="Picked" display_name="Picked"))}}>{{@placeholder}}</button>`
    );
    registerTemplateOnly(owner, 'country-select', hbs`<select data-test-country-select disabled={{@disabled}}></select>`);
    registerTemplateOnly(owner, 'date-picker', hbs`<input data-test-date-picker={{@placeholder}} disabled={{@disabled}} />`);
    registerTemplateOnly(owner, 'phone-input', hbs`<input data-test-phone-input value={{@value}} ...attributes />`);
    registerTemplateOnly(owner, 'money-input', hbs`<input data-test-money-input disabled={{@disabled}} />`);
    registerTemplateOnly(owner, 'unit-input', hbs`<input data-test-unit-input disabled={{@disabled}} />`);
    registerTemplateOnly(owner, 'avatar-picker', hbs`<div data-test-avatar-picker disabled={{@disabled}}></div>`);
    registerTemplateOnly(owner, 'registry-yield', hbs`<div data-test-registry={{@registry}}></div>`);
    registerTemplateOnly(owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
}

/**
 * A minimal record-like fixture: `cannot-write` resolves the permission from `constructor.modelName`
 * and `isNew`, and the forms mutate through `set`/`setProperties`.
 */
export function makeRecord(modelName, attributes = {}, { isNew = true } = {}) {
    class Record {
        static modelName = modelName;
        isNew = isNew;

        constructor() {
            Object.assign(this, attributes);
        }

        set(key, value) {
            this[key] = value;
            return value;
        }

        setProperties(values) {
            Object.assign(this, values);
            return values;
        }
    }

    return new Record();
}

export class AbilitiesStub {
    static create(props) {
        return Object.assign(new AbilitiesStub(), props);
    }

    allow = true;
    asked = [];

    can(permission) {
        this.asked.push(permission);
        return this.allow;
    }
}
