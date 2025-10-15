import Component from '@glimmer/component';

export default class TelematicFormComponent extends Component {
    /**
     * Status options for telematic devices
     */
    statusOptions = ['active', 'inactive', 'maintenance', 'retired'];
}
