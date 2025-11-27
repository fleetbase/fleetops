import { ExtensionComponent } from '@fleetbase/ember-core';

export default {
    setupExtension(universe) {
        // Register menu items
        universe.registerMenuItem('fleet-ops', 'Operations', {
            icon: 'truck',
            priority: 1
        });

        // Register dashboard widgets
        universe.registerDashboardWidget(
            new ExtensionComponent({
                engineName: '@fleetbase/fleetops-engine',
                componentPath: 'widget/orders-metrics'
            })
        );

        // Register admin menu items
        universe.registerAdminMenuItem('Fleet Settings', 'fleet-ops.settings', {
            icon: 'cog'
        });
    }
};
