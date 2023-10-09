import Component from '@glimmer/component';
import { inject as controller } from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class DriverCardMiniComponent extends Component {
    @controller('management.drivers.index') drivers;
    @service store;

    toModel(record, modelName) {
        // set id
        if (!record.id && record.uuid) {
            record.id = record.uuid;
        }

        const normalized = this.store.normalize(modelName, record);
        return this.store.push(normalized);
    }

    @action viewDriver(record) {
        const driver = this.toModel(record, 'driver');

        return this.drivers.viewDriver(driver);
    }
}
