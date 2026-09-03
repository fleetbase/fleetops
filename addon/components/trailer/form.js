import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { task } from 'ember-concurrency';

export default class TrailerFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;
    @service intl;

    get typeOptions() {
        return [
            'dry_van',
            'reefer',
            'flatbed',
            'step_deck',
            'lowboy',
            'tanker',
            'bulk',
            'dump',
            'chassis',
            'curtain_side',
            'car_carrier',
            'livestock',
            'logging',
            'dolly',
            'specialty',
            'other',
        ].map((value) => ({ value, label: this.intl.t(`trailer.types.${value}`) }));
    }
    get statusOptions() {
        return ['available', 'in_use', 'maintenance', 'out_of_service', 'retired'].map((value) => ({ value, label: this.intl.t(`trailer.statuses.${value}`) }));
    }
    get measurementOptions() {
        return ['metric', 'imperial'].map((value) => ({ value, label: this.intl.t(`trailer.measurement.${value}`) }));
    }

    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/trailers/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:trailer',
                    type: 'trailer_photo',
                },
                (photo) => this.args.resource.setProperties({ photo_uuid: photo.id, photo_url: photo.url, photo })
            );
        } catch (error) {
            this.notifications.error(this.intl.t('trailer.messages.photo-error', { message: error.message }));
        }
    }
}
