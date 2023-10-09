import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { alias } from '@ember/object/computed';
import { calculateInPlacePosition } from 'ember-basic-dropdown/utils/calculate-position';

export default class MapContainerToolbarZonesPanelComponent extends Component {
    @service store;
    @service modalsManager;
    @service notifications;
    @service crud;
    @service serviceAreas;
    @service appCache;

    @tracked isLoading = false;
    @tracked serviceAreaRecords = [];
    @tracked editableLayers = [];
    @tracked serviceAreaTypes = ['neighborhood', 'city', 'region', 'state', 'province', 'country', 'continent'];
    @alias('args.map') leafletMap;
    @alias('args.map.liveMap.activeServiceArea') activeServiceArea;
    @alias('args.map.liveMap.activeFeatureGroup') activeFeatureGroup;

    @action setupZonesPanel() {
        this.fetchServiceAreas();
        this.serviceAreas.setMapInstance(this.leafletMap);
    }

    @action calculatePosition(trigger) {
        const position = calculateInPlacePosition(...arguments);
        const rect = trigger.getBoundingClientRect();

        position.style.top = '-0.5rem';
        position.style.left = `calc(${rect.width}px + 0.75rem)`;

        return position;
    }

    @action onAction(actionName, dd, ...params) {
        if (typeof dd?.actions?.close === 'function') {
            dd.actions.close();
        }

        if (typeof this[actionName] === 'function') {
            this[actionName](...params);
        }

        if (typeof this.args[actionName] === 'function') {
            this.args[actionName](...params);
        }

        if (typeof this.args[`on${actionName?.toUpperCase()}`] === 'function') {
            this.args[`on${actionName?.toUpperCase()}`](...params);
        }
    }

    @action serviceAreaAction(actionName, dropdown, ...params) {
        const { dd } = this.args;

        if (typeof dd?.actions?.close === 'function') {
            dd.actions.close();
        }

        if (typeof dropdown?.actions?.close === 'function') {
            dropdown.actions.close();
        }

        if (typeof this.serviceAreas[actionName] === 'function') {
            this.serviceAreas[actionName](...params);
        }
    }

    @action liveMapAction(actionName, dropdown, ...params) {
        const { dd } = this.args;

        if (typeof dd?.actions?.close === 'function') {
            dd.actions.close();
        }

        if (typeof dropdown?.actions?.close === 'function') {
            dropdown.actions.close();
        }

        if (typeof this.leafletMap?.liveMap[actionName] === 'function') {
            this.leafletMap?.liveMap[actionName](...params);
        }
    }

    @action fetchServiceAreas() {
        this.isLoading = true;

        if (this.appCache.has('serviceAreas')) {
            this.serviceAreaRecords = this.appCache.getEmberData('serviceAreas', 'service-area');
        }

        this.store
            .query('service-area', { with: ['zones'] })
            .then((serviceAreaRecords) => {
                this.serviceAreaRecords = serviceAreaRecords;
                this.appCache.setEmberData('serviceAreas', serviceAreaRecords);
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
}
