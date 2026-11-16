const MARK = '\u0000';

/**
 * Turns a translated sentence with `{placeholders}` into parts for `OrderConfig::SentenceField`:
 * text between the placeholders, and each placeholder replaced by its control. Translating with
 * markers instead of splitting the source string keeps word order right in every language.
 *
 * @param {Service} intl the intl service
 * @param {String} key the translation key of the sentence
 * @param {Object} controls a part for each placeholder name, e.g. `{ choice: { kind: 'choice', ... } }`
 * @param {Object} values other ICU values the sentence needs
 * @returns {Array<Object>} parts in reading order
 */
export default function sentenceParts(intl, key, controls = {}, values = {}) {
    const markers = Object.fromEntries(Object.keys(controls).map((name) => [name, `${MARK}${name}${MARK}`]));
    const text = intl.t(key, { ...values, ...markers });
    const parts = [];

    text.split(MARK).forEach((chunk, index) => {
        // Odd chunks are placeholder names; even chunks are text between them.
        if (index % 2 === 1) {
            if (controls[chunk]) {
                parts.push(controls[chunk]);
            }
            return;
        }

        const trimmed = chunk.trim();
        if (trimmed) {
            parts.push({ kind: 'text', text: trimmed });
        }
    });

    return parts;
}
