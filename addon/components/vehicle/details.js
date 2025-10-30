import Component from '@glimmer/component';
import { inject as service } from '@ember/service';

export default class VehicleDetailsComponent extends Component {
    @service resourceMetadata;

    get metadataButtons() {
        return [
            {
                type: 'default',
                text: 'Edit',
                icon: 'pencil',
                iconPrefix: 'fas',
                permission: 'fleet-ops update vehicle',
                onClick: () => {
                    this.resourceMetadata.edit(this.args.resource);
                },
            },
        ];
    }
}
