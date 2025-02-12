import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class MapContainerToolbarComponent extends Component {
    @tracked liveMap;
    @tracked map;

    constructor(owner, { map }) {
        super(...arguments);

        if (map) {
            this.map = map;
            this.liveMap = map.liveMap;
        }
    }

    @action calculatePosition(trigger) {
        let { width } = trigger.getBoundingClientRect();

        let style = {
            marginTop: '0px',
            left: `${width + 13}px`,
            top: '0px',
        };

        return { style };
    }

    @action onAction(actionName, ...params) {
        if (typeof this[actionName] === 'function') {
            this[actionName](...params);
        }

        if (typeof this.args[actionName] === 'function') {
            this.args[actionName](...params);
        }
    }

    @action handleArgsChanged(owner, [map]) {
        this.map = map;
        this.liveMap = map.liveMap;
    }

    @action toggleMapTheme() {
        if (this.liveMap && typeof this.liveMap.changeTileSource === 'function') {
            const nextTheme = this.liveMap.mapTheme === 'dark' ? 'light' : 'dark';
            this.liveMap.changeTileSource(nextTheme);
        }
    }

    @action onZoomOut() {
        if (this.map && typeof this.map.zoomOut === 'function') {
            this.map.zoomOut();
        }
    }

    @action onZoomIn() {
        if (this.map && typeof this.map.zoomIn === 'function') {
            this.map.zoomIn();
        }
    }
}
