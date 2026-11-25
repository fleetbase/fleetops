import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Linking a Navigator app to this instance: the QR code a driver scans, the link
 * to tap on a phone, and sending that link to drivers.
 */
export default class NavigatorAppLinkComponent extends Component {
    @service fetch;
    @service intl;
    @service notifications;

    @tracked link;
    @tracked failed = false;
    @tracked selectedDrivers = [];
    @tracked lastShare;

    constructor() {
        super(...arguments);
        this.load.perform();
    }

    get qrCode() {
        return this.link?.qrCode ?? null;
    }

    get liveUpdates() {
        if (!this.link) {
            return '';
        }

        return `${this.link.socketHost}:${this.link.socketPort}`;
    }

    get hasSelectedDrivers() {
        return this.selectedDrivers.length > 0;
    }

    @task *load() {
        this.failed = false;

        try {
            this.link = yield this.fetch.get('fleet-ops/navigator/get-link-app');
        } catch (error) {
            this.failed = true;
        }
    }

    @action addDriver(driver) {
        if (!driver || this.selectedDrivers.some((selected) => selected.id === driver.id)) {
            return;
        }

        this.selectedDrivers = [...this.selectedDrivers, driver];
    }

    @action removeDriver(driver) {
        this.selectedDrivers = this.selectedDrivers.filter((selected) => selected.id !== driver.id);
    }

    @task *sendToSelected() {
        yield this.share.perform({ drivers: this.selectedDrivers.map((driver) => driver.id) });
    }

    @task *sendToAll() {
        yield this.share.perform({ all: true });
    }

    @task *share(body) {
        try {
            const result = yield this.fetch.post('fleet-ops/navigator/share-link', body);
            const sent = result?.sent?.length ?? 0;
            const failed = result?.failed?.length ?? 0;

            this.lastShare = result;
            this.selectedDrivers = [];

            if (sent > 0) {
                this.notifications.success(this.intl.t('navigator-app-link.sent', { count: sent }));
            }
            if (failed > 0) {
                this.notifications.warning(this.intl.t('navigator-app-link.not-sent', { count: failed }));
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @action downloadQrCode() {
        if (!this.link?.qrCodeSvg) {
            return;
        }

        const blob = new Blob([this.link.qrCodeSvg], { type: 'image/svg+xml' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'navigator-app-link.svg';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        URL.revokeObjectURL(url);
    }

    @action printQrCode() {
        if (!this.link?.qrCodeSvg) {
            return;
        }

        const title = this.intl.t('navigator-app-link.print-title');
        const hint = this.intl.t('navigator-app-link.print-hint');
        const printWindow = window.open('', '_blank', 'width=640,height=820');
        if (!printWindow) {
            return;
        }

        printWindow.document.write(
            `<!doctype html><html><head><title>${title}</title><style>body{font-family:system-ui,sans-serif;text-align:center;padding:48px}svg{width:360px;height:360px}h1{font-size:28px;margin:0 0 8px}p{font-size:16px;color:#444;margin:0 0 32px}</style></head><body><h1>${title}</h1><p>${hint}</p>${this.link.qrCodeSvg}</body></html>`
        );
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    }
}
