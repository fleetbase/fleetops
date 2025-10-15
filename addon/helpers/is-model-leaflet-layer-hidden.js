import Helper from '@ember/component/helper';
import { inject as service } from '@ember/service';

export default class IsModelLeafletLayerHidden extends Helper {
    @service leafletLayerVisibilityManager;

    compute([model]) {
        return this.leafletLayerVisibilityManager.isModelLayerHidden(model);
    }
}
