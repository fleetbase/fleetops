import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Maps a user-facing polymorphic type string to the Ember Data model name
 * used by ModelSelect.
 */
const TYPE_TO_MODEL = {
    'fleet-ops:vehicle': 'vehicle',
    'fleet-ops:equipment': 'equipment',
};

export default class PartFormComponent extends Component {
    @service fetch;
    @service currentUser;
    @service notifications;

    /** Part type options. */
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

    /** Status options for parts. */
    statusOptions = ['in_stock', 'low_stock', 'out_of_stock', 'discontinued', 'on_order'];

    /**
     * Polymorphic asset type options — the asset this part is compatible with.
     * Each entry has a `value` (stored on the model) and a `label` (displayed in the UI).
     */
    assetTypeOptions = [
        { value: 'fleet-ops:vehicle', label: 'Vehicle' },
        { value: 'fleet-ops:equipment', label: 'Equipment' },
    ];

    /** Derived Ember Data model name for the currently selected asset type. */
    @tracked assetModelName = null;

    /** The currently selected asset type option object (drives the PowerSelect trigger label). */
    @tracked selectedAssetType = null;

    constructor(owner, args) {
        super(owner, args);
        const { resource } = args;
        if (resource?.asset_type) {
            this.assetModelName = TYPE_TO_MODEL[resource.asset_type] ?? null;
            this.selectedAssetType = this.assetTypeOptions.find((o) => o.value === resource.asset_type) ?? null;
        }
    }

    /**
     * Handles a change to the asset type selector. Resets the asset
     * relationship so a stale association is not persisted.
     */
    @action onAssetTypeChange(option) {
        this.selectedAssetType = option;
        this.args.resource.asset_type = option.value;
        this.args.resource.asset_uuid = null;
        this.args.resource.asset = null;
        this.assetModelName = TYPE_TO_MODEL[option.value] ?? null;
    }

    /** Assigns the selected asset model to the resource. */
    @action assignAsset(model) {
        this.args.resource.asset = model;
        this.args.resource.asset_uuid = model?.id ?? null;
    }

    /**
     * Handles photo upload using the Fleetbase fetch service upload pattern.
     */
    @task *handlePhotoUpload(file) {
        try {
            yield this.fetch.uploadFile.perform(
                file,
                {
                    path: `uploads/${this.currentUser.companyId}/parts/${this.args.resource.id}`,
                    subject_uuid: this.args.resource.id,
                    subject_type: 'fleet-ops:part',
                    type: 'part_photo',
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
