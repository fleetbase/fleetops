import Component from '@glimmer/component';
import { get } from '@ember/object';
import config from 'ember-get-config';
import { resolveIdentityCellResource } from '../../utils/identity-cell-resource';

function inventoryStatus(part) {
    if (get(part, 'is_low_stock')) {
        return 'low_stock';
    }

    if (get(part, 'is_in_stock')) {
        return 'in_stock';
    }

    return 'out_of_stock';
}

function inventoryStatusLabel(part) {
    return inventoryStatus(part)
        .split('_')
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

export default class CellPartIdentityComponent extends Component {
    get resource() {
        return resolveIdentityCellResource(this.args);
    }

    get emptyText() {
        return this.args.column?.emptyText ?? '-';
    }

    get column() {
        return {
            ...(this.args.column ?? {}),
            labelPath: 'name',
            mediaPath: 'photo_url',
            fallbackImage: config?.defaultValues?.partImage ?? config?.defaultValues?.placeholderImage,
            statusPath: inventoryStatus,
            statusFormatter: () => null,
            metaPaths: [
                {
                    value: (part) => get(part, 'type'),
                    icon: 'tag',
                    style: 'badge',
                },
                {
                    value: inventoryStatusLabel,
                    icon: 'boxes-stacked',
                    style: 'badge',
                },
            ],
            statusToneMap: {
                in_stock: 'text-green-500',
                low_stock: 'text-yellow-500',
                out_of_stock: 'text-red-500',
                active: 'text-green-500',
                inactive: 'text-gray-400',
            },
        };
    }
}
