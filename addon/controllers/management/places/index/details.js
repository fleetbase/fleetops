import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';

export default class ManagementPlacesIndexDetailsController extends Controller {
    @service hostRouter;
    @tracked tabs = [
        {
            route: 'management.places.index.details.index',
            label: 'Overview',
        },
        {
            route: 'management.places.index.details.operations',
            label: 'Operations',
        },
        {
            route: 'management.places.index.details.performance',
            label: 'Performance',
        },
        {
            route: 'management.places.index.details.activity',
            label: 'Activity',
        },
        {
            route: 'management.places.index.details.map',
            label: 'Map',
        },
        {
            route: 'management.places.index.details.documents',
            label: 'Documents',
        },
        {
            route: 'management.places.index.details.comments',
            label: 'Comments',
        },
        {
            route: 'management.places.index.details.rules',
            label: 'Rules',
        },
    ];
    @tracked actionButtons = [
        {
            icon: 'pencil',
            fn: () => this.hostRouter.transitionTo('console.fleet-ops.management.places.index.edit', this.model),
        },
    ];
}
