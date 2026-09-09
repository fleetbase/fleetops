import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { get } from '@ember/object';

/**
 * Table cell that renders a stable enum value (`dry_van`, `never_connected`, ...)
 * through its localized label. Configure the column with:
 *
 *   translationPrefix: 'trailer.types'   // required, joined with the raw value
 *   badge: true                          // optional, render as a status badge
 *   badgeIconPath / badgeIcons           // optional badge icon per value
 */
export default class CellTranslatedValueComponent extends Component {
    @service intl;

    get value() {
        const row = this.args.row;
        const valuePath = this.args.column?.valuePath;

        if (this.args.value !== undefined && this.args.value !== null) {
            return this.args.value;
        }

        return valuePath ? get(row, valuePath) : null;
    }

    get label() {
        const value = this.value;
        const prefix = this.args.column?.translationPrefix;

        if (value === null || value === undefined || value === '') {
            return null;
        }

        if (!prefix) {
            return String(value);
        }

        const key = `${prefix}.${value}`;

        return this.intl.exists(key) ? this.intl.t(key) : String(value);
    }

    get isBadge() {
        return Boolean(this.args.column?.badge);
    }

    get icon() {
        const icons = this.args.column?.badgeIcons;

        return icons && this.value ? icons[this.value] : undefined;
    }

    get emptyText() {
        return this.args.column?.emptyText ?? '-';
    }
}
