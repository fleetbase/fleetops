import { module, test } from 'qunit';
import extension from '@fleetbase/fleetops-engine/extension';

module('Unit | FleetOps extension', function () {
    test('it resolves intl from the application container when registering navigation', function (assert) {
        assert.expect(5);

        let headerMenuOptions;
        const requestedUniverseServices = [];
        const app = {
            lookup(name) {
                assert.strictEqual(name, 'service:intl', 'looks up the application intl service');

                return {
                    t(key) {
                        return `translated:${key}`;
                    },
                };
            },
        };
        const services = {
            menu: {
                registerHeaderMenuItem(_title, _route, options) {
                    headerMenuOptions = options;
                },
                registerAdminMenuPanel() {},
                registerMenuItem() {},
            },
            registry: {
                createRegistries() {},
                registerRenderableComponent() {},
            },
            widget: {
                registerDashboard() {},
                registerWidgets() {},
                registerDefaultWidgets() {},
            },
        };
        const universe = {
            extensionManager: {
                isInstalled() {
                    return false;
                },
            },
            getService(name) {
                requestedUniverseServices.push(name);
                return services[name];
            },
        };

        extension.setupExtension(app, universe);

        const trailersShortcut = headerMenuOptions.shortcuts.find((shortcut) => shortcut.route === 'console.fleet-ops.management.trailers');
        assert.deepEqual(requestedUniverseServices, ['menu', 'registry', 'widget'], 'only asks Universe for Universe-owned services');
        assert.ok(trailersShortcut, 'registers the trailers shortcut');
        assert.strictEqual(trailersShortcut.title, 'translated:menu.trailers', 'translates the shortcut title');
        assert.strictEqual(trailersShortcut.description, 'translated:trailer.navigation-description', 'translates the shortcut description');
    });

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

    test('it lays out the default dashboard: KPI row, full-width map, then three panels', function (assert) {
        let registered = [];
        extension.registerWidgets({
            registerDashboard() {},
            registerWidgets(id, widgets) {
                if (id === 'dashboard') registered = widgets.map((widget) => widget.toObject());
            },
            registerDefaultWidgets() {},
        });

        const byId = Object.fromEntries(registered.map((widget) => [widget.id, widget]));
        const defaults = registered.filter((widget) => widget.default === true).sort((a, b) => a.order - b.order);

        assert.deepEqual(
            defaults.map((widget) => [widget.id, widget.order]),
            [
                ['fleet-ops-radar-widget', 10],
                ['fleet-ops-kpi-active-orders-widget', 30],
                ['fleet-ops-kpi-drivers-online-widget', 40],
                ['fleet-ops-live-fleet-widget', 50],
                ['fleet-ops-revenue-trend-widget', 60],
                ['fleet-ops-top-drivers-widget', 70],
                ['fleet-ops-maintenance-overview-widget', 80],
            ],
            'every default widget has a place, leaving 20 for the ledger Revenue tile'
        );
        assert.strictEqual(byId['fleet-ops-live-fleet-widget'].grid_options.w, 12, 'the map spans the full width');
        assert.deepEqual(
            ['fleet-ops-revenue-trend-widget', 'fleet-ops-top-drivers-widget', 'fleet-ops-maintenance-overview-widget'].map((id) => byId[id].grid_options.w),
            [4, 4, 4],
            'the panels share a row'
        );
        assert.false(byId['fleet-ops-kpi-earnings-widget'].default, 'Earnings is no longer a default');
        assert.false(byId['fleet-ops-kpi-aov-widget'].default, 'Avg Order Value is no longer a default');
    });
});
