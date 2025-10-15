import Component from '@glimmer/component';

const SIZE_TO_CLASS = {
    xs: 'w-5 h-5', // 20px
    sm: 'w-7 h-7', // 28px
    md: 'w-9 h-9', // 36px
    lg: 'w-12 h-12', // 48px
};

const OVERLAP_TO_CLASS = {
    none: '',
    tight: '-ml-2', // small horizontal overlap
    normal: '-ml-3', // medium
    dense: '-ml-4', // big
};

export default class OrderCustomerAvatarStackComponent extends Component {
    get entries() {
        const list = this.args.waypoints ?? [];
        return list.filter(Boolean).map((wp, idx) => {
            const c = wp.customer ?? {};
            return {
                key: c.public_id ?? c.id ?? `wp-${idx}`,
                name: c.name,
                phone: c.phone,
                address: wp.address,
                photoUrl: c.photo_url,
            };
        });
    }

    get sizeClass() {
        return SIZE_TO_CLASS[this.args.size] ?? SIZE_TO_CLASS.sm;
    }

    get overlapClass() {
        return OVERLAP_TO_CLASS[this.args.overlap] ?? OVERLAP_TO_CLASS.tight;
    }
}
