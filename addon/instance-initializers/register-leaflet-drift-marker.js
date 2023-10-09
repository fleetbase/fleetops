import LeafletDriftMarkerComponent from '../components/leaflet-drift-marker';

export function initialize(owner) {
    let emberLeafletService = owner.lookup('service:ember-leaflet');

    if (emberLeafletService) {
        // we then invoke the `registerComponent` method
        emberLeafletService.registerComponent('leaflet-drift-marker', {
            as: 'drift-marker',
            component: LeafletDriftMarkerComponent,
        });
    }
}

export default {
    initialize,
};
