import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';
import { hash } from 'rsvp';

export default class SettingsNotificationsRoute extends Route {
    @service fetch;

    model() {
        return hash({
            registry: this.fetch.get('fleet-ops/settings/notification-registry'),
            notifiables: this.fetch.get('fleet-ops/settings/notification-notifiables'),
        });
    }

    setupController(controller, { registry, notifiables }) {
        super.setupController(...arguments);

        controller.registry = registry;
        controller.notifiables = notifiables;
    }
}
