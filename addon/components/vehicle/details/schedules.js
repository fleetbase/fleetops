import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class VehicleDetailsSchedulesComponent extends Component {
    @service('maintenance-schedule-actions') scheduleActions;
}
