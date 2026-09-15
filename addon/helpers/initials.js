import { helper } from '@ember/component/helper';
import { initialsOf } from '../utils/radar';

export default helper(function initials([name]) {
    return initialsOf(name);
});
