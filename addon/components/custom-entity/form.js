import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { dasherize } from '@ember/string';

export default class CustomEntityFormComponent extends Component {
    @service notifications;
    @service fetch;
    @tracked config;

    /**
     * Action method to save the custom entity. It triggers an optional onSave callback
     * with the current state of the custom entity.
     * @action
     */
    @action save() {
        if (typeof this.onSave === 'function') {
            this.onSave(this.args.resource);
        }
    }

    /**
     * Action method called when a file is added. It uploads the file
     * and updates the custom entity's photo information.
     * @param {File} file - The file that was added.
     * @action
     */
    @action onFileAdded(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.config.id}/entity/${this.simpleHash(this.args.resource.get('name') + '+' + this.args.resource.get('description'))}`,
                subject_uuid: this.config.id,
                subject_type: 'fleet-ops:order-config',
                type: 'custom_entity_image',
            },
            (uploadedFile) => {
                this.args.resource.setProperties({
                    photo_uuid: uploadedFile.id,
                    photo_url: uploadedFile.url,
                });
            }
        );
    }

    /**
     * Action method to set the type of the custom entity. Converts the type to a dasherized string.
     * @param {Event} event - The event object containing the selected type.
     * @action
     */
    @action setCustomEntityType(event) {
        const value = event.target.value;
        this.args.resource.set('type', dasherize(value));
    }

    /**
     * Action method to update the unit of dimensions of the custom entity.
     * @param {string} unit - The unit for the dimensions.
     * @action
     */
    @action updateCustomEntityDimensionsUnit(unit) {
        this.args.resource.set('dimensions_unit', unit);
    }

    /**
     * Action method to update the weight unit of the custom entity.
     * @param {string} unit - The unit for the weight.
     * @action
     */
    @action updateCustomEntityWeightUnit(unit) {
        this.args.resource.set('weight_unit', unit);
    }

    /**
     * A utility method to generate a simple hash from a string.
     * Used for creating unique identifiers.
     * @param {string} str - The input string.
     * @returns {number} The generated hash value.
     */
    simpleHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = (hash << 5) - hash + char;
            hash |= 0;
        }
        return hash;
    }
}
