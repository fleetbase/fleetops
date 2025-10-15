import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class ContactFormComponent extends Component {
    @service contactActions;
    @service fetch;
    @service notifications;
    @service currentUser;

    @action selectPlace(place) {
        if (!place) return;
        this.args.resource.setProperties({
            place,
            place_uuid: place.id,
        });
    }

    @action removePlace() {
        this.args.resource.setProperties({
            place: null,
            place_uuid: null,
        });
    }

    @action editPlace() {
        if (this.args.resource.has_place) {
            this.contactActions.editPlace(this.args.resource);
        } else {
            this.contactActions.createPlace(this.args.resource);
        }
    }

    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/contacts/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:contact',
                    type: 'contact_photo',
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
