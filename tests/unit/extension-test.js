import { module, test } from 'qunit';
import extension from '@fleetbase/fleetops-engine/extension';

module('Unit | FleetOps extension', function () {
    test('it registers the FleetOps analytics dashboard', function (assert) {
        const dashboards = [];
        const registrations = {};
        const defaultRegistrations = {};
        const widgetService = {
            registerDashboard(id) {
                dashboards.push(id);
            },
            registerWidgets(id, widgets) {
                registrations[id] = widgets;
            },
            registerDefaultWidgets(id, widgets) {
                defaultRegistrations[id] = widgets;
            },
        };

        extension.registerWidgets(widgetService);

        assert.deepEqual(dashboards, ['fleet-ops']);
        assert.ok(registrations.dashboard, 'global dashboard widgets remain registered');
        assert.ok(registrations['fleet-ops'], 'FleetOps dashboard widgets are registered');
        assert.strictEqual(registrations['fleet-ops'], registrations.dashboard, 'FleetOps dashboard reuses the same widget suite');
        assert.ok(defaultRegistrations['fleet-ops']?.length > 0, 'FleetOps default dashboard widgets are explicitly registered');
        assert.ok(
            defaultRegistrations['fleet-ops'].every((widget) => widget.default === true),
            'only default widgets are registered as dashboard defaults'
        );
        assert.ok(
            defaultRegistrations['fleet-ops'].some((widget) => widget.id === 'fleet-ops-live-fleet-widget' && widget.widgetId === 'fleet-ops-live-fleet-widget'),
            'default analytics widgets are available'
        );
    });
});
