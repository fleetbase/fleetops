import Component from '@glimmer/component';
import { transactionStatuses } from '../utils/fuel-transaction';

export default class FuelTransactionStatusComponent extends Component {
    get status() {
        return transactionStatuses[this.args.status ?? this.args.value] ?? { label: 'Unknown', tone: 'default' };
    }
}
