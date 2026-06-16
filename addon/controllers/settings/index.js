import Controller from '@ember/controller';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class SettingsIndexController extends Controller {
    @service docsPanel;

    groups = [
        {
            title: 'Driver And Map Experience',
            description: 'Tune what dispatchers and drivers see while work is moving.',
            links: [
                { label: 'Navigator App', route: 'settings.navigator-app', icon: 'location-arrow', description: 'Driver app behavior and workflow defaults.' },
                { label: 'Map', route: 'settings.map', icon: 'map', description: 'Map providers, defaults, and display behavior.' },
            ],
        },
        {
            title: 'Dispatch Automation',
            description: 'Shape route planning, assignment, and scheduling behavior.',
            links: [
                { label: 'Routing', route: 'settings.routing', icon: 'route', description: 'Routing engines and routing defaults.' },
                { label: 'Orchestrator', route: 'settings.orchestrator', icon: 'circle-nodes', description: 'Dispatch orchestration and assignment behavior.' },
                { label: 'Scheduling', route: 'settings.scheduling', icon: 'calendar-days', description: 'Schedule planning and operational timing rules.' },
            ],
        },
        {
            title: 'Business And Data',
            description: 'Keep commerce, metadata, and visual conventions aligned.',
            links: [
                { label: 'Payments', route: 'settings.payments', icon: 'cash-register', description: 'Payment setup for operational commerce workflows.' },
                { label: 'Custom Fields', route: 'settings.custom-fields', icon: 'pen-to-square', description: 'Operational metadata fields for Fleet-Ops records.' },
                { label: 'Avatars', route: 'settings.avatars', icon: 'icons', description: 'Visual assets for driver, vehicle, and map displays.' },
            ],
        },
        {
            title: 'Communication',
            description: 'Control operational alerts and notification behavior.',
            links: [{ label: 'Notifications', route: 'settings.notifications', icon: 'bell', description: 'Operational status alerts and notification rules.' }],
        },
    ];

    actions = [
        {
            label: 'Start with driver experience',
            description: 'Navigator App and Map settings should be reviewed before operators rely on live dispatch defaults.',
            icon: 'location-arrow',
            route: 'settings.navigator-app',
            tone: 'info',
        },
        {
            label: 'Review automation rules',
            description: 'Routing, orchestration, and scheduling settings affect assignment and planning behavior.',
            icon: 'route',
            route: 'settings.routing',
            tone: 'warning',
        },
        {
            label: 'Keep forms focused',
            description: 'Add custom fields after core workflows are stable so operational screens stay easy to scan.',
            icon: 'pen-to-square',
            route: 'settings.custom-fields',
            tone: 'success',
        },
    ];

    docs = [
        {
            label: 'Navigator App',
            icon: 'location-arrow',
            slug: 'fleet-ops/settings/navigator-app',
            title: 'Navigator App settings',
            description: 'Configure driver app behavior, onboarding, and live workflow defaults.',
        },
        {
            label: 'Map',
            icon: 'map',
            slug: 'fleet-ops/settings/map',
            title: 'Map settings',
            description: 'Set map providers, display defaults, and operator map behavior.',
        },
        {
            label: 'Payments',
            icon: 'cash-register',
            slug: 'fleet-ops/settings/payments',
            title: 'Payments guide',
            description: 'Connect payment providers and payment behavior for Fleet-Ops workflows.',
        },
        {
            label: 'Notifications',
            icon: 'bell',
            slug: 'fleet-ops/settings/notifications',
            title: 'Notification settings',
            description: 'Tune operational alerts sent to dispatchers, customers, and drivers.',
        },
        {
            label: 'Routing',
            icon: 'route',
            slug: 'fleet-ops/settings/routing',
            title: 'Routing settings',
            description: 'Configure routing engines, tracking defaults, and distance units.',
        },
        {
            label: 'Orchestrator',
            icon: 'circle-nodes',
            slug: 'fleet-ops/settings/orchestrator',
            title: 'Orchestrator settings',
            description: 'Select dispatch automation engines and assignment behavior.',
        },
        {
            label: 'Scheduling',
            icon: 'calendar-days',
            slug: 'fleet-ops/settings/scheduling',
            title: 'Scheduling settings',
            description: 'Manage schedule templates and timing rules for planned work.',
        },
        {
            label: 'Custom Fields',
            icon: 'pen-to-square',
            slug: 'fleet-ops/settings/custom-fields',
            title: 'Custom field settings',
            description: 'Add structured operational metadata to Fleet-Ops records.',
        },
        {
            label: 'Avatars',
            icon: 'icons',
            slug: 'fleet-ops/settings/avatars',
            title: 'Avatar settings',
            description: 'Manage visual markers used across maps and operational screens.',
        },
    ];

    @action openDocs(link) {
        if (!link?.slug) {
            return;
        }

        return this.docsPanel.open(link.slug, {
            title: link.title ?? link.label,
            source: 'fleet-ops-settings-hub',
        });
    }
}
