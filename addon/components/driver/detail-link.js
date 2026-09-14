import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class DriverDetailLinkComponent extends Component {
    @service driverActions;

    get driver() {
        return this.args.driver ?? this.args.resource ?? null;
    }

    get title() {
        const driver = this.driver;
        return driver?.name || driver?.displayName || driver?.public_id || null;
    }

    get subtitle() {
        const driver = this.driver;
        return driver?.phone || driver?.email || null;
    }

    @action open() {
        const driver = this.driver;
        if (!driver) {
            return;
        }

        if (this.args.mode !== 'route' && this.driverActions.panel?.view) {
            return this.driverActions.panel.view(driver);
        }

        return this.driverActions.transition.view(driver);
    }
}
