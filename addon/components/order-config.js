import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action, computed, set } from '@ember/object';
import { equal } from '@ember/object/computed';
import isModel from '@fleetbase/ember-core/utils/is-model';

export default class OrderConfigComponent extends Component {
    @service modalsManager;
    @service fetch;
    @service socket;
    @service intl;
    @service store;
    @service notifications;
    @service crud;
    @service currentUser;
    @tracked activeTab = 'details';

    @equal('activeTab', 'details') isDetailsTab;
    @equal('activeTab', 'activity') isActivityTab;
    @equal('activeTab', 'entities') isEntitiesTab;

    @tracked selected;
    @tracked isLoading = true;
    @tracked isSaving = false;
    @tracked configurations = [];
    @tracked uninstallChannels = [];

    @computed('selected', 'configurations.[]') get orderConfig() {
        return this.configurations.find((config) => config.namespace === this.selected);
    }

    @computed('selected', 'configurations.[]') get orderConfigIndex() {
        return this.configurations.findIndex((config) => config.namespace === this.selected);
    }

    @computed('orderConfig', 'orderConfig.meta.fields.[]') get configFields() {
        const defaultLogicFields = ['status', 'type', 'internal_id', 'id'];
        const metaFields = Array.from(this.orderConfig.meta.fields ?? []).map((metaField) => `meta.${metaField.key}`);

        return [...defaultLogicFields, ...metaFields];
    }

    @action setupComponent() {
        this.loadConfigurations();
    }

    @action async loadConfigurations() {
        this.configurations = await this.fetchConfigurations();

        if (typeof this.args.onReady === 'function') {
            this.args.onReady(this.configurations);
        }
    }

    @action selectConfig(selected) {
        this.selected = selected;

        if (typeof this.args.onConfigChanged === 'function') {
            this.args.onConfigChanged(this.orderConfig);
        }
    }

    @action fetchConfigurations() {
        this.isLoading = true;

        return new Promise((resolve, reject) => {
            this.fetch
                .get('fleet-ops/order-configs/get-installed')
                .then((configs) => {
                    const serialized = [];

                    for (let i = 0; i < configs.length; i++) {
                        const config = configs.objectAt(i);
                        const normalizedConfig = this.store.normalize('order-config', config);
                        const serializedConfig = this.store.push(normalizedConfig);

                        serialized.pushObject(serializedConfig);
                    }

                    if (typeof this.args.onConfigsLoaded === 'function') {
                        this.args.onConfigsLoaded(serialized);
                    }

                    this.isLoading = false;
                    resolve(serialized);
                })
                .catch((error) => {
                    this.isLoading = false;
                    reject(error);
                });
        });
    }

    @action createNewOrderConfig() {
        const tags = [];

        this.modalsManager.show('modals/new-order-config', {
            title: this.intl.t('fleet-ops.component.order-config.create-new-title'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            name: null,
            description: null,
            tags,
            addTag: (tag) => {
                tags.pushObject(tag);
            },
            removeTag: (index) => {
                tags.removeAt(index);
            },
            confirm: (modal) => {
                modal.startLoading();

                const { name, description, tags } = modal.getOptions();

                if (!name) {
                    modal.stopLoading();
                    return this.notifications.warning(this.intl.t('fleet-ops.component.order-config.warning-message'));
                }

                return this.fetch
                    .post('fleet-ops/order-configs/new', { name, description, tags })
                    .then((newConfig) => {
                        const newConfigExtension = this.fetch.jsonToModel(newConfig, 'extension');

                        this.configurations.pushObject(newConfigExtension);
                        this.notifications.success(this.intl.t('fleet-ops.component.order-config.success-message'));
                        this.selectConfig(newConfigExtension.namespace);
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
        });
    }

    @action saveConfig() {
        const { orderConfig } = this;

        if (!orderConfig) {
            this.notifications.warning(this.intl.t('fleet-ops.component.order-config.no-order-warning'));

            return;
        }

        this.isSaving = true;
        this.fetch
            .post(`fleet-ops/order-configs/save`, {
                data: orderConfig,
            })
            .then(() => {
                this.notifications.success(this.intl.t('fleet-ops.component.order-config.saved-success', { orderName: orderConfig.name }));
                this.isSaving = false;
            })
            .catch((error) => {
                this.notifications.serverError(error);
                this.isSaving = false;
            });
    }

    @action cloneConfiguration(extension) {
        const name = `${extension.name} Clone`;
        const description = extension.description;

        this.modalsManager.show('modals/clone-config-form', {
            title: this.intl.t('fleet-ops.component.order-config.enter-name-title'),
            acceptButtonIcon: 'check',
            acceptButtonIconPrefix: 'fas',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            name,
            description,
            confirm: (modal) => {
                modal.startLoading();

                const { name, description } = modal.getOptions();

                if (!name) {
                    return this.notifications.warning(this.intl.t('fleet-ops.component.order-config.no-config-warning'));
                }

                return this.fetch
                    .post('fleet-ops/order-configs/clone', {
                        id: extension.id,
                        installed: extension.installed,
                        name,
                        description,
                    })
                    .then((newConfig) => {
                        const clone = this.fetch.jsonToModel(newConfig, 'extension');
                        this.configurations.pushObject(clone);
                        this.notifications.success(this.intl.t('fleet-ops.component.order-config.cloned-success'));
                        this.selectConfig(clone.namespace);
                    })
                    .catch((error) => {
                        this.notifications.serverError(error);
                    });
            },
        });
    }

    @action deleteExtension(extension) {
        const uninstall = isModel(extension) ? extension : this.fetch.jsonToModel(extension, 'extension');

        return this.crud.delete(uninstall, {
            body: this.intl.t('fleet-ops.component.order-config.body'),
            acceptButtonIcon: 'trash',
            declineButtonIcon: 'times',
            declineButtonIconPrefix: 'fas',
            onFinish: () => {
                this.configurations.removeObject(extension);
                this.selected = undefined;
            },
        });
    }

    @action uninstallConfiguration(extension) {
        const extensionName = extension.display_name ?? extension.name;

        this.listenForUninstallProgress(extension);

        this.modalsManager.show('modals/uninstall-prompt', {
            title: this.intl.t('fleet-ops.component.order-config.uninstall-title'),
            acceptButtonText: 'Uninstall',
            acceptButtonScheme: 'danger',
            extension,
            extensionName,
            isUninstalling: false,
            uninstallProgress: 0,
            confirm: (modal, done) => {
                modal.startLoading();
                modal.setOption('isUninstalling', true);

                extension
                    .uninstall()
                    .then(() => {
                        this.closeUninstallChannel();
                        this.notifications.success(this.intl.t('fleet-ops.component.order-config.uninstall-success', { extensionName: extensionName }));
                        this.configurations.removeObject(extension);
                        this.selected = undefined;
                        done();
                    })
                    .catch((error) => {
                        modal.stopLoading();
                        modal.setOption('isInstalling', false);
                        this.notifications.serverError(error);
                    });
            },
            decline: (modal) => {
                this.closeUninstallChannel();
                modal.done();
            },
        });
    }

    async listenForUninstallProgress(extension) {
        // get channel identifier
        const channelId = `${extension.id}:${this.currentUser.id}`;

        // setup socket
        const socket = this.socket.instance();

        // get channel
        const channel = socket.subscribe(channelId);
        this.uninstallChannels = channel;

        // listen to channel for events
        await channel.listener('subscribe').once();

        // get incoming data and console out
        for await (let data of channel) {
            if (data.progress) {
                this.modalsManager.setOption('uninstallProgress', data.progress);
            }
        }
    }

    closeUninstallChannel() {
        if (this.uninstallChannels) {
            this.uninstallChannels.close();
        }
    }

    @action setMetaFields(fields = []) {
        set(this.orderConfig, 'meta.fields', fields);
    }

    @action setFlow(flow = {}) {
        set(this.orderConfig, 'meta.flow', flow);
    }

    @action setEntities(entities = {}) {
        set(this.orderConfig, 'meta.entities', entities);
    }
}
