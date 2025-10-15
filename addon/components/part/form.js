import Component from '@glimmer/component';
import { task } from 'ember-concurrency';

export default class PartFormComponent extends Component {
    /**
     * Part type options
     */
    partTypeOptions = [
        'filter',
        'tire',
        'belt',
        'brake_pad',
        'battery',
        'oil',
        'coolant',
        'spark_plug',
        'air_filter',
        'fuel_filter',
        'transmission_fluid',
        'wiper_blade',
        'light_bulb',
        'fuse',
        'sensor',
        'other',
    ];

    /**
     * Status options for parts
     */
    statusOptions = ['in_stock', 'low_stock', 'out_of_stock', 'discontinued', 'on_order'];

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
