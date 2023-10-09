import { helper } from '@ember/component/helper';
import { isArray } from '@ember/array';
import extractCoordinates from '../utils/extract-coordinates';

export default helper(function formatPoint([point]) {
    if (isArray(point)) {
        return `(${point[0]}, ${point[1]})`;
    }

    const [latitude, longitude] = extractCoordinates(point.coordinates);

    return `(${latitude}, ${longitude})`;
});
