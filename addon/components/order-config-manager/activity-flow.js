/* eslint-disable no-undef */
import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import createFlowActivity from '../../utils/create-flow-activity';

export default class OrderConfigManagerActivityFlowComponent extends Component {
    @service contextPanel;
    @tracked flow = {};
    @tracked immutableActivities = ['created', 'dispatched'];
    @tracked paper;
    @tracked graph;

    @action initializeGraph({ paper, graph }) {
        this.paper = paper;
        this.graph = graph;
        this.initializeActivityJointModel();
        this.initializeActivityFlow();
    }

    initializeActivityJointModel() {
        joint.shapes.fleetbase = {};
        joint.shapes.fleetbase.Activity = joint.shapes.standard.Rectangle.define(
            'fleetbase.Activity',
            {
                attrs: {
                    rect: { stroke: 'none', fillOpacity: 0 },
                    text: {
                        textVerticalAnchor: 'middle',
                        textAnchor: 'middle',
                        refX: '50%',
                        refY: '50%',
                        fontSize: 14,
                        fill: '#333',
                    },
                },
            },
            {
                markup: [
                    {
                        tagName: 'rect',
                        selector: 'body',
                        className: 'flow-activity',
                    },
                    {
                        tagName: 'rect',
                        selector: 'pill',
                    },
                    {
                        tagName: 'text',
                        selector: 'code',
                    },
                    {
                        tagName: 'text',
                        selector: 'status',
                    },
                    {
                        tagName: 'text',
                        selector: 'details',
                    },
                ],
                initialize: function () {
                    joint.shapes.standard.Rectangle.prototype.initialize.apply(this, arguments);
                    this.on('element:pointerdown', function (elementView) {
                        console.log('element view clicked');
                        if (typeof this.args.onActivityClicked === 'function') {
                            this.args.onActivityClicked(elementView, this);
                        }
                    });
                },
            }
        );
    }

    initializeActivityFlow() {
        const created = createFlowActivity('created', 'Order Created', 'New order was created.');
        const dispatched = createFlowActivity('dispatched', 'Order Dispatched', 'Order has been dispatched.');
        this.addActivityToGraph([created, dispatched]);
    }

    addActivityToGraph(activity, parentActivity) {
        if (isArray(activity)) {
            let lastActivity = null;
            activity.forEach((activityObject, index) => {
                if (index > 0 && lastActivity) {
                    this.addNewLinkedActivity(lastActivity, activityObject);
                    return;
                }

                const firstActivity = this.addActivityToGraph(activityObject);
                lastActivity = firstActivity;
            });
            return;
        }

        const parentActivities = parentActivity ? parentActivity.get('activities') : [];
        const width = 250;
        const baseHeight = 90;
        const lineHeight = 10;
        const wrappedDetails = joint.util.breakText(activity.get('details'), { width });
        const numberOfLines = wrappedDetails.split('\n').length;
        const height = baseHeight + lineHeight * (numberOfLines - 1);
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
                const lastActivityHeight = lastActivityNode.size().height;
                const lastActivityY = lastActivityNode.position().y;

                // Calculate new y position
                y = lastActivityY + lastActivityHeight + spacing;
            }
        }
        const activityNode = new joint.shapes.fleetbase.Activity({
            position: { x, y },
            size: { width, height },
            attrs: {
                pill: {
                    ref: 'code',
                    refWidth: activity.get('code').length * 1.5,
                    refHeight: 5,
                    refX: -5,
                    refY: -2,
                    rx: 5,
                    ry: 5,
                    fill: '#374151',
                    stroke: '#374151',
                    strokeWidth: 1,
                },
                code: { ref: 'pill', text: activity.get('code'), fill: 'white', fontSize: 14, textAnchor: 'left', refX: 15, refY: 20, yAlignment: 'middle' },
                status: { text: activity.get('status'), fill: 'white', fontSize: 14, fontWeight: 'bold', refY: 40, refX: 10, textAnchor: 'left' },
                details: {
                    text: wrappedDetails,
                    fill: 'white',
                    refY: 60,
                    fontSize: 14,
                    refX: 10,
                    textAnchor: 'left',
                },
                body: { fill: activity.get('color'), stroke: '#374151', strokeWidth: 1, rx: 10, ry: 10 },
            },
            interactive: false,
            activity: activity,
        });

        // Reposition all paren's activities
        if (parentActivity) {
            this.repositionActivities(parentActivity);
        }

        // add node to activity
        activity.set('id', activityNode.id);
        activity.set('node', activityNode);

        const removeButton = new joint.elementTools.Remove({
            focusOpacity: 0.5,
            rotate: true,
            x: width,
            y: 0,
            offset: { x: 0, y: -1 },
            action: (evt, elementView, toolView) => {
                elementView.model.remove({ ui: true, tool: toolView.cid });
                // try to get parent activity if any from the deleted activity and run the reposition function
                const deletedActivityId = elementView.model.id;
                const deletedActivity = this.getActivityById(deletedActivityId);
                console.log('deletedActivity', deletedActivity);
                if (deletedActivity) {
                    this.removeActivityFromFlow(deletedActivity);
                    const parentActivity = this.getActivityById(deletedActivity.get('parentId'));
                    console.log('deleted - parentActivity', parentActivity);
                    if (parentActivity) {
                        this.removeActivityFromParentById(parentActivity, deletedActivityId);
                        this.repositionActivities(parentActivity);
                    }
                }
            },
        });

        const addButton = new joint.elementTools.Button({
            focusOpacity: 0.5,
            rotate: true,
            x: width,
            y: '50%',
            offset: { x: 2, y: 0 },
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
            action: () => {
                this.createNewActivity(activity);
            },
        });

        let tools = [];
        if (activity.get('code') === 'created') {
            tools = [];
        }

        if (activity.get('code') === 'dispatched') {
            tools = [addButton];
        }

        if (!this.immutableActivities.includes(activity.get('code'))) {
            tools = [removeButton, addButton];
        }

        activityNode.addTo(this.graph);
        activityNode.findView(this.paper).addTools(
            new joint.dia.ToolsView({
                tools,
            })
        );

        // add activity to flow
        this.flow = {
            ...this.flow,
            [activity.get('code')]: activity,
        };

        console.log('[flow]', this.flow);
        return activity;
    }

    repositionActivities(parentActivity) {
        const activities = parentActivity.get('activities');
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

    getActivityById(id) {
        if (!id) {
            return null;
        }

        const activities = Object.values(this.flow);
        return activities.find((activity) => {
            return activity.get('id') === id;
        });
    }

    removeActivityFromParentById(parentActivity, id) {
        const activities = parentActivity.get('activities');
        const filteredActivities = activities.filter((activity) => activity.get('id') !== id);
        parentActivity.set('activities', filteredActivities);
    }

    removeActivityFromFlow(activity) {
        const flowClone = { ...this.flow };
        delete flowClone[activity.get('id')];
        this.flow = flowClone;
    }

    createNewActivity(targetActivity) {
        const activity = createFlowActivity();
        this.contextPanel.focus(activity, 'editing', {
            args: {
                activity,
                onPressCancel: () => {
                    this.contextPanel.clear();
                    this.addNewLinkedActivity(targetActivity, activity);
                },
            },
        });
    }

    addNewLinkedActivity(targetActivity, activity) {
        // Add the new activity at the top of the stack
        const newActivity = this.addActivityToGraph(activity, targetActivity);
        newActivity.set('parentId', targetActivity.get('id'));
        targetActivity.get('activities').unshiftObject(newActivity);

        // Update positions to stack activities vertically with spacing
        this.repositionActivities(targetActivity);

        // Link the new activity
        const link = new joint.shapes.standard.Link({
            source: { id: targetActivity.get('id') },
            target: { id: newActivity.get('id') },
        });
        link.connector('straight', {
            cornerType: 'cubic',
            precision: 0,
            cornerRadius: 20,
        });
        link.addTo(this.graph);

        return newActivity;
    }
}
