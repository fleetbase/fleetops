import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class NavigatorAppControlsComponent extends Component {
    @service fetch;
    @tracked isLoading = false;
    @tracked url;

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
