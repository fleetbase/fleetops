import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';

export default class RouteOptimizationEngineSelectButtonComponent extends Component {
    @service routeOptimization;

    @action handleClick({ key }) {
        if (typeof this.args.onClick === 'function') {
            this.args.onClick(key);
        }
    }
}
