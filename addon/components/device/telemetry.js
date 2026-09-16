import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { registerDestructor } from '@ember/destroyable';
import { task } from 'ember-concurrency';
import { action, get } from '@ember/object';

export default class DeviceTelemetryComponent extends Component {
    @service socket;
    @tracked clock = Date.now();
    @tracked connectionError = false;
    timer;
    channel;
    reconnect;
    consumer;

    constructor() {
        super(...arguments);
        this.watch.perform();
        this.tick.perform();
        registerDestructor(this, () => this.stop());
    }

    stop() {
        clearTimeout(this.timer);
        this.consumer?.close();
        this.reconnect?.close();
        this.channel?.unsubscribe()?.catch(() => {});
    }

    @action resourceChanged() {
        this.watch.cancelAll();
        this.watchReconnect.cancelAll();
        this.consumer?.close();
        this.reconnect?.close();
        this.channel?.unsubscribe()?.catch(() => {});
        this.watch.perform();
        this.reload.perform();
    }

    get telemetry() {
        return this.args.resource ? (get(this.args.resource, 'meta.telemetry') ?? {}) : {};
    }

    get ageSeconds() {
        const time = Date.parse(this.telemetry.position_at);
        return Number.isFinite(time) ? Math.max(0, Math.floor((this.clock - time) / 1000)) : null;
    }

    get stale() {
        const threshold = this.telemetry.stale_after_seconds ?? (this.telemetry.ignition === true ? 120 : 600);
        return this.ageSeconds === null || this.ageSeconds > threshold;
    }

    get ageLabel() {
        return this.ageSeconds === null ? 'No valid position' : `${this.ageSeconds}s ago`;
    }

    get positionTime() {
        return this.localTime(this.telemetry.position_at);
    }

    get providerTime() {
        return this.localTime(this.telemetry.provider_at);
    }

    localTime(value) {
        const date = new Date(value);
        return Number.isFinite(date.getTime()) ? date.toLocaleString(undefined, { timeZoneName: 'short' }) : 'Unknown';
    }

    @task *tick() {
        while (true) {
            this.clock = Date.now();
            yield new Promise((resolve) => {
                this.timer = setTimeout(resolve, 15000);
            });
        }
    }

    @task({ drop: true }) *reload() {
        try {
            yield this.args.resource?.reload?.();
            this.connectionError = false;
        } catch {
            this.connectionError = true;
        }
    }

    @task *watch() {
        const id = this.args.resource?.id ?? this.args.resource?.uuid;
        if (!id) return;
        try {
            const socket = this.socket.instance();
            this.channel = socket.subscribe(`device.${id}`);
            this.consumer = this.channel.createConsumer();
            this.reconnect = socket.listener('connect').createConsumer();
            this.watchReconnect.perform();
            if (this.channel.state !== 'subscribed') yield this.channel.subscribe();
            while (true) {
                const { value, done } = yield this.consumer.next();
                if (done) break;
                if (value?.event === 'device.telemetry_updated') yield this.reload.perform();
            }
        } catch {
            this.connectionError = true;
        }
    }

    @task *watchReconnect() {
        while (true) {
            const { done } = yield this.reconnect.next();
            if (done) break;
            yield this.reload.perform();
        }
    }
}
