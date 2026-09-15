'use strict';

/**
 * The dummy app only needs English. Loading every locale here also asks the
 * browser for Intl data it may not ship (Chrome 152 has none for `mn`), and
 * ember-intl throws while hydrating, which takes every rendering test down.
 * The engine still ships all translations to the host console.
 */
module.exports = function () {
    return {
        includeLocales: ['en-us'],
    };
};
