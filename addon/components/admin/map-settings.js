import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import { action } from '@ember/object';
import MAP_PROVIDER_OPTIONS from '../../utils/map-provider-options';

export default class AdminMapSettingsComponent extends Component {
    @service fetch;
    @service notifications;
    @tracked mapProviderOptions = MAP_PROVIDER_OPTIONS;
    @tracked mapProvider = 'leaflet';
    @tracked googleMapsMapId = '';

    constructor() {
        super(...arguments);
        this.loadSettings.perform();
    }

    @task *loadSettings() {
        try {
            const settings = yield this.fetch.get('fleet-ops/settings/admin-map');
            this.mapProvider = settings?.mapProvider ?? 'leaflet';
            this.googleMapsMapId = settings?.googleMapsMapId ?? '';
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action selectMapProvider(option) {
        this.mapProvider = option?.value ?? option ?? 'leaflet';
    }

    @task *saveSettings() {
        try {
            yield this.fetch.post('fleet-ops/settings/admin-map', {
                mapProvider: this.mapProvider,
                googleMapsMapId: this.googleMapsMapId,
            });

            this.notifications.success('Map settings saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
