import MarkerLayer from 'ember-leaflet/components/marker-layer';

export default class LeafletRotatedMarkerComponent extends MarkerLayer {
    leafletOptions = [
        ...this.leafletOptions,

        /**
         * Rotation angle, in degrees, clockwise.
         *
         * @argument rotationAngle
         * @type {Number}
         */
        'rotationAngle',

        /**
         * The rotation center, as a transform-origin CSS rule.
         *
         * @argument rotationOrigin
         * @type {String}
         */
        'rotationOrigin',
    ];
}
