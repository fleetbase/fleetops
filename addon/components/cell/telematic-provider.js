import Component from '@glimmer/component';
import { action, get } from '@ember/object';

const DEFAULT_PROVIDER_ICON = '/engines-dist/images/telematics/providers/default.webp';

export default class CellTelematicProviderComponent extends Component {
    get telematic() {
        const resourcePath = this.args.column?.resourcePath;

        if (typeof resourcePath === 'function') {
            const resource = resourcePath(this.args.row, this.args.value, this.args.column);

            return resource ?? null;
        }

        if (typeof resourcePath === 'string') {
            const resource = get(this.args.row, resourcePath);

            return resource ?? null;
        }

        if (this.args.row?.telematic) {
            return this.args.row.telematic;
        }

        if (this.args.row?.telematic_uuid || this.args.row?.telematic_name) {
            return {
                id: this.args.row.telematic_uuid,
                name: this.args.row.telematic_name,
                provider: this.args.row.provider,
                provider_descriptor: this.args.row.provider_descriptor,
            };
        }

        return this.args.row;
    }

    get emptyText() {
        return this.args.column?.emptyText ?? '-';
    }

    get compact() {
        return this.args.column?.compact ?? false;
    }

    get descriptor() {
        return this.telematic?.provider_descriptor ?? this.args.row?.provider_descriptor ?? {};
    }

    get name() {
        return this.telematic?.name ?? this.descriptor.label ?? this.telematic?.provider ?? this.args.row?.provider ?? this.args.row?.telematic_name;
    }

    get description() {
        return this.descriptor.description ?? this.telematic?.provider ?? this.args.row?.provider;
    }

    get icon() {
        return this.descriptor.icon ?? DEFAULT_PROVIDER_ICON;
    }

    @action onClick(event) {
        const { row, column, onClick } = this.args;
        const resource = this.telematic ?? row;

        if (typeof onClick === 'function') {
            onClick(resource, event);
        }

        if (typeof column?.action === 'function') {
            column.action(resource, event);
        }

        if (typeof column?.onClick === 'function') {
            column.onClick(resource, event);
        }
    }
}
