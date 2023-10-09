import LeafletRotatedMarkerComponent from '../components/leaflet-rotated-marker';

export function initialize(owner) {
    let emberLeafletService = owner.lookup('service:ember-leaflet');

    if (emberLeafletService) {
        // we then invoke the `registerComponent` method
        emberLeafletService.registerComponent('leaflet-rotated-marker', {
            as: 'rotated-marker',
            component: LeafletRotatedMarkerComponent,
        });
    }
}

export default {
    initialize,
};
