import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class EntityFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;

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
