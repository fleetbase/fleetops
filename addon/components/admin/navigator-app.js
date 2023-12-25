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
