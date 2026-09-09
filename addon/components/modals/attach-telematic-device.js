import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class ModalsAttachTelematicDeviceComponent extends Component {
    @service intl;

    get assetTypeOptions() {
        return [
            { value: 'fleet-ops:vehicle', model: 'vehicle', label: this.intl.t('resource.vehicle') },
            { value: 'fleet-ops:trailer', model: 'trailer', label: this.intl.t('resource.trailer') },
        ];
    }
}
