import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { pluralize } from 'ember-inflector';
import getModelName from '@fleetbase/ember-core/utils/get-model-name';
import isUuid from '@fleetbase/ember-core/utils/is-uuid';

export default class AvatarPickerComponent extends Component {
    @service store;
    @tracked model;
    @tracked type;
    @tracked endpoint;

    constructor(owner, { model, endpoint }) {
        super(...arguments);

        this.model = model;
        this.type = getModelName(model);
        this.endpoint = endpoint ?? `${pluralize(this.type)}/avatars`;
    }

    /**
     * Set the selected avatar
     *
     * @param {String} input
     */
    @action async selectAvatar(input) {
        // Normalize: empty -> clear to default
        if (!input) {
            const changed = this.model.avatar_url !== null || this.model.avatar_custom_url !== null;

            this.model.setProperties({
                avatar_url: null,
                avatar_custom_url: null,
            });

            if (changed) this.args.onSelect?.(this.model, null);
            return;
        }

        // UUID path: fetch File and use its URL
        if (isUuid(input)) {
            const id = input;

            // Correct Ember Data usage
            let file = this.store.peekRecord('file', id);
            if (!file) {
                try {
                    file = await this.store.findRecord('file', id);
                } catch (e) {
                    // Optional: surface a toast here if you want
                    return;
                }
            }
            if (!file) return;

            // No-op fast path
            if (this.model.avatar_url === file.id && this.model.avatar_custom_url === file.url) {
                this.args.onSelect?.(this.model, file.url);
                return;
            }

            this.model.setProperties({
                avatar_url: file.id,
                avatar_custom_url: file.url,
            });

            this.args.onSelect?.(this.model, file.url);
            return;
        }

        // URL path (treat any non-UUID string as a URL)
        const url = String(input);
        if (this.model.avatar_url === url && this.model.avatar_custom_url === null) {
            this.args.onSelect?.(this.model, url);
            return;
        }

        this.model.setProperties({
            avatar_url: url,
            avatar_custom_url: null,
        });

        this.args.onSelect?.(this.model, url);
    }
}
