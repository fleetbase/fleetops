import { setComponentTemplate } from '@ember/component';
import templateOnly from '@ember/component/template-only';

/**
 * Registers a template-only stand-in for a component so a rendering test can isolate the
 * component under test from heavy collaborators (ember-ui's CustomField::Yield loads company and
 * custom fields through the network; CountryName fetches country data).
 *
 * @param {ApplicationInstance} owner
 * @param {string} name component registration name, e.g. 'custom-field/yield'
 * @param {TemplateFactory} template a compiled `hbs` template
 */
export default function registerTemplateOnly(owner, name, template) {
    owner.register(`component:${name}`, setComponentTemplate(template, templateOnly()));
}
