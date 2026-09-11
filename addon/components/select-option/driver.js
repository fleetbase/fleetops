import Component from '@glimmer/component';
import { get } from '@ember/object';
import config from 'ember-get-config';

/**
 * A driver as a select option: photo, then name over phone and email. Takes
 * the record as `@option` or `@model`, like `SelectOption::User`.
 */
export default class SelectOptionDriverComponent extends Component {
    get driver() {
        return this.args.option ?? this.args.model ?? null;
    }

    get fallbackPhoto() {
        return get(config, 'defaultValues.driverImage');
    }

    get title() {
        return this.driver?.name || this.driver?.public_id;
    }

    get details() {
        return [this.driver?.phone, this.driver?.email];
    }
}
