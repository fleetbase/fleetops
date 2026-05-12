import Component from '@glimmer/component';

export default class TrackingStopProgressComponent extends Component {
    get stops() {
        const stops = this.args.stops ?? [];
        const activeStop = this.args.activeStop;

        return stops.map((stop, index) => {
            const isActive = this.matches(stop, activeStop);
            const completed = Boolean(stop.completed);

            return {
                ...stop,
                index: index + 1,
                isFirst: index === 0,
                isLast: index === stops.length - 1,
                label: this.labelFor(stop, index),
                title: this.titleFor(stop, index),
                locationLabel: this.locationLabelFor(stop, index),
                completed,
                active: isActive,
                pending: !completed && !isActive,
            };
        });
    }

    get completedCount() {
        return this.stops.filter((stop) => stop.completed).length;
    }

    get totalCount() {
        return this.stops.length;
    }

    labelFor(stop, index) {
        if (stop.type === 'pickup') {
            return 'P';
        }

        if (stop.type === 'dropoff') {
            return 'D';
        }

        return String(index + 1);
    }

    titleFor(stop, index) {
        if (stop.type === 'pickup') {
            return 'Pickup';
        }

        if (stop.type === 'dropoff') {
            return 'Dropoff';
        }

        return `Stop ${index + 1}`;
    }

    locationLabelFor(stop, index) {
        return stop.city || stop.name || stop.address || this.titleFor(stop, index);
    }

    matches(stop, activeStop) {
        if (!stop || !activeStop) {
            return false;
        }

        return stop.uuid === activeStop.uuid || stop.public_id === activeStop.public_id;
    }
}
