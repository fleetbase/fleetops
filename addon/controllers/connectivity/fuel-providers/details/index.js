import Controller from '@ember/controller';
import { inject as controller } from '@ember/controller';

export default class FuelIntegrationOverviewController extends Controller {
    @controller('connectivity.fuel-providers.details') details;
}
