import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';

export default class NavigatorAppControlsComponent extends Component {
    @service fetch;
    @tracked isLoading = false;
    @tracked url;

    constructor() {
        super(...arguments);
        this.getAppLinkUrl();
    }

    /**
     * Indicates whether settings is currently enabled.
     *
     * @property {boolean} isEnabled
     * @public
     */
    @tracked isDriverEntityUpdateSettingEnabled;

    /**
     * Action handler for toggling Entity Settings.
     *
     * @method onEntityToggled
     * @param {boolean} isEntityEnabled - Indicates whether Entity Settings is enabled.
     * @return {void}
     * @public
     */
    @action enableDriverEntityUpdateSetting(isDriverEntityUpdateSettingEnabled) {
        this.isDriverEntityUpdateSettingEnabled = isDriverEntityUpdateSettingEnabled;
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
