import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';

export default class OrderFormPayloadComponent extends Component {
    @service store;
    @service fetch;
    @service entityActions;

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
    }

    @action addFromCustomEntity(customEntity) {
        const entity = this.store.createRecord('entity', {
            ...customEntity,
            id: undefined,
        });

        this.args.resource.payload.entities.pushObject(entity);
    }

    @action addEntities(entities = []) {
        if (isArray(entities)) {
            this.args.resource.payload.entities.pushObjects(entities);
        }
    }

    @action addEntity(importId = null) {
        const entity = this.store.createRecord('entity', {
            _import_id: typeof importId === 'string' ? importId : null,
        });

        this.args.resource.payload.entities.pushObject(entity);
    }

    @action removeEntity(entity) {
        if (this.args.resource.payload.entities.length === 1) return;

        if (!entity.isNew) {
            return entity.destroyRecord();
        }

        this.args.resource.payload.entities.removeObject(entity);
    }

    @action editEntity(entity) {
        this.entityActions.modal.edit(entity);
    }
}
