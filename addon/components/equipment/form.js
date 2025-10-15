import Component from '@glimmer/component';
import { task } from 'ember-concurrency';

export default class EquipmentFormComponent extends Component {
    /**
     * Equipment type options
     */
    equipmentTypeOptions = ['ppe', 'refrigeration_unit', 'tool', 'liftgate', 'ramp', 'container', 'pallet_jack', 'forklift', 'safety_equipment', 'communication_device', 'other'];

    /**
     * Status options for equipment
     */
    statusOptions = ['available', 'in_use', 'maintenance', 'retired', 'lost', 'damaged'];

    /**
     * Task to handle photo upload
     */
    @task *handlePhotoUpload(file) {
        try {
            const response = yield file.upload(this.args.resource);
            this.args.resource.photo_uuid = response.uuid;
            this.args.resource.photo_url = response.url;
        } catch (error) {
            console.error('Photo upload failed:', error);
        }
    }
}
