import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class OrderFormPayloadComponent extends Component {
    @service store;
    @service fetch;
    @service entityActions;
    @service orderCreation;

    get entitiesByImportId() {
        const groups = [];

        this.args.resource.payload.waypoints.forEach((waypoint) => {
            const importId = waypoint.place._import_id ?? null;
            if (importId) {
                const entities = this.args.resource.payload.entities.filter((entity) => entity._import_id === importId);
                const group = {
                    importId,
                    waypoint,
                    entities,
                };

                groups.pushObject(group);
            }
        });

        return groups;
    }

    @action setEntityDestionation(index, { target }) {
        const { value } = target;

        this.args.resource.payload.entities[index].destination_uuid = value;
        this.requestServiceQuoteRefresh('entity.destination.changed');
    }

    @action addFromCustomEntity(customEntity) {
        const entity = this.store.createRecord('entity', {
            ...customEntity,
            id: undefined,
        });

        this.args.resource.payload.entities.pushObject(entity);
        this.requestServiceQuoteRefresh('entity.added');
    }

    @action addEntities(entities = []) {
        if (isArray(entities)) {
            this.args.resource.payload.entities.pushObjects(entities);
            if (entities.length) {
                this.requestServiceQuoteRefresh('entity.batch_added');
            }
        }
    }

    @action addEntity(importId = null) {
        const entity = this.store.createRecord('entity', {
            _import_id: typeof importId === 'string' ? importId : null,
            type: 'entity',
        });

        this.args.resource.payload.entities.pushObject(entity);
        this.requestServiceQuoteRefresh('entity.added');
    }

    @action removeEntity(entity) {
        if (this.args.resource.payload.entities.length === 1) return;

        if (!entity.isNew) {
            return entity.destroyRecord().then(() => {
                this.requestServiceQuoteRefresh('entity.removed');
            });
        }

        this.args.resource.payload.entities.removeObject(entity);
        this.requestServiceQuoteRefresh('entity.removed');
    }

    @action editEntity(entity) {
        this.entityActions.modal.edit(entity, {
            confirm: (modal) => {
                modal.done();
                this.requestServiceQuoteRefresh('entity.edited');
            },
        });
    }

    @action setEntityQuoteField(entity, field, value) {
        this.setEntityValue(entity, field, value);
        this.requestServiceQuoteRefresh(`entity.${field}.changed`);
    }

    @action setEntityCurrency(entity, value) {
        this.setEntityValue(entity, 'currency', value);
        this.requestServiceQuoteRefresh('entity.currency.changed');
    }

    @action setEntityDimensionsUnit(entity, value) {
        this.setEntityValue(entity, 'dimensions_unit', value);
        this.requestServiceQuoteRefresh('entity.dimensions_unit.changed');
    }

    @action setEntityWeightUnit(entity, value) {
        this.setEntityValue(entity, 'weight_unit', value);
        this.requestServiceQuoteRefresh('entity.weight_unit.changed');
    }

    setEntityValue(entity, field, value) {
        if (typeof entity?.set === 'function') {
            entity.set(field, value);
            return;
        }

        entity[field] = value;
    }

    requestServiceQuoteRefresh(reason) {
        this.orderCreation.requestServiceQuoteRefresh(reason, this.args.resource);
    }
}
