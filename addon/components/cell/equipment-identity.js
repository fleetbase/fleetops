import Component from '@glimmer/component';
import { get } from '@ember/object';
import config from 'ember-get-config';
import { resolveIdentityCellResource } from '../../utils/identity-cell-resource';

export default class CellEquipmentIdentityComponent extends Component {
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
            fallbackImage: config?.defaultValues?.equipmentImage ?? config?.defaultValues?.placeholderImage,
            statusPath: (equipment) => (get(equipment, 'is_equipped') ? 'equipped' : (get(equipment, 'status') ?? 'unequipped')),
            statusFormatter: () => null,
            metaPaths: [
                {
                    value: (equipment) => get(equipment, 'type'),
                    formatter: (type) => type,
                    icon: 'toolbox',
                    style: 'badge',
                },
                {
                    value: (equipment) => get(equipment, 'serial_number') ?? get(equipment, 'code') ?? get(equipment, 'public_id'),
                    icon: 'barcode',
                    style: 'badge',
                    class: 'max-w-[12rem]',
                },
            ],
            statusToneMap: {
                active: 'text-green-500',
                equipped: 'text-green-500',
                available: 'text-green-500',
                maintenance: 'text-yellow-500',
                unequipped: 'text-gray-400',
                inactive: 'text-gray-400',
                retired: 'text-red-500',
            },
        };
    }
}
