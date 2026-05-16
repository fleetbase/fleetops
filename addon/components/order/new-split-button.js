import Component from '@glimmer/component';

export default class OrderNewSplitButtonComponent extends Component {
    get onNewOrder() {
        return this.args.options?.onNewOrder;
    }

    get onNewSeries() {
        return this.args.options?.onNewSeries;
    }
}
