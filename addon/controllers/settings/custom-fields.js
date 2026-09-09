import Controller from '@ember/controller';
import { inject as service } from '@ember/service';

export default class SettingsCustomFieldsController extends Controller {
    @service intl;
    get subjects() {
        return [
            {
                model: 'driver',
                type: 'fleet-ops:driver',
                label: 'Driver',
                groups: [],
            },
            {
                model: 'vehicle',
                type: 'fleet-ops:vehicle',
                label: 'Vehicle',
                groups: [],
            },
            {
                model: 'trailer',
                type: 'fleet-ops:trailer',
                label: this.intl.t('resource.trailer'),
                groups: [],
            },
            {
                model: 'contact',
                type: 'fleet-ops:contact',
                label: 'Contact',
                groups: [],
            },
            {
                model: 'vendor',
                type: 'fleet-ops:vendor',
                label: 'Vendor',
                groups: [],
            },
            {
                model: 'place',
                type: 'fleet-ops:place',
                label: 'Place',
                groups: [],
            },
            {
                model: 'entity',
                type: 'fleet-ops:entity',
                label: 'Entity',
                groups: [],
            },
            {
                model: 'fleet',
                type: 'fleet-ops:fleet',
                label: 'Fleet',
                groups: [],
            },
            {
                model: 'issue',
                type: 'fleet-ops:issue',
                label: 'Issue',
                groups: [],
            },
            {
                model: 'fuel-report',
                type: 'fleet-ops:fuel-report',
                label: 'Fuel Report',
                groups: [],
            },
        ];
    }
}
