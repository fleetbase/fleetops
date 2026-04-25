import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { task } from 'ember-concurrency';
import fleetOpsOptions from '../../utils/fleet-ops-options';

export default class EntityFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @tracked useCustomType = false;

    constructor() {
        super(...arguments);
        this.useCustomType = this.selectedEntityType === undefined && Boolean(this.args.resource?.type);
    }

    get entityTypes() {
        return fleetOpsOptions('entityTypes');
    }

    get selectedEntityType() {
        return this.entityTypes.find((option) => option.value === this.args.resource?.type);
    }

    @action selectEntityType(option) {
        this.useCustomType = false;
        this.args.resource.type = option?.value ?? null;
    }

    @action toggleCustomType(value) {
        this.useCustomType = value;

        if (!value && !this.selectedEntityType) {
            this.args.resource.type = null;
        }
    }

    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/entities/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:entity',
                    type: 'entity_photo',
                },
                (uploadedFile) => {
                    this.args.resource.setProperties({
                        photo_uuid: uploadedFile.id,
                        photo_url: uploadedFile.url,
                        photo: uploadedFile,
                    });
                }
            );
        } catch (err) {
            this.notifications.error('Unable to upload photo: ' + err.message);
        }
    }
}
