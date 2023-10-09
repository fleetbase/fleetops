import Controller from '@ember/controller';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';

export default class OperationsOrdersIndexConfigTypesController extends Controller {
    @tracked configurations = [];
    @tracked isLoaded = false;

    @computed('isLoaded', 'configurations.length') get noConfigsInstalled() {
        return this.configurations.length === 0 && this.isLoaded === true;
    }

    @action setConfigurations(configurations = []) {
        this.configurations = configurations;
        this.isLoaded = true;
    }
}
