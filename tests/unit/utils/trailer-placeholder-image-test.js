import { module, test } from 'qunit';
import getTrailerPlaceholderImage, { TRAILER_PLACEHOLDER_IMAGE } from '@fleetbase/fleetops-engine/utils/trailer-placeholder-image';

module('Unit | Utility | trailer-placeholder-image', function () {
    test('it ships an inline SVG placeholder distinct from the vehicle image', function (assert) {
        assert.ok(TRAILER_PLACEHOLDER_IMAGE.startsWith('data:image/svg+xml;base64,'), 'placeholder is an inline data URI');
        assert.ok(atob(TRAILER_PLACEHOLDER_IMAGE.split(',')[1]).includes('<svg'), 'payload decodes to SVG markup');
        assert.strictEqual(getTrailerPlaceholderImage(), TRAILER_PLACEHOLDER_IMAGE, 'falls back to the inline image when no console override is configured');
    });
});
