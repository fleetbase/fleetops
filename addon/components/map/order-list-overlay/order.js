import Component from '@glimmer/component';
import { action } from '@ember/object';
import { tracked } from '@glimmer/tracking';

export default class MapOrderListOverlayOrderComponent extends Component {
    @tracked trackerDataLoaded = false;
    observer = null;
    @action onClick(order, event) {
        //Don't run callback if action button is clicked
        if (event.target.closest('span.order-listing-action-button')) {
            event.stopPropagation();
            event.preventDefault();
            return;
        }

        if (typeof this.args.onClick === 'function') {
            this.args.onClick(...arguments);
        }
    }

    @action onDoubleClick() {
        if (typeof this.args.onDoubleClick === 'function') {
            this.args.onDoubleClick(...arguments);
        }
    }

    @action onMouseEnter() {
        if (typeof this.args.onMouseEnter === 'function') {
            this.args.onMouseEnter(...arguments);
        }
    }

    @action onMouseLeave() {
        if (typeof this.args.onMouseLeave === 'function') {
            this.args.onMouseLeave(...arguments);
        }
    }

    @action setupIntersectionObserver(element) {
        // Create IntersectionObserver to detect when order becomes visible
        this.observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && !this.trackerDataLoaded) {
                        this.loadTrackerData();
                    }
                });
            },
            {
                root: null, // viewport
                rootMargin: '50px', // start loading slightly before visible
                threshold: 0.1, // trigger when 10% visible
            }
        );

        this.observer.observe(element);
    }

    loadTrackerData() {
        const { order } = this.args;
        
        if (order && typeof order.loadTrackerData === 'function' && !this.trackerDataLoaded) {
            this.trackerDataLoaded = true;
            order.loadTrackerData();
        }
    }

    willDestroy() {
        super.willDestroy(...arguments);
        
        // Clean up observer
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }
}
