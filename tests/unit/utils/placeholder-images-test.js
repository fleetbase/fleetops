import { module, test } from 'qunit';
import getPlaceholderImage, { PLACEHOLDER_IMAGES, isDefaultImage, resolveResourceImage } from '@fleetbase/fleetops-engine/utils/placeholder-images';
import getTrailerPlaceholderImage, { TRAILER_PLACEHOLDER_IMAGE } from '@fleetbase/fleetops-engine/utils/trailer-placeholder-image';

module('Unit | Utility | placeholder-images', function () {
    test('it ships one inline SVG silhouette per resource type', function (assert) {
        for (const type of ['trailer', 'vehicle', 'driver', 'fleet', 'vendor', 'contact', 'customer']) {
            assert.ok(PLACEHOLDER_IMAGES[type].startsWith('data:image/svg+xml;base64,'), `${type} placeholder is an inline data URI`);
            assert.ok(atob(PLACEHOLDER_IMAGES[type].split(',')[1]).includes('<svg'), `${type} payload decodes to SVG markup`);
            assert.strictEqual(getPlaceholderImage(type), PLACEHOLDER_IMAGES[type]);
        }

        assert.strictEqual(getPlaceholderImage('unknown'), PLACEHOLDER_IMAGES.contact, 'unknown types fall back to the generic silhouette');
        assert.strictEqual(getTrailerPlaceholderImage(), TRAILER_PLACEHOLDER_IMAGE, 'the trailer helper keeps returning the trailer silhouette');
        assert.strictEqual(TRAILER_PLACEHOLDER_IMAGE, PLACEHOLDER_IMAGES.trailer);
    });

    test('it treats blank photos and the legacy hosted defaults as "no photo"', function (assert) {
        assert.true(isDefaultImage(undefined));
        assert.true(isDefaultImage(''));
        assert.true(isDefaultImage('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png'));
        assert.true(isDefaultImage('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/vehicle-placeholder.png'));
        assert.true(isDefaultImage('https://flb-assets.s3.ap-southeast-1.amazonaws.com/static/image-file-icon.png'));
        assert.false(isDefaultImage('https://cdn.example/uploads/truck-42.jpg'));
    });

    test('it resolves a record image to its own photo or the typed placeholder', function (assert) {
        assert.strictEqual(resolveResourceImage('https://cdn.example/uploads/truck-42.jpg', 'vehicle'), 'https://cdn.example/uploads/truck-42.jpg');
        assert.strictEqual(resolveResourceImage('https://s3.ap-southeast-1.amazonaws.com/flb-assets/static/no-avatar.png', 'driver'), PLACEHOLDER_IMAGES.driver);
        assert.strictEqual(resolveResourceImage(null, 'customer'), PLACEHOLDER_IMAGES.customer);
    });
});
