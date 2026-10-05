import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class OrderDetailsComponent extends Component {
    @service orderPresentation;
    @tracked proofReloadToken = 0;

    get effectiveProofReloadToken() {
        return `${this.args.proofReloadToken ?? 0}:${this.proofReloadToken}`;
    }

    /** The extension presentation profile applying to this order's config, if any. */
    get profile() {
        return this.orderPresentation.profileFor(this.args.resource);
    }

    /** Profile sections with the change handler each native section expects. */
    get profileSections() {
        const handlers = {
            activity: this.handleActivityChanged,
            detail: this.args.onDetailsChanged,
            'custom-fields': this.args.onCustomFieldsChanged,
            notes: this.args.onNotesChanged,
            route: this.args.onRouteChanged,
            payload: this.args.onPayloadChanged,
            documents: this.args.onDocumentsChanged,
            comments: this.args.onCommentsChanged,
            metadata: this.args.onMetadataChanged,
        };

        return this.orderPresentation.renderableSectionsFor(this.profile, 'details').map((section) => {
            return section.native ? { ...section, onChange: handlers[section.name] } : section;
        });
    }

    @action handleActivityChanged(optionsOrActivity) {
        if (typeof this.args.onActivityChanged === 'function') {
            this.args.onActivityChanged(optionsOrActivity?.activityCreated ?? optionsOrActivity);
        }

        if (optionsOrActivity?.proofCreated) {
            this.proofReloadToken++;
        }
    }
}
