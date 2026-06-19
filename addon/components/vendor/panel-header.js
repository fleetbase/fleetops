import Component from '@glimmer/component';
import { get } from '@ember/object';

export default class VendorPanelHeaderComponent extends Component {
    get resource() {
        return this.args.resource;
    }

    get imageUrl() {
        return get(this.resource, 'photo_url') ?? get(this.resource, 'logo_url');
    }

    get name() {
        return get(this.resource, 'name') ?? get(this.resource, 'displayName') ?? get(this.resource, 'business_id') ?? get(this.resource, 'public_id') ?? '-';
    }

    get status() {
        return get(this.resource, 'status') ?? 'active';
    }

    get businessId() {
        return get(this.resource, 'business_id') ?? get(this.resource, 'internal_id') ?? get(this.resource, 'public_id');
    }

    get type() {
        return get(this.resource, 'type');
    }

    get email() {
        return get(this.resource, 'email');
    }

    get phone() {
        return get(this.resource, 'phone');
    }

    get country() {
        return get(this.resource, 'country');
    }

    get address() {
        return get(this.resource, 'address_street') ?? get(this.resource, 'address');
    }

    get website() {
        return get(this.resource, 'website_url');
    }
}
