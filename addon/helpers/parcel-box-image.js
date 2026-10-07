import { helper } from '@ember/component/helper';

// Each URL is written out in full so a production build's fingerprinting
// (broccoli-asset-rev) rewrites it to the hashed file name. An interpolated
// path such as `boxes/${size}.png` is never rewritten, so it misses the
// fingerprinted file and the server answers with the console's index.html.
const PARCEL_BOX_IMAGES = {
    small: '/engines-dist/images/boxes/small.png',
    medium: '/engines-dist/images/boxes/medium.png',
    large: '/engines-dist/images/boxes/large.png',
    'x-large': '/engines-dist/images/boxes/x-large.png',
};

export default helper(function parcelBoxImage([size]) {
    return PARCEL_BOX_IMAGES[size] ?? PARCEL_BOX_IMAGES.medium;
});
