import { helper } from '@ember/component/helper';
import fleetOpsOptions from '../utils/fleet-ops-options';

export function getFleetOpsOptionLabel(optionsKey, value) {
    const allOptions = fleetOpsOptions(optionsKey);
    return allOptions.find((opt) => opt.value === value)?.label ?? null;
}

export default helper(function getFleetOpsOptionLabelHelper([optionsKey, value]) {
    return getFleetOpsOptionLabel(optionsKey, value);
});
