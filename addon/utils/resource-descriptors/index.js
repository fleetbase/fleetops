import buildAssetDescriptors from './assets';
import buildPeopleDescriptors from './people';
import buildConnectivityDescriptors from './connectivity';
import buildMaintenanceDescriptors from './maintenance';
import buildOperationsDescriptors from './operations';
import buildFuelDescriptors from './fuel';
import buildSubRecordDescriptors from './sub-records';
import buildPolymorphicDescriptors from './polymorphic';

/**
 * Every FleetOps resource descriptor. Model names that are only polymorphic
 * subtypes of another model (attachable-vehicle, facilitator-driver,
 * customer-contact, …) are aliases on the concrete descriptor rather than
 * descriptors of their own.
 */
export function buildFleetOpsResourceDescriptors(owner) {
    return [
        ...buildAssetDescriptors(owner),
        ...buildPeopleDescriptors(owner),
        ...buildConnectivityDescriptors(owner),
        ...buildMaintenanceDescriptors(owner),
        ...buildOperationsDescriptors(owner),
        ...buildFuelDescriptors(owner),
        ...buildSubRecordDescriptors(owner),
        ...buildPolymorphicDescriptors(owner),
    ].map((descriptor) => ({
        ...descriptor,
        components: {
            identity: `cell/${descriptor.key}-identity`,
            ...(descriptor.components ?? {}),
        },
    }));
}

export default buildFleetOpsResourceDescriptors;
