import { module, test } from 'qunit';
import sentenceParts from 'dummy/utils/sentence-parts';

// A stand-in for ember-intl: fills `{name}` placeholders like ICU does.
const intl = (sentences) => ({
    t(key, values = {}) {
        return sentences[key].replace(/\{(\w+)\}/g, (match, name) => (values[name] === undefined ? match : String(values[name])));
    },
});

module('Unit | Utility | sentence-parts', function () {
    test('it splits a sentence into text and its controls, in reading order', function (assert) {
        const strategy = { kind: 'choice', value: 'sequential' };
        const seconds = { kind: 'number', value: 20 };
        const parts = sentenceParts(intl({ s: 'Offer them to {strategy}, giving each driver {seconds} seconds to accept.' }), 's', { strategy, seconds });

        assert.deepEqual(parts, [{ kind: 'text', text: 'Offer them to' }, strategy, { kind: 'text', text: ', giving each driver' }, seconds, { kind: 'text', text: 'seconds to accept.' }]);
    });

    test('it keeps the word order of the translation', function (assert) {
        const choice = { kind: 'choice' };
        const parts = sentenceParts(intl({ s: '{choice} sind die Bestellungen.' }), 's', { choice });

        assert.strictEqual(parts[0], choice);
        assert.deepEqual(parts[1], { kind: 'text', text: 'sind die Bestellungen.' });
    });

    test('it passes other values through to the translation', function (assert) {
        const control = { kind: 'number' };
        const parts = sentenceParts(intl({ s: '{label}: {control}' }), 's', { control }, { label: 'Timeout' });

        assert.deepEqual(parts, [{ kind: 'text', text: 'Timeout:' }, control]);
    });
});
