import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';

export default class ConnectivitySensorsIndexDetailsVirtualController extends Controller {
    @tracked view;
    queryParams = ['view'];
}
