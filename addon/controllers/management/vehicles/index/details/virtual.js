import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';

export default class ManagementVehiclesIndexDetailsVirtualController extends Controller {
    @tracked view;
    queryParams = ['view'];
}
