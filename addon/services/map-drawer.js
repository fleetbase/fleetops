import Service from '@ember/service';
import Evented from '@ember/object/evented';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class MapDrawerService extends Service.extend(Evented) {
    @service appCache;
    @tracked drawer;
    @tracked drawerComponent;
    @tracked drawerHeight = this.appCache.get('map:drawer:height');
    @tracked activeTabId = this.appCache.get('map:drawer:tab');
    @tracked isMinimized = this.appCache.get('map:drawer:minimized', true);

    @action setDrawer(drawer, drawerComponent) {
        this.drawer = drawer;
        this.drawerComponent = drawerComponent;
        this.trigger('ready', { drawer, drawerComponent });
    }

    @action setActiveTab(tab) {
        this.activeTabId = tab.id;
        this.appCache.set('map:drawer:tab', tab.id);
        this.trigger('tab.changed', tab);
    }

    @action setMinimized(minimized) {
        this.isMinimized = minimized;
        this.appCache.set('map:drawer:minimized', minimized);
    }

    @action setDrawerHeight(height) {
        this.drawerHeight = height;
        this.appCache.set('map:drawer:height', height);
    }

    @action handleResizeEnd({ drawerPanelNode }) {
        const rect = drawerPanelNode.getBoundingClientRect();

        this.setDrawerHeight(rect.height);

        if (rect.height === 0) {
            this.setMinimized(true);
        } else if (rect.height > 1) {
            this.setMinimized(false);
        }
    }

    @action toggle(options = {}) {
        this.drawer.toggleMinimize({
            onToggle: (context) => {
                if (!this.drawerComponent) return;
                this.setMinimized(context.isMinimized);
                this.trigger('toggled', context);
            },
            ...options,
        });
    }

    @action minimize(options = {}) {
        this.drawer.minimize({
            onMinimize: (context) => {
                if (!this.drawerComponent) return;
                this.setMinimized(true);
                this.trigger('minimized', context);
            },
            ...options,
        });
    }

    @action maximize(options = {}) {
        this.drawer.maximize({
            onMaximize: (context) => {
                if (!this.drawerComponent) return;
                this.setMinimized(false);
                this.trigger('maximized', context);
            },
            ...options,
        });
    }
}
