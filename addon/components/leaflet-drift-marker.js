import MarkerLayer from 'ember-leaflet/components/marker-layer';

export default class LeafletDriftMarkerComponent extends MarkerLayer {
    leafletOptions = [
        ...this.leafletOptions,

        /**
         * Required, duration im miliseconds marker will take to destination point.
         *
         * @argument duration
         * @type {Number}
         */
        'duration',

        /**
         * Makes map view follow marker.
         *
         * @argument keepAtCenter
         * @type {Boolean}
         */
        'keepAtCenter',

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

        /**
         * The Public ID of the driver.
         *
         * @argument publicId
         * @type {String}
         */
        'publicId',
    ];

    createLayer() {
        const { DriftMarker } = window;

        return new DriftMarker(...this.requiredOptions, this.options);
    }
}
