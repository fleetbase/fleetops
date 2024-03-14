import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class NavigatorAppControlsComponent extends Component {
    @service fetch;
    @tracked isLoading = false;
    @tracked url;
    @tracked selectedOrderConfig;
    @tracked entityFields = [
        { name: 'Name', visible: false },
        { name: 'Sku', visible: false },
        { name: 'Description', visible: false },
        { name: 'Height', visible: false },
        { name: 'Width', visible: false },
        { name: 'Length', visible: false },
        { name: 'Weight', visible: false },
        { name: 'Declared Value', visible: false },
        { name: 'Sale Price', visible: false },
    ];
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
        this.selectedOrderConfig = {};
        this.driverEntityUpdateSettings = {
            ...this.driverEntityUpdateSettings,
            [orderConfig.id]: {
                // editable_entity_fields: [name, sku, weight],
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

    @action onSave() {
        console.log(
            'Checked fields: ',
            this.entityFields.filter((item) => item.visible)
        );
        // const { entityFields } = this;
        // this.isLoading = true;
        // return this.fetch
        //     .post('drivers/save-settings', { entityFields })
        //     .then(() => {
        //         this.entityFields.success('Successfuully saved.');
        //     })
        //     .catch((error) => {
        //         this.entityFields.serverError(error);
        //     });
    }
}
