import Component from '@glimmer/component';
import { get } from '@ember/object';
import config from 'ember-get-config';

/**
 * A user as a select option: photo, then name over email and phone. Takes the
 * record as `@option`, which is how PowerSelect hands a `@selectedItemComponent`
 * its selection, or as `@model` inside an option block.
 */
export default class SelectOptionUserComponent extends Component {
    get user() {
        return this.args.option ?? this.args.model ?? null;
    }

    get fallbackPhoto() {
        return get(config, 'defaultValues.userImage');
    }

    get title() {
        return this.user?.name || this.user?.email || this.user?.public_id;
    }

    get details() {
        return [this.user?.email, this.user?.phone];
    }
}
