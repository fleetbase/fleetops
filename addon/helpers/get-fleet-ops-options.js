import { helper } from '@ember/component/helper';
import fleetOpsOptions from '../utils/fleet-ops-options';

export default helper(function getFleetOpsOptions([key]) {
    return fleetOpsOptions(key);
});
