import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/form', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders', async function (assert) {
        // Set any properties with this.set('myProperty', 'value');
        // Handle any actions with this.set('myAction', function(val) { ... });

        await render(hbs`<Order::Form />`);

        assert.dom().hasText('');

        // Template block usage:
        await render(hbs`
      <Order::Form>
        template block text
      </Order::Form>
    `);

        assert.dom().hasText('template block text');
    });

    test('it exposes orchestrator constraints between documents and metadata', async function (assert) {
        this.set('resource', {
            files: [],
            meta: {},
            required_skills: [],
        });

        await render(hbs`
            <Order::Form @resource={{this.resource}} as |Form|>
                <Form.Documents />
                <Form.OrchestratorConstraints />
                <Form.Metadata />
            </Order::Form>
        `);

        const text = this.element.textContent;
        const documentsIndex = text.indexOf('Documents');
        const constraintsIndex = text.indexOf('Orchestrator Constraints');
        const metadataIndex = text.indexOf('Metadata');

        assert.true(documentsIndex > -1, 'documents panel is rendered');
        assert.true(constraintsIndex > -1, 'orchestrator constraints panel is rendered');
        assert.true(metadataIndex > -1, 'metadata panel is rendered');
        assert.true(documentsIndex < constraintsIndex, 'orchestrator constraints render after documents');
        assert.true(constraintsIndex < metadataIndex, 'orchestrator constraints render before metadata');
    });
});
