import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class ContactFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service intl
     */
    @service intl;

    /**
     * @service fetch
     */
    @service fetch;

    /**
     * @service currentUser
     */
    @service currentUser;

    /**
     * @service notifications
     */
    @service notifications;

    /**
     * @service hostRouter
     */
    @service hostRouter;

    /**
     * @service contextPanel
     */
    @service contextPanel;

    /**
     * Overlay context.
     * @type {any}
     */
    @tracked context;

    /**
     * All possible contact types.
     *
     * @var {String}
     */
    @tracked contactTypeOptions = ['contact', 'customer'];

    /**
     * All possible contact status options.
     *
     * @var {String}
     */
    @tracked contactStatusOptions = ['pending', 'active', 'do-not-contact', 'prospective', 'archived'];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.contact = this.args.contact;
        applyContextComponentArguments(this);
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Task to save contact.
     *
     * @return {void}
     * @memberof ContactFormPanelComponent
     */
    @task *save() {
        contextComponentCallback(this, 'onBeforeSave', this.contact);

        try {
            this.contact = yield this.contact.save();
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }

        this.notifications.success(this.intl.t('fleet-ops.component.contact-form-panel.success-message', { contactName: this.contact.name }));
        contextComponentCallback(this, 'onAfterSave', this.contact);
    }

    /**
     * Uploads a new photo for the driver.
     *
     * @param {File} file
     * @memberof DriverFormPanelComponent
     */
    @action onUploadNewPhoto(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.currentUser.companyId}/drivers/${this.contact.id}`,
                subject_uuid: this.contact.id,
                subject_type: 'fleet-ops:contact',
                type: 'contact_photo',
            },
            (uploadedFile) => {
                this.contact.setProperties({
                    photo_uuid: uploadedFile.id,
                    photo_url: uploadedFile.url,
                    photo: uploadedFile,
                });
            }
        );
    }

    /**
     * View the details of the contact.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.contact);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.contact, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.contact);
    }

    /**
     * Uploads a file to the server for the contact.
     *
     * @param {File} file
     */
    uploadContactPhoto(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.contact.company_uuid}/contacts/${this.contact.slug}`,
                subject_uuid: this.contact.id,
                subject_type: 'fleet-ops:contact',
                type: 'contact_photo',
            },
            (uploadedFile) => {
                this.contact.setProperties({
                    photo_uuid: uploadedFile.id,
                    photo_url: uploadedFile.url,
                    photo: uploadedFile,
                });
            }
        );
    }
}
