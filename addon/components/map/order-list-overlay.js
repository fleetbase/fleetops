import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class MapOrderListOverlayComponent extends Component {
    @service('order-list-overlay') overlay;
    @service fleetActions;
    @service orderActions;
}
