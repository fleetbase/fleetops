import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class NavigatorAppControlsComponent extends Component {
    @service fetch;
    @tracked isLoading = false;
    @tracked url;
    @tracked selectedOrderConfig;
    @tracked entityFields = ['name', 'sku', 'description', 'height', 'width', 'length', 'weight', 'declared value', 'sale price'];
    @tracked driverEntityUpdateSettings = {};

    constructor() {
        super(...arguments);
        this.getAppLinkUrl();
    }

    /**
     * Indicates whether driver entity settings is currently enabled.
     *
     * @property {boolean} isEnabled
     * @public
     */
    @tracked isDriverEntityUpdateSettingEnabled;

    /**
     * Action handler for toggling Driver Entity Update Settings.
     *
     * @method enableDriverEntityUpdateSetting
     * @param {boolean} isDriverEntityUpdateSettingEnabled - Indicates whether Driver Entity Settings is enabled.
     * @return {void}
     * @public
     */
    @action enableDriverEntityUpdateSetting(isDriverEntityUpdateSettingEnabled) {
        this.isDriverEntityUpdateSettingEnabled = isDriverEntityUpdateSettingEnabled;
    }

    @action onConfigChanged(orderConfig) {
        console.log('onConfigChanged()', ...arguments);
        this.driverEntityUpdateSettings = {
            ...this.driverEntityUpdateSettings,
            [orderConfig.id]: {
                editable_entity_fields: [],
            },
        };
    }

    @action makeFieldEditable(fieldName) {
        const editableFields = this.driverEntityUpdateSettings[this.selectedOrderConfig.id].editable_entity_fields;
        if (isArray(editableFields)) {
            editableFields.pushObject(fieldName);
        }
        this.driverEntityUpdateSettings = {
            ...this.driverEntityUpdateSettings,
            [this.selectedOrderConfig.id]: {
                editable_entity_fields: editableFields,
            },
        };
    }

    getAppLinkUrl() {
        this.isLoading = true;

        return this.fetch
            .get('fleet-ops/navigator/get-link-app')
            .then(({ linkUrl }) => {
                this.url = linkUrl;
            })
            .finally(() => {
                this.isLoading = false;
            });
    }
}
