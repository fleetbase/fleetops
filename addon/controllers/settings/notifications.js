import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';
import createNotificationKey from '@fleetbase/ember-core/utils/create-notification-key';

export default class SettingsNotificationsController extends Controller {
    /**
     * Inject the notifications service.
     *
     * @memberof ConsoleAdminNotificationsController
     */
    @service notifications;

    /**
     * Inject the fetch service.
     *
     * @memberof ConsoleAdminNotificationsController
     */
    @service fetch;

    /**
     * The notification settings value JSON.
     *
     * @memberof ConsoleAdminNotificationsController
     * @var {Object}
     */
    @tracked notificationSettings = {};

    /**
     * Notification transport methods enabled.
     *
     * @memberof ConsoleAdminNotificationsController
     * @var {Array}
     */
    @tracked notificationTransportMethods = ['email', 'sms'];

    /**
     * Creates an instance of ConsoleAdminNotificationsController.
     * @memberof ConsoleAdminNotificationsController
     */
    constructor() {
        super(...arguments);
        this.getSettings.perform();
    }

    /**
     * Selectes notifiables for settings.
     *
     * @param {Object} notification
     * @param {Array} notifiables
     * @memberof ConsoleAdminNotificationsController
     */
    @action onSelectNotifiable(notification, notifiables) {
        const notificationKey = createNotificationKey(notification.definition, notification.name);
        const _notificationSettings = { ...this.notificationSettings };

        if (!_notificationSettings[notificationKey]) {
            _notificationSettings[notificationKey] = {};
        }

        _notificationSettings[notificationKey].notifiables = notifiables;
        _notificationSettings[notificationKey].definition = notification.definition;
        _notificationSettings[notificationKey].via = notifiables.map((notifiable) => {
            return {
                identifier: notifiable.value,
                methods: this.notificationTransportMethods,
            };
        });

        this.mutateNotificationSettings(_notificationSettings);
    }

    /**
     * Mutates the notification settings property.
     *
     * @param {Object} [_notificationSettings={}]
     * @memberof ConsoleAdminNotificationsController
     */
    mutateNotificationSettings(_notificationSettings = {}) {
        this.notificationSettings = {
            ...this.notificationSettings,
            ..._notificationSettings,
        };
    }

    /**
     * Save notification settings.
     *
     * @memberof ConsoleAdminNotificationsController
     */
    @task *saveSettings() {
        const { notificationSettings } = this;

        try {
            yield this.fetch.post('fleet-ops/settings/notification-settings', { notificationSettings });
            this.notifications.success('Notification settings successfully saved.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    /**
     * Get notification settings.
     *
     * @memberof ConsoleAdminNotificationsController
     */
    @task *getSettings() {
        try {
            const { notificationSettings } = yield this.fetch.get('fleet-ops/settings/notification-settings');
            this.notificationSettings = notificationSettings;
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
