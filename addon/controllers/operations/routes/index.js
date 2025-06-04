import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';

export default class OperationsRoutesIndexController extends Controller {
    @tracked leafletMap;
    @tracked liveMap;

    @action setMapReference({ target }) {
        this.leafletMap = target;
        this.liveMap = target.liveMap;
    }
}
