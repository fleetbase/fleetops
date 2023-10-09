import { helper } from '@ember/component/helper';
import extractCoordinates from '../utils/extract-coordinates';

export default helper(function pointToCoordinates([point, format = 'array']) {
    const [latitude, longitude] = extractCoordinates(point?.coordinates);

    if (format === 'array') {
        return [latitude, longitude];
    }

    if (format === 'latitudelongitude') {
        return { latitude, longitude };
    }

    if (format === 'latlng') {
        return {
            lat: latitude,
            lng: longitude,
        };
    }

    if (format === 'xy') {
        return {
            x: latitude,
            y: longitude,
        };
    }
});
