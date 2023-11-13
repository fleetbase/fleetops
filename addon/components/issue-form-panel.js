import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import getWithDefault from '@fleetbase/ember-core/utils/get-with-default';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';
import getIssueTypes from '../utils/get-issue-types';
import getIssueCategories from '../utils/get-issue-categories';

export default class IssueFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service fetch
     */
    @service fetch;

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
     * All possible issue types
     *
     * @var {String}
     */
    @tracked issueTypes = getIssueTypes();

    /**
     *  The subcategories for issue types.
     *
     * @var {Object}
     */
    @tracked issueCategoriesByType = getIssueCategories({ fullObject: true });

    /**
     * Selectable issue categories.
     *
     * @memberof IssueFormPanelComponent
     */
    @tracked issueCategories = [];

    /**
     * Issue status options.
     *
     * @memberof IssueFormPanelComponent
     */
    @tracked issueStatusOptions = ['pending', 'in-progress', 'backlogged', 'requires-update', 'in-review', 're-opened', 'duplicate', 'pending-review', 'escalated', 'completed', 'canceled'];

    /**
     * Issue priorty options.
     *
     * @memberof IssueFormPanelComponent
     */
    @tracked issuePriorityOptions = ['low', 'medium', 'high', 'critical', 'scheduled-maintenance', 'operational-suggestion'];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.issue = this.args.issue;
        this.issueCategories = getWithDefault(this.issueCategoriesByType, getWithDefault(this.issue, 'type', 'operational'), []);
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
     * Saves the issue changes.
     *
     * @action
     * @returns {Promise<any>}
     */
    @action save() {
        const { issue } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving issue...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', issue);

        try {
            return issue
                .save()
                .then((issue) => {
                    this.notifications.success(`Issue (${issue.public_id}) saved successfully.`);
                    contextComponentCallback(this, 'onAfterSave', issue);
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
     * Trigger when the issue type is selected.
     *
     * @param {String} type
     * @memberof IssueFormPanelComponent
     */
    @action onSelectIssueType(type) {
        this.issue.type = type;
        this.issue.category = null;
        this.issueCategories = getWithDefault(this.issueCategoriesByType, type, []);
    }

    /**
     * Add a tag to the issue
     *
     * @param {String} tag
     * @memberof IssueFormPanelComponent
     */
    @action addTag(tag) {
        if (!isArray(this.issue.tags)) {
            this.issue.tags = [];
        }

        this.issue.tags.pushObject(tag);
    }

    /**
     * Remove a tag from the issue tags.
     *
     * @param {Number} index
     * @memberof IssueFormPanelComponent
     */
    @action removeTag(index) {
        this.issue.tags.removeAt(index);
    }

    /**
     * View the details of the issue.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.issue);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.issue, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.issue);
    }
}
