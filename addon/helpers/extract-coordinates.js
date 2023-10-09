import { helper } from '@ember/component/helper';
import extractCoordinatesUtil from '../utils/extract-coordinates';

export default helper(function extractCoordinates([coordinates = [], format = 'latlng']) {
    return extractCoordinatesUtil(coordinates, format);
});
