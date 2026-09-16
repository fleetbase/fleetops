import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, triggerKeyEvent, fillIn } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { isTypingTarget } from '@fleetbase/fleetops-engine/modifiers/radar-keyboard';

module('Integration | Modifier | radar-keyboard', function (hooks) {
    setupRenderingTest(hooks);

    test('it maps keys to handlers and ignores them while typing', async function (assert) {
        const fired = [];
        this.handlers = {
            next: () => fired.push('next'),
            previous: () => fired.push('previous'),
            select: () => fired.push('select'),
            acknowledge: () => fired.push('acknowledge'),
            snooze: () => fired.push('snooze'),
            assign: () => fired.push('assign'),
            open: () => fired.push('open'),
            close: () => fired.push('close'),
        };

        await render(hbs`<div {{radar-keyboard this.handlers}}><input id="field" /></div>`);

        for (const key of ['J', 'K', 'X', 'E', 'S', 'A', 'Enter', 'Escape', 'ArrowDown']) {
            await triggerKeyEvent(document.body, 'keydown', key);
        }
        assert.deepEqual(fired, ['next', 'previous', 'select', 'acknowledge', 'snooze', 'assign', 'open', 'close', 'next']);

        await fillIn('#field', 'typing');
        await triggerKeyEvent('#field', 'keydown', 'J');
        assert.strictEqual(fired.length, 9, 'a key pressed inside a field is left to the field');

        await triggerKeyEvent(document.body, 'keydown', 'J', { ctrlKey: true });
        assert.strictEqual(fired.length, 9, 'modifier combinations are not hijacked');

        await triggerKeyEvent(document.body, 'keydown', 'Q');
        assert.strictEqual(fired.length, 9, 'unmapped keys do nothing');
    });

    test('it can be disabled and stops listening when removed', async function (assert) {
        let count = 0;
        this.handlers = { next: () => count++ };
        this.enabled = false;
        this.shown = true;

        await render(hbs`{{#if this.shown}}<div {{radar-keyboard this.handlers enabled=this.enabled}}></div>{{/if}}`);

        await triggerKeyEvent(document.body, 'keydown', 'J');
        assert.strictEqual(count, 0, 'disabled');

        this.set('enabled', true);
        await triggerKeyEvent(document.body, 'keydown', 'J');
        assert.strictEqual(count, 1);

        this.set('shown', false);
        await triggerKeyEvent(document.body, 'keydown', 'J');
        assert.strictEqual(count, 1, 'the listener is removed with the element');
    });

    test('isTypingTarget recognises fields, editable content and open dropdowns', function (assert) {
        const input = document.createElement('input');
        const div = document.createElement('div');
        const editable = document.createElement('div');
        editable.contentEditable = 'true';
        const dropdown = document.createElement('div');
        dropdown.className = 'ember-basic-dropdown-content';
        const inside = document.createElement('span');
        dropdown.appendChild(inside);

        assert.true(isTypingTarget(input));
        assert.false(isTypingTarget(div));
        assert.true(isTypingTarget(editable) || editable.isContentEditable === false, 'contenteditable counts when the browser reports it');
        assert.true(isTypingTarget(inside));
        assert.false(isTypingTarget(null));
    });
});
