import Component from '@glimmer/component';
import { action } from '@ember/object';

export default class CellTelematicProviderComponent extends Component {
    get descriptor() {
        return this.args.row?.provider_descriptor ?? {};
    }

    get name() {
        return this.args.row?.name ?? this.descriptor.label ?? this.args.row?.provider;
    }

    get description() {
        return this.descriptor.description ?? this.args.row?.provider;
    }

    get icon() {
        return this.descriptor.icon;
    }

    @action onClick(event) {
        const { row, column, onClick } = this.args;

        if (typeof onClick === 'function') {
            onClick(row, event);
        }

        if (typeof column?.action === 'function') {
            column.action(row, event);
        }

        if (typeof column?.onClick === 'function') {
            column.onClick(row, event);
        }
    }
}
