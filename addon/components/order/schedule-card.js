import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class OrderScheduleCardComponent extends Component {
    @service contextPanel;
    @service modalsManager;
    @tracked isAssigningDriver;

    constructor(owner, { order }) {
        super(...arguments);

        this.loadDriverFromOrder();
        this.loadPayloadFromOrder();
    }

    @action onClickDriver(driver) {
        this.contextPanel.focus(driver);
    }

    @action onClickVehicle(vehicle) {
        this.contextPanel.focus(vehicle);
    }

    @action startAssignDriver() {
        this.isAssigningDriver = true;
    }

    @action assignDriver(driver) {
        const order = this.args.order;

        return this.modalsManager.confirm({
            title: 'Assign New Driver?',
            body: `You are about to assign a new driver (${driver.name}) to Order ${order.public_id}. Click continue to confirm driver.`,
            acceptButtonText: 'Continue and assign driver',
            confirm: () => {
                order.set('driver_assigned_uuid', driver.id);
                return driver
                    .save()
                    .catch((error) => {
                        this.notifications.serverError(error);
                    })
                    .finally(() => {
                        this.isAssigningDriver = false;
                    });
            },
            decline: () => {
                this.isAssigningDriver = false;
            },
        });
    }

    @action onTitleClick(order) {
        const { onTitleClick } = this.args;

        if (typeof onTitleClick === 'function') {
            onTitleClick(order);
        }
    }

    loadDriverFromOrder(order) {
        if (order && typeof order.loadDriver === 'function') {
            order.loadDriver();
        }
    }

    loadPayloadFromOrder(order) {
        if (order && typeof order.loadPayload === 'function') {
            order.loadPayload();
        }
    }
}
