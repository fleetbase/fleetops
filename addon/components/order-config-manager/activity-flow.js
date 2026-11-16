/* eslint-disable no-undef */
import Component from '@glimmer/component';
import ObjectProxy from '@ember/object/proxy';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import { next } from '@ember/runloop';
import { task } from 'ember-concurrency';
import lowercase from 'ember-cli-string-helpers/utils/lowercase';
import generateUUID from '@fleetbase/ember-core/utils/generate-uuid';
import inlineTask from '@fleetbase/ember-core/utils/inline-task';
import createFlowActivity from '../../utils/create-flow-activity';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import normalizeOrderConfigFlow, { getOrderConfigFlowRootCode } from '../../utils/normalize-order-config-flow';
import { ICON_GROUPS, actionIcon, actionLabel } from '../../utils/order-config-action-meta';

const NODE_WIDTH = 236;
const NODE_HEIGHT = 64;

/** Role tile icons, drawn on a 24 × 24 grid. */
const ROLE_ICONS = {
    start: 'M8 5v14l11-7z',
    step: 'M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    complete: 'M5 12l5 5L20 7',
    end: 'M7 7h10v10H7z',
};

/** Action icons on a node, drawn on a 24 × 24 grid, keyed by the icon names in order-config-action-meta. */
const ACTION_PATHS = {
    bell: 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4',
    comments: 'M21 12a8 8 0 0 1-12 7l-5 1 1-4a8 8 0 1 1 16-4z',
    'comment-slash': 'M21 12a8 8 0 0 1-12 7l-5 1 1-4a8 8 0 1 1 16-4zM4 4l16 16',
    'file-invoice': 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
    tag: 'M3 12V4h8l10 10-8 8zM7.5 7.5h.01',
    ban: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM5.6 5.6l12.8 12.8',
    bolt: 'M13 3L5 14h6l-1 7 8-11h-6z',
};

/**
 * Manages the activity flow for order configuration, allowing users to create, edit, and view a sequence of activities.
 * @extends Component
 */
export default class OrderConfigManagerActivityFlowComponent extends Component {
    @service resourceContextPanel;
    @service notifications;
    @service intl;
    @service abilities;
    @service fetch;

    /** Actions, events and placeholders triggers can use, from `order-configs/capabilities`. */
    @tracked capabilities = { actions: [], events: [], placeholders: [] };

    /**
     * Represents the current state of the activity flow.
     * @type {Object}
     */
    @tracked flow = {};

    /**
     * An array of activity codes that should not be modified.
     * @type {Array.<string>}
     */
    /**
     * Activities that cannot be edited or removed. Configs with their own lifecycle
     * (`meta.lifecycle`) are not bound to the default dispatch activities.
     */
    get immutableActivities() {
        return this.lifecycle ? [] : ['created', 'dispatched', 'started'];
    }

    get lifecycle() {
        return this.config?.meta?.lifecycle ?? null;
    }

    /**
     * The configuration data for the activity flow.
     * @type {Object}
     */
    @tracked config;

    /**
     * JointJS paper instance where the graph is rendered.
     * @type {Object}
     */
    @tracked paper;

    /**
     * JointJS graph instance representing the activity flow.
     * @type {Object}
     */
    @tracked graph;

    /**
     * True when the rendered graph is wider than the visible Activity Flow area.
     *
     * @type {Boolean}
     */
    @tracked hasHorizontalOverflow = false;

    /**
     * Checks if listener pointerdown listener is called.
     *
     * @type {Boolean}
     */
    @tracked listeningForClicks = false;

    graphScrollContainer;
    horizontalScrollbar;
    horizontalScrollbarContent;
    isSyncingHorizontalScroll = false;
    onGraphScroll;
    onHorizontalScrollbarScroll;
    onWindowResize;

    /**
     * Initializes the component with the given configuration.
     * @param {Object} owner - The owner of the component instance.
     * @param {Object} config - The configuration data for the activity flow.
     */
    constructor(owner, { config, configManagerContext }) {
        super(...arguments);
        this.config = config;
        this.loadCapabilities.perform();

        configManagerContext.on('onConfigChanged', (newConfig) => {
            this.changeConfig(newConfig);
        });
    }

    /**
     * Action to initialize the graph on the JointJS paper instance.
     * @param {Object} paper - The JointJS paper instance.
     * @param {Object} graph - The JointJS graph instance.
     */
    @action initializeGraph({ paper, graph }) {
        this.paper = paper;
        this.graph = graph;
        this.initializeActivityJointModel();
        this.initializeActivityFlow();
        this.listenForElementClicks();
        this.updateHorizontalScrollbar();
    }

    @action setupGraphScrollContainer(element) {
        this.graphScrollContainer = element;
        this.onGraphScroll = () => this.syncHorizontalScrollbarFromGraph();
        this.onWindowResize = () => this.updateHorizontalScrollbar();

        element.addEventListener('scroll', this.onGraphScroll);
        window.addEventListener('resize', this.onWindowResize);
        this.updateHorizontalScrollbar();
    }

    @action setupHorizontalScrollbar(element) {
        this.horizontalScrollbar = element;
        this.horizontalScrollbarContent = element.firstElementChild;
        this.onHorizontalScrollbarScroll = () => this.syncGraphFromHorizontalScrollbar();

        element.addEventListener('scroll', this.onHorizontalScrollbarScroll);
        this.updateHorizontalScrollbar();
    }

    willDestroy() {
        super.willDestroy(...arguments);

        if (this.graphScrollContainer && this.onGraphScroll) {
            this.graphScrollContainer.removeEventListener('scroll', this.onGraphScroll);
        }

        if (this.horizontalScrollbar && this.onHorizontalScrollbarScroll) {
            this.horizontalScrollbar.removeEventListener('scroll', this.onHorizontalScrollbarScroll);
        }

        if (this.onWindowResize) {
            window.removeEventListener('resize', this.onWindowResize);
        }
    }

    /**
     * Zooms in by scaling the paper.
     *
     * @return {void}
     * @memberof OrderConfigManagerActivityFlowComponent
     */
    @action zoomIn() {
        const currentScale = this.paper.scale();
        const currentScaleSx = currentScale.sx;
        if (currentScaleSx >= 1.2) {
            return;
        }

        this.paper.scale(currentScaleSx + 0.2);
    }

    /**
     * Zooms out by scaling the paper.
     *
     * @return {void}
     * @memberof OrderConfigManagerActivityFlowComponent
     */
    @action zoomOut() {
        const currentScale = this.paper.scale();
        const currentScaleSx = currentScale.sx;
        if (currentScaleSx <= 0.2) {
            return;
        }

        this.paper.scale(currentScaleSx - 0.2);
    }

    /**
     * Handles the reset of the config.
     *
     * @memberof OrderConfigManagerActivityFlowComponent
     */
    @action resetConfig() {
        this.clearGraph({
            onAfter: () => {
                this.flow = {};
                // A configured lifecycle resets to its saved flow, never to the default dispatch flow.
                if (this.lifecycle && this.config?.flow && Object.keys(this.config.flow).length) {
                    this.deserializeFlow(this.config.flow);
                    return;
                }

                const defaultActivities = this.getDefaultActivities();
                this.addActivityToGraph(defaultActivities);
            },
        });
    }

    /**
     * Handles the change of a config.
     *
     * @param {OrderConfigModel} newConfig
     * @memberof OrderConfigManagerActivityFlowComponent
     */
    changeConfig(newConfig) {
        this.clearGraph({
            onAfter: () => {
                this.config = newConfig;
                this.initializeActivityFlow();
                this.listenForElementClicks();
            },
        });
    }

    /**
     * Handles the reset of the config.
     *
     * @memberof OrderConfigManagerActivityFlowComponent
     */
    clearGraph(options = {}) {
        if (typeof options.onBefore === 'function') {
            options.onBefore();
        }
        for (let activityCode in this.flow) {
            const activity = this.flow[activityCode];
            const node = activity.get('node');
            if (node) {
                node.remove();
            }
        }
        if (typeof options.onAfter === 'function') {
            options.onAfter();
        }
    }

    /**
     * Listens for element click events within the JointJS paper.
     */
    listenForElementClicks() {
        if (this.listeningForClicks === true) return;

        this.paper.on('element:pointerdown', (elementView, event) => {
            this.onActivityClicked(elementView);
            contextComponentCallback(this, 'onActivityClicked', elementView, event);
        });
        this.listeningForClicks = true;
    }

    /**
     * Initializes the custom JointJS shapes used in the activity flow.
     */
    initializeActivityJointModel() {
        const chip = (index) => [
            { tagName: 'rect', selector: `chip${index}`, className: 'flow-activity-chip' },
            { tagName: 'path', selector: `chipIcon${index}`, className: 'flow-activity-chip-icon' },
        ];
        joint.shapes.fleetbase = {};
        joint.shapes.fleetbase.Activity = joint.dia.Element.define(
            'fleetbase.Activity',
            {
                attrs: {
                    body: { width: 'calc(w)', height: 'calc(h)', rx: 12, ry: 12 },
                    tile: { x: 12, y: 'calc(0.5*h-16)', width: 32, height: 32, rx: 9, ry: 9 },
                    tileIcon: { transform: 'translate(20, 24) scale(0.67)' },
                    status: { x: 54, y: 'calc(0.5*h-4)', fontSize: 13, fontWeight: 600, textVerticalAnchor: 'middle' },
                    meta: { x: 54, y: 'calc(0.5*h+13)', fontSize: 11, textVerticalAnchor: 'middle' },
                },
            },
            {
                markup: [
                    { tagName: 'rect', selector: 'body', className: 'flow-activity' },
                    { tagName: 'rect', selector: 'tile', className: 'flow-activity-tile' },
                    { tagName: 'path', selector: 'tileIcon', className: 'flow-activity-tile-icon' },
                    { tagName: 'text', selector: 'status', className: 'flow-activity-status' },
                    { tagName: 'text', selector: 'meta', className: 'flow-activity-meta' },
                    ...chip(0),
                    ...chip(1),
                    ...chip(2),
                ],
            }
        );
    }

    /**
     * Asynchronously saves the current state of the activity flow.
     * @returns {Generator}
     */
    @task *save() {
        const flow = this.serializeFlow();
        this.config.set('flow', flow);
        yield this.config.save().then((config) => {
            this.config = config;
            this.initializeActivityFlow();
            this.listenForElementClicks();
        });
    }

    /**
     * Saves the flow as it is on the canvas without redrawing it, for "Save step".
     */
    @task *persistFlow() {
        this.config.set('flow', this.serializeFlow());
        try {
            yield this.config.save();
            this.notifications.success(this.intl.t('order-config-manager.step.saved'));
            this.args.onConfigUpdated?.(this.config);
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *loadCapabilities() {
        try {
            const { actions, events, placeholders } = yield this.fetch.get('order-configs/capabilities');
            this.capabilities = { actions: actions ?? [], events: events ?? [], placeholders: placeholders ?? [] };
        } catch {
            this.capabilities = { actions: [], events: [], placeholders: [] };
        }
    }

    get eventCount() {
        return (this.config?.triggers ?? []).length;
    }

    /** The actions used anywhere on the flow, for the legend. */
    get legend() {
        const used = new Set(
            Object.values(this.config?.flow ?? {})
                .flatMap((activity) => activity?.actions ?? [])
                .map((trigger) => trigger?.action)
        );
        return ICON_GROUPS.filter((group) => group.actions.some((name) => used.has(name))).map((group) => ({
            icon: group.icon,
            label: this.intl.t(`order-config-manager.flow.${group.key}`),
        }));
    }

    /**
     * Opens the order events: triggers for things that happen to an order outside its steps.
     */
    @action openOrderEvents() {
        this.resourceContextPanel.open({
            content: 'order-config/order-events',
            title: this.intl.t('order-config-manager.events.title'),
            panelContentClass: 'py-2 px-4',
            resource: this.config,
            config: this.config,
            actions: this.capabilities.actions,
            events: this.capabilities.events,
            placeholders: this.capabilities.placeholders,
            onSaved: (config) => this.args.onConfigUpdated?.(config),
        });
    }

    /**
     * If any context is provided then initialize the state.
     *
     * @memberof OrderConfigManagerCustomFieldsComponent
     */
    initializeContext() {
        next(this, () => {
            const { context, contextModel } = this.args;
            if (typeof context === 'string' && contextModel === 'activity') {
                const contextActivity = Object.values(this.flow).find((activity) => activity.get('internalId') === context);
                if (contextActivity) {
                    this.editActivity(contextActivity);
                }
            }
        });
    }

    /**
     * Serializes the current activity flow into a JSON-compatible format.
     * @returns {Object} The serialized activity flow.
     */
    serializeFlow() {
        const serialized = {};
        const keys = Object.keys(this.flow);
        keys.forEach((key) => {
            const activity = this.flow[key];
            // remove node
            activity.set('node', undefined);
            // remove ids
            activity.set('id', undefined);
            activity.set('parentId', undefined);
            // remove internal model
            activity.set('_internalModel', undefined);
            // map activities into id and code string
            const activities = activity.get('activities');
            activity.set(
                'activities',
                activities.map((_activity) => {
                    return _activity.get('code');
                })
            );
            serialized[key] = activity.content;
        });

        return serialized;
    }

    /**
     * Deserializes the incoming activity flow and reconstructs the activity graph.
     * @param {Object} incomingFlow - The incoming serialized activity flow.
     */
    deserializeFlow(incomingFlow) {
        const normalizedFlow = normalizeOrderConfigFlow(incomingFlow);
        const deserializedFlow = {};
        const keys = Object.keys(normalizedFlow);
        keys.forEach((key) => {
            const activity = this.deserializeActivity(normalizedFlow[key], normalizedFlow, deserializedFlow);
            if (activity) {
                deserializedFlow[activity.get('code')] = activity;
            }
        });

        const rootCode = getOrderConfigFlowRootCode(deserializedFlow, this.lifecycle?.initial);
        if (rootCode && deserializedFlow[rootCode]) {
            this.addDeserializedActivityToGraph(deserializedFlow[rootCode]);
        }
    }

    /**
     * Deserializes a single activity.
     * @param {Object} activityObject - Serialized activity data.
     * @param {Object} incomingFlow - The entire incoming serialized activity flow for reference.
     * @returns {Object} Deserialized activity object.
     */
    deserializeActivity(activityObject, incomingFlow, deserializedFlow = {}) {
        if (!activityObject || typeof activityObject !== 'object') {
            return;
        }

        const code = activityObject.code ?? activityObject.key;
        if (!code) {
            return;
        }

        if (deserializedFlow[code]) {
            return deserializedFlow[code];
        }

        const activity = createFlowActivity(code, activityObject.status, activityObject.details, activityObject.sequence, activityObject.color, {
            key: activityObject.key,
            logic: activityObject.logic ?? [],
            events: activityObject.events ?? [],
            entities: activityObject.entities ?? [],
            actions: activityObject.actions ?? [],
            require_pod: activityObject.require_pod ?? false,
            pod_method: activityObject.pod_method ?? 'scan',
            complete: activityObject.complete ?? false,
            internalId: activityObject.internalId ?? generateUUID(),
        });
        deserializedFlow[code] = activity;
        const { activities } = activityObject;
        if (isArray(activities)) {
            activity.set(
                'activities',
                activities
                    .map((activityKey) => {
                        return this.deserializeActivity(incomingFlow[activityKey], incomingFlow, deserializedFlow);
                    })
                    .filter(Boolean)
            );
        }

        return activity;
    }

    /**
     * Adds deserialized activities to the graph.
     * @param {Object} activity - The activity to add to the graph.
     */
    addDeserializedActivityToGraph(activity) {
        if (!activity) {
            return;
        }

        const positionals = this.getActivityPositioning(activity);
        const parentActivity = this.createActivityNode(activity, positionals);
        const childActivities = activity.get('activities');

        this.addActivityNodeToGraph(parentActivity);
        this.addActivityNodeTools(parentActivity, positionals);
        this.addChildActivities(parentActivity, childActivities);
        this.repositionAllActivities();
        this.resizePaperWidthToFitActivities();
    }

    /**
     * Recursively adds child activities to a parent activity on the graph. It creates nodes for each child, adds them to the graph,
     * and creates links from the parent to each child. It then calls itself to add any children of the current child activities.
     * Finally, it repositions all child activities to maintain visual consistency.
     *
     * A flow is a graph, not a tree: an activity may be reachable from several parents, and a
     * configured lifecycle can loop back (e.g. active -> extended -> active). An activity that is
     * already on the graph only gets a link from this parent; it is never placed or walked twice.
     *
     * @param {Object} parentActivity - The parent activity to which child activities will be added.
     * @param {Array} [childActivities=[]] - An array of child activity objects to be added. Defaults to an empty array.
     */
    addChildActivities(parentActivity, childActivities = []) {
        if (!isArray(childActivities)) {
            return;
        }
        childActivities.forEach((childActivityObject) => {
            if (childActivityObject.get('node')) {
                this.addLinkToGraph(parentActivity, childActivityObject);
                return;
            }

            const childPositionals = this.getActivityPositioning(childActivityObject, parentActivity);
            const childActivity = this.createActivityNode(childActivityObject, childPositionals);
            childActivity.set('parentId', parentActivity.get('id'));

            this.addActivityNodeToGraph(childActivity);
            this.addActivityNodeTools(childActivity, childPositionals);
            this.addLinkToGraph(parentActivity, childActivity);
            this.addChildActivities(childActivity, childActivity.get('activities'));
        });
        this.repositionActivities(parentActivity);
    }

    /**
     * Repositions all activities and their child activities.
     *
     * @memberof OrderConfigManagerActivityFlowComponent
     */
    repositionAllActivities() {
        const activities = Object.values(this.flow);
        activities.forEach((parentActivity) => {
            this.repositionActivities(parentActivity);
        });
    }

    /**
     * Expands the JointJS paper width so wide activity flows can scroll horizontally.
     *
     * @returns {void}
     */
    resizePaperWidthToFitActivities() {
        if (!this.paper) {
            return;
        }

        const nodes = Object.values(this.flow)
            .map((activity) => activity.get('node'))
            .filter(Boolean);

        if (nodes.length === 0) {
            return;
        }

        const padding = 150;
        const viewportWidth = this.paper.el.parentElement?.clientWidth ?? this.paper.options.width;
        const currentHeight = this.paper.options.height;
        const contentWidth = nodes.reduce((width, node) => {
            const position = node.position();
            const size = node.size();

            return Math.max(width, position.x + size.width + padding);
        }, 0);
        const paperWidth = Math.max(viewportWidth, contentWidth);

        this.paper.setDimensions(paperWidth, currentHeight);
        this.paper.el.style.width = contentWidth > viewportWidth ? `${paperWidth}px` : '100%';
        this.paper.el.style.minWidth = '100%';
        next(this, this.updateHorizontalScrollbar);
    }

    updateHorizontalScrollbar() {
        if (!this.graphScrollContainer || !this.horizontalScrollbar || !this.horizontalScrollbarContent) {
            return;
        }

        const scrollWidth = this.graphScrollContainer.scrollWidth;
        const clientWidth = this.graphScrollContainer.clientWidth;
        const hasOverflow = scrollWidth > clientWidth + 1;

        this.hasHorizontalOverflow = hasOverflow;
        this.horizontalScrollbarContent.style.width = hasOverflow ? `${scrollWidth}px` : '100%';
        this.horizontalScrollbar.scrollLeft = this.graphScrollContainer.scrollLeft;

        const { left, width } = this.graphScrollContainer.getBoundingClientRect();
        this.horizontalScrollbar.style.left = `${left}px`;
        this.horizontalScrollbar.style.width = `${width}px`;
    }

    syncHorizontalScrollbarFromGraph() {
        if (this.isSyncingHorizontalScroll || !this.horizontalScrollbar || !this.graphScrollContainer) {
            return;
        }

        this.isSyncingHorizontalScroll = true;
        this.horizontalScrollbar.scrollLeft = this.graphScrollContainer.scrollLeft;
        this.isSyncingHorizontalScroll = false;
    }

    syncGraphFromHorizontalScrollbar() {
        if (this.isSyncingHorizontalScroll || !this.horizontalScrollbar || !this.graphScrollContainer) {
            return;
        }

        this.isSyncingHorizontalScroll = true;
        this.graphScrollContainer.scrollLeft = this.horizontalScrollbar.scrollLeft;
        this.isSyncingHorizontalScroll = false;
    }

    /**
     * Creates and adds a link between a parent activity and a child activity on the graph.
     *
     * @param {Object} parentActivity - The activity object representing the source of the link.
     * @param {Object} childActivity - The activity object representing the target of the link.
     * @returns {Object} - The link object that has been added to the graph.
     */
    addLinkToGraph(parentActivity, childActivity) {
        const link = this.createLink(parentActivity, childActivity);
        link.addTo(this.graph);
        return link;
    }

    /**
     * A connector from a step to one it leads to: a smooth curve with an arrowhead, labelled "or"
     * when it's the second (or later) way out of the step.
     */
    createLink(parentActivity, childActivity) {
        const siblings = (parentActivity.get('activities') ?? []).map((activity) => activity?.get?.('id'));
        const isBranch = siblings.indexOf(childActivity.get('id')) > 0;
        return new joint.shapes.standard.Link({
            source: { id: parentActivity.get('id'), anchor: { name: 'right' } },
            target: { id: childActivity.get('id'), anchor: { name: 'left' } },
            connector: { name: 'smooth' },
            attrs: {
                line: {
                    class: 'flow-activity-link',
                    stroke: '#64748b',
                    strokeWidth: 1.6,
                    targetMarker: { type: 'path', d: 'M 9 -4.5 L 0 0 L 9 4.5', fill: 'none', 'stroke-width': 1.6 },
                },
            },
            labels: isBranch
                ? [
                      {
                          markup: [
                              { tagName: 'rect', selector: 'labelBody', className: 'flow-activity-link-label' },
                              { tagName: 'text', selector: 'labelText', className: 'flow-activity-link-label-text' },
                          ],
                          attrs: {
                              labelText: { text: this.intl.t('order-config-manager.common.or'), fontSize: 11, textAnchor: 'middle', textVerticalAnchor: 'middle' },
                              labelBody: { ref: 'labelText', x: 'calc(x-8)', y: 'calc(y-3)', width: 'calc(w+16)', height: 'calc(h+6)', rx: 9, ry: 9 },
                          },
                          position: { distance: 0.5 },
                      },
                  ]
                : [],
        });
    }

    /**
     * Handles activity clicks within the JointJS paper.
     * @param {Object} elementView - The JointJS element view of the clicked activity.
     */
    onActivityClicked(elementView) {
        // Anyone who can view the config can open a step; what they can change is decided in the panel.
        if (this.abilities.cannot('fleet-ops view order-config')) return;

        const { model } = elementView;
        const activity = this.getActivityById(model.id);
        if (activity) {
            const parentActivity = this.getActivityById(activity.get('parentId'));
            this.editActivity(activity, parentActivity);
        }
    }

    /**
     * Get the standard default activities which apply to every order.
     * @returns {Array}
     */
    getDefaultActivities() {
        const created = createFlowActivity('created', 'Order Created', 'New order was created.');
        const dispatched = createFlowActivity('dispatched', 'Order Dispatched', 'Order has been dispatched.');
        const started = createFlowActivity('started', 'Order Started', 'Order has been started');
        return [created, [dispatched, started]];
    }

    /**
     * Initializes the activity flow by either loading from configuration or creating a default flow.
     */
    initializeActivityFlow() {
        const hasFlow = this.config && Object.keys(this.config.flow).length > 0;
        if (hasFlow) {
            this.deserializeFlow(this.config.flow);
            this.initializeContext();
            return;
        }

        const defaultActivities = this.getDefaultActivities();
        this.addActivityToGraph(defaultActivities);
        this.initializeContext();
    }

    /**
     * Adds multiple activities to the graph when provided as an array.
     * @param {Array} activities - An array of activities to be added sequentially.
     */
    addMultipleLinearActivities(activities = []) {
        if (!isArray(activities)) {
            return;
        }

        let lastActivity = null;
        activities.forEach((activityObject, index) => {
            if (index > 0 && lastActivity) {
                if (isArray(activityObject)) {
                    let lastChildActivity = null;
                    return activityObject.forEach((childActivityObject, childIndex) => {
                        if (childIndex > 0 && lastChildActivity) {
                            this.addNewLinkedActivity(lastChildActivity, childActivityObject);
                            return;
                        }
                        const firstChildActivity = this.addNewLinkedActivity(lastActivity, childActivityObject);
                        lastChildActivity = firstChildActivity;
                    });
                }

                this.addNewLinkedActivity(lastActivity, activityObject);
                return;
            }

            const firstActivity = this.addActivityToGraph(activityObject);
            lastActivity = firstActivity;
        });
        this.resizePaperWidthToFitActivities();
        return;
    }

    /**
     * Adds a single activity to the graph, optionally using parent activity for positioning.
     * @param {Object} activity - The activity to add.
     * @param {Object} [parentActivity] - The parent activity to use for positioning.
     * @returns {Object} The activity added to the graph.
     */
    addActivityToGraph(activity, parentActivity) {
        if (isArray(activity)) {
            this.addMultipleLinearActivities(activity);
            return;
        }

        const positionals = this.getActivityPositioning(activity, parentActivity);
        const newActivity = this.createActivityNode(activity, positionals);
        if (parentActivity) {
            this.repositionActivities(parentActivity);
        }

        this.addActivityNodeToGraph(newActivity);
        this.addActivityNodeTools(newActivity, positionals);

        return activity;
    }

    /**
     * Adds an activity node to the JointJS graph.
     * @param {Object} activity - The activity whose node is to be added.
     * @returns {Object} The activity added to the graph.
     */
    addActivityNodeToGraph(activity) {
        const activityNode = activity.get('node');
        if (activityNode) {
            activityNode.addTo(this.graph);
        }
        this.addActivityToFlowMap(activity);
        return activity;
    }

    /**
     * Adds the activity to the internal flow mapping.
     * @param {Object} activity - The activity to add to the flow map.
     * @returns {Object} The updated flow map.
     */
    addActivityToFlowMap(activity) {
        this.flow = {
            ...this.flow,
            [activity.get('code')]: activity,
        };
        return this.flow;
    }

    /**
     * Updates an existing activity in the flow map.
     * @param {Object} activity - The activity to update in the flow map.
     * @returns {Object} The updated flow map.
     */
    updateActivityInFlowMap(activity) {
        const flowClone = { ...this.flow };
        flowClone[activity.get('code')] = activity;
        this.flow = flowClone;
        return this.flow;
    }

    /**
     * Coerces activity label fields before passing them to JointJS text helpers.
     *
     * @param {*} value
     * @returns {String}
     */
    activityText(value) {
        return typeof value === 'string' ? value : value == null ? '' : String(value);
    }

    /**
     * Creates a new activity node based on the provided activity and positioning data.
     * @param {Object} activity - The activity data.
     * @param {Object} positionals - Positioning information for the activity node.
     * @returns {Object} The created activity node.
     */
    createActivityNode(activity, positionals = {}) {
        const { width, height, x, y } = positionals;
        const activityNode = new joint.shapes.fleetbase.Activity({
            position: { x, y },
            size: { width, height },
            attrs: this.activityNodeAttrs(activity, width),
            interactive: false,
            activity: activity,
        });

        activity.set('id', activityNode.id);
        activity.set('node', activityNode);

        return activity;
    }

    /**
     * What a step's node shows: a tile for its role (start, step, completes the order, ends it),
     * its status and code, and an icon for each thing that happens when an order reaches it.
     *
     * @param {Object} activity
     * @param {Number} width
     * @returns {Object} JointJS attrs
     */
    activityNodeAttrs(activity, width = NODE_WIDTH) {
        const code = this.activityText(activity.get('code'));
        const status = this.activityText(activity.get('status')) || code;
        const role = this.activityRole(activity);
        const actions = (activity.get('actions') ?? []).map((trigger) => trigger?.action).filter(Boolean);
        const shown = actions.slice(0, 3);
        const roleText = role === 'step' ? '' : ` · ${this.intl.t(`order-config-manager.flow.role-${role}`)}`;
        const textWidth = width - 54 - 12 - shown.length * 23;
        const attrs = {
            body: { class: 'flow-activity' },
            tile: { class: `flow-activity-tile flow-activity-tile--${role}` },
            tileIcon: { d: ROLE_ICONS[role], class: `flow-activity-tile-icon flow-activity-tile-icon--${role}` },
            status: { text: joint.util.breakText(status, { width: textWidth }, { 'font-size': 13, 'font-weight': 600 }, { maxLineCount: 1, ellipsis: true }) },
            meta: { text: joint.util.breakText(`${code}${roleText}`, { width: textWidth }, { 'font-size': 11 }, { maxLineCount: 1, ellipsis: true }) },
        };
        for (let index = 0; index < 3; index++) {
            const name = shown[index];
            const chipX = width - 12 - (shown.length - index) * 23 + 3;
            attrs[`chip${index}`] = name ? { display: 'block', x: chipX, y: 22, width: 20, height: 20, rx: 6, ry: 6 } : { display: 'none' };
            attrs[`chipIcon${index}`] = name
                ? { display: 'block', d: ACTION_PATHS[actionIcon(name)] ?? ACTION_PATHS.bolt, transform: `translate(${chipX + 4.5}, 26.5) scale(0.46)` }
                : { display: 'none' };
            if (name) {
                attrs[`chip${index}`].title = actionLabel(this.intl, name, this.capabilities.actions);
            }
        }

        return attrs;
    }

    /**
     * A step's role in the lifecycle: where orders start, a step along the way, the step that
     * completes them, or another step that ends them.
     */
    activityRole(activity) {
        const code = activity.get('code');
        const lifecycle = this.lifecycle ?? {};
        const root = getOrderConfigFlowRootCode(normalizeOrderConfigFlow(this.config?.flow ?? {}), lifecycle.initial);
        if (activity.get('complete') || lifecycle.completed === code) {
            return 'complete';
        }
        if (lifecycle.canceled === code || (lifecycle.terminal ?? []).includes(code)) {
            return 'end';
        }
        if (code === (lifecycle.initial ?? root ?? 'created')) {
            return 'start';
        }
        return 'step';
    }

    /** Redraws a step's node after it changed in the side panel. */
    refreshActivityNode(activity) {
        const node = activity?.get?.('node');
        if (node) {
            node.attr(this.activityNodeAttrs(activity, node.size().width));
        }
    }

    /**
     * Determines the positioning for an activity node.
     * @param {Object} activity - The activity for which to determine positioning.
     * @param {Object} [parentActivity] - The parent activity, if applicable.
     * @returns {Object} Positioning information for the activity node.
     */
    getActivityPositioning(activity, parentActivity) {
        const parentActivities = parentActivity ? parentActivity.get('activities') : [];
        const width = NODE_WIDTH;
        const height = NODE_HEIGHT;
        let x = 100;
        if (parentActivity) {
            const parentActivityX = parentActivity.get('node').position().x;
            x = parentActivityX + width + 100;
        }
        let y = this.paper.options.height / 2 - height / 2;
        if (parentActivity) {
            const spacing = 50;

            // If there are already child activities, stack the new one below them
            if (parentActivities.length > 0) {
                const lastActivity = parentActivities[parentActivities.length - 1];
                const lastActivityNode = lastActivity.get('node');
                // only if last activity has a node if not then its not added or linked yet so skip
                if (lastActivityNode) {
                    const lastActivityHeight = lastActivityNode.size().height;
                    const lastActivityY = lastActivityNode.position().y;

                    // Calculate new y position
                    y = lastActivityY + lastActivityHeight + spacing;
                }
            }
        }

        return {
            width,
            height,
            x,
            y,
        };
    }

    /**
     * Adds interactive tools (like remove and add buttons) to an activity node.
     * @param {Object} activity - The activity to which tools are to be added.
     * @param {Object} positionals - Positioning information for the tools.
     */
    addActivityNodeTools(activity, positionals = {}) {
        const activityNode = activity.get('node');
        if (!activityNode || this.config.core_service) {
            return;
        }
        const { width } = positionals;
        const removeButton = this.createActivityRemoveButton({ x: width });
        const addButton = this.createAddActivityButton(activity, { x: width });
        let tools = [];

        const code = activity.get('code');
        const canUpdate = this.abilities.can('fleet-ops update order-config');

        if (this.lifecycle) {
            // The configured initial activity is the root: it can gain children but not be removed.
            if (canUpdate) {
                tools = code === this.lifecycle.initial ? [addButton] : [removeButton, addButton];
            }
        } else {
            if (code === 'created' || code === 'dispatched') {
                tools = [];
            }

            if (code === 'started' && canUpdate) {
                tools = [addButton];
            }

            if (!this.immutableActivities.includes(code) && canUpdate) {
                tools = [removeButton, addButton];
            }
        }

        const activityNodeView = activityNode.findView(this.paper);
        if (activityNodeView) {
            activityNodeView.addTools(
                new joint.dia.ToolsView({
                    tools,
                })
            );
        }
    }

    /**
     * Creates a remove button tool for an activity node.
     * @param {Object} props - Additional properties for the remove button tool.
     * @returns {Object} The remove button tool.
     */
    createActivityRemoveButton(props = {}) {
        const removeButton = new joint.elementTools.Remove({
            offset: { x: 0, y: -1 },
            focusOpacity: 0.5,
            rotate: true,
            y: 0,
            ...props,
            action: (evt, elementView, toolView) => {
                elementView.model.remove({ ui: true, tool: toolView.cid });
                this.removeActivityUsingElementView(elementView);
            },
        });

        return removeButton;
    }

    /**
     * Creates an 'Add' button tool for activity nodes, allowing users to add new linked activities.
     *
     * @param {Object} activity - The activity object to which the 'Add' button will be linked.
     * @param {Object} [props={}] - Additional properties to customize the button's appearance and behavior.
     * @returns {joint.elementTools.Button} - The configured 'Add' button tool.
     */
    createAddActivityButton(activity, props = {}) {
        const addButton = new joint.elementTools.Button({
            markup: [
                {
                    tagName: 'circle',
                    selector: 'button',
                    className: 'flow-activity-add-button',
                    attributes: {
                        r: 12,
                        fill: '#2563eb',
                        stroke: '#2563eb',
                        cursor: 'default',
                    },
                },
                {
                    tagName: 'path',
                    selector: 'icon',
                    attributes: {
                        d: 'M -4 0 L 4 0 M 0 -4 L 0 4',
                        fill: 'none',
                        stroke: '#FFFFFF',
                        strokeWidth: 2,
                        pointerEvents: 'none',
                    },
                },
            ],
            offset: { x: 2, y: 0 },
            focusOpacity: 0.5,
            rotate: true,
            y: '50%',
            ...props,
            action: () => {
                this.createNewActivity(activity);
            },
        });

        return addButton;
    }

    /**
     * Handles the removal of an activity from the graph using its view.
     *
     * @param {joint.dia.ElementView} elementView - The JointJS view for the element to be removed.
     */
    removeActivityUsingElementView(elementView) {
        const deletedActivityId = elementView.model.id;
        const deletedActivity = this.getActivityById(deletedActivityId);
        if (deletedActivity) {
            this.removeActivityFromFlow(deletedActivity);
            const parentActivity = this.getActivityById(deletedActivity.get('parentId'));
            if (parentActivity) {
                this.removeActivityFromParentById(parentActivity, deletedActivityId);
                this.repositionActivities(parentActivity);
            }
        }
    }

    /**
     * Repositions all child activities in relation to the parent activity to maintain a consistent layout.
     *
     * @param {Object} parentActivity - The parent activity whose children will be repositioned.
     */
    repositionActivities(parentActivity) {
        const activities = parentActivity.get('activities').filter((activity) => activity.get('node'));
        if (activities.length === 0) {
            return;
        }

        const spacingY = 50; // Vertical spacing between activities
        const parentY = parentActivity.get('node').position().y;
        const parentHeight = parentActivity.get('node').size().height;

        // Calculate the total height of all activities including spacing
        const totalActivitiesHeight = activities.reduce((total, activity) => {
            return total + activity.get('node').size().height + spacingY;
        }, -spacingY); // Subtract one spacing to account for the first activity not needing a top margin

        // The starting y position centers the block of activities around the parent's y position
        let startY = parentY + parentHeight / 2 - totalActivitiesHeight / 2;

        activities.forEach((activity, index) => {
            const activityHeight = activity.get('node').size().height;
            const yPos = startY + (activityHeight + spacingY) * index;
            activity.get('node').position(activity.get('node').position().x, yPos);
        });

        // If there is only one activity, it should be aligned with the parent's y position
        if (activities.length === 1) {
            const singleActivity = activities[0];
            singleActivity.get('node').position(singleActivity.get('node').position().x, parentY + parentHeight / 2 - singleActivity.get('node').size().height / 2);
        }
    }

    /**
     * Retrieves an activity by its ID from the current flow.
     *
     * @param {string} id - The unique identifier for the activity.
     * @returns {Object|null} - The activity object if found, otherwise null.
     */
    getActivityById(id) {
        if (!id) {
            return null;
        }

        const activities = Object.values(this.flow);
        return activities.find((activity) => {
            return activity.get('id') === id;
        });
    }

    /**
     * Removes an activity from its parent's list of activities by ID.
     *
     * @param {Object} parentActivity - The parent activity object.
     * @param {string} id - The unique identifier of the child activity to be removed.
     */
    removeActivityFromParentById(parentActivity, id) {
        const activities = parentActivity.get('activities');
        const filteredActivities = activities.filter((activity) => activity.get('id') !== id);
        parentActivity.set('activities', filteredActivities);
    }

    /**
     * Removes an activity from the current flow.
     *
     * @param {Object} activity - The activity object to be removed from the flow.
     */
    removeActivityFromFlow(activity) {
        const flowClone = { ...this.flow };
        delete flowClone[activity.get('code')];
        this.flow = flowClone;
    }

    /**
     * Initializes the process to create a new activity, focusing the context panel on the new activity object.
     *
     * @param {Object} targetActivity - The activity object that will serve as the reference for the new activity.
     * @returns {Object} - The newly created activity object.
     */
    createNewActivity(targetActivity) {
        const activity = createFlowActivity();
        return this.editActivity(activity, targetActivity);
    }

    /**
     * Initiates the editing process for a given activity, handling immutable activities accordingly.
     *
     * @param {Object} activity - The activity object to be edited.
     * @param {Object} targetActivity - The target activity object that may be linked to the activity being edited.
     */
    editActivity(activity, targetActivity) {
        const isNew = Boolean(targetActivity) && !this.flow[activity.get('code')];
        // A system config's steps, and the standard dispatch steps, keep their details; what happens at them can still change.
        const readonlyDetails = Boolean(this.config.core_service) || this.immutableActivities.includes(activity.get('code'));
        const steps = Object.keys(this.flow);
        const position = steps.indexOf(activity.get('code')) + 1;

        contextComponentCallback(this, 'onContextChanged', this._createContextualActivity(activity));
        this.resourceContextPanel.open({
            content: 'activity/form',
            title: isNew ? this.intl.t('common.create-new-resource', { resource: lowercase(this.intl.t('resource.activity')) }) : activity.get('status') || activity.get('code'),
            panelContentClass: 'py-2 px-4',
            resource: activity,
            pojoResource: true,
            readonlyDetails,
            initialTab: isNew ? 'details' : 'happens',
            actions: this.capabilities.actions,
            placeholders: this.capabilities.placeholders,
            stepPosition: position > 0 ? this.intl.t('order-config-manager.step.kicker', { position, total: steps.length }) : null,
            saveTask: inlineTask(async () => {
                // Check if the activity exists and if it's not the same instance being updated
                const existingActivity = this.flow[activity.get('code')];
                if (existingActivity && existingActivity !== activity) {
                    return this.notifications.warning(this.intl.t('order-config-manager.activity-flow.edit-activity-unique-code-warning'));
                }

                contextComponentCallback(this, 'onContextChanged', null);
                this.updateActivityInFlowMap(activity);
                if (targetActivity) {
                    this.addNewLinkedActivity(targetActivity, activity);
                }
                this.refreshActivityNode(activity);
                this.resourceContextPanel.closeAll();
                await this.persistFlow.perform();
            }),
            onClose: () => {
                contextComponentCallback(this, 'onContextChanged', null);
                this.refreshActivityNode(activity);
            },
        });
    }

    /**
     * Creates a contextual activity using ObjectProxy.
     *
     * This function wraps the content of a given activity inside an ObjectProxy,
     * allowing for proxy-based interaction with the activity's content. The function
     * also sets the 'id' of the newly created proxy to the 'internalId' of the activity.
     * This can be useful for tracking or referencing the original activity.
     *
     * @param {Object} activity - The activity object whose content needs to be proxied.
     * @returns {ObjectProxy} The created contextual activity, which is an ObjectProxy instance with the same content as the original activity, but with a reassigned 'id'.
     */
    _createContextualActivity(activity) {
        const contextualActivity = ObjectProxy.create({ content: { ...activity.get('content') } });
        // set persited id to id
        contextualActivity.set('id', activity.get('internalId'));
        return contextualActivity;
    }

    /**
     * Adds a new linked activity to the target activity and updates the graph accordingly.
     *
     * @param {Object} targetActivity - The activity to which the new activity will be linked.
     * @param {Object} activity - The new activity object to be added.
     * @returns {Object} - The newly linked activity object.
     */
    addNewLinkedActivity(targetActivity, activity) {
        // Add the new activity at the top of the stack
        const newActivity = this.addActivityToGraph(activity, targetActivity);
        newActivity.set('parentId', targetActivity.get('id'));

        // Check if target activity has this activity if not add it
        if (!this.parentHasActivity(targetActivity, newActivity)) {
            targetActivity.get('activities').unshiftObject(newActivity);
        }

        // Update positions to stack activities vertically with spacing
        this.repositionActivities(targetActivity);

        // Link the new activity
        this.addLinkToGraph(targetActivity, newActivity);
        this.resizePaperWidthToFitActivities();

        return newActivity;
    }

    /**
     * Checks if a parent activity already contains a specific child activity.
     *
     * @param {Object} parentActivity - The parent activity object.
     * @param {Object} childActivity - The child activity object to check for.
     * @returns {boolean} - True if the parent has the child activity, false otherwise.
     */
    parentHasActivity(parentActivity, childActivity) {
        const activities = parentActivity.get('activities');
        return activities.find((activity) => {
            return childActivity.get('id') === activity.get('id');
        });
    }
}
