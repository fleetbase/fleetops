import Component from '@glimmer/component';
import { get } from '@ember/object';

/**
 * Renders a has-many (or any array-like value) as a row of pills. Reads the
 * column's `resourcePath` when given, otherwise the cell value, otherwise the
 * row attribute named by `valuePath`; proxies and ManyArrays are unwrapped.
 */
export default class CellResourceListComponent extends Component {
    get items() {
        const { column = {}, row, value } = this.args;
        let list = typeof column.resourcePath === 'function' ? column.resourcePath(row, value, column) : (value ?? (column.valuePath ? get(row ?? {}, column.valuePath) : null));

        if (list && typeof list === 'object' && 'content' in list && typeof list.then === 'function') {
            list = list.content;
        }

        if (!list) {
            return [];
        }

        if (typeof list.slice === 'function') {
            return Array.from(list.slice()).filter(Boolean);
        }

        return Array.isArray(list) ? list.filter(Boolean) : [list];
    }
}
