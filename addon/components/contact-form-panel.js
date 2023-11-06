import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class ContactFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

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
     * @service loader
     */
    @service loader;

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
     * Indicates whether the component is in a loading state.
     * @type {boolean}
     */
    @tracked isLoading = false;

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
     * Saves the contact changes.
     *
     * @action
     * @returns {Promise<any>}
     */
    @action save() {
        const { contact } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving contact...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', contact);

        try {
            return contact
                .save()
                .then((contact) => {
                    this.notifications.success(`contact (${contact.name}) saved successfully.`);
                    contextComponentCallback(this, 'onAfterSave', contact);
                })
                .catch((error) => {
                    this.notifications.serverError(error);
                })
                .finally(() => {
                    this.loader.removeLoader('.next-content-overlay-panel-container ');
                    this.isLoading = false;
                });
        } catch (error) {
            this.loader.removeLoader('.next-content-overlay-panel-container ');
            this.isLoading = false;
        }
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
                subject_type: `contact`,
                type: `contact_photo`,
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
                subject_type: 'contact',
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
