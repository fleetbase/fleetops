'use strict';

module.exports = {
    extends: 'recommended',
    rules: {
        'no-invalid-interactive': 'off',
        'no-yield-only': 'off',
        'no-pointer-down-event-binding': 'off',
        'table-groups': 'off',
        'link-href-attributes': 'off',
        'require-input-label': 'off',
        'no-array-prototype-extensions': 'off',
        // `leaflet-tile-url` is a zero-argument helper resolving the configured tile provider
        'no-implicit-this': { allow: ['leaflet-tile-url'] },
    },
};
