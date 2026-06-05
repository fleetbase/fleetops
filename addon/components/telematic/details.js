import Component from '@glimmer/component';

export default class TelematicDetailsComponent extends Component {
    get webhookUrl() {
        const url = this.args.resource?.provider_descriptor?.webhook_url;
        const id = this.args.resource?.public_id ?? this.args.resource?.id;

        if (!url || !id) {
            return url;
        }

        const separator = url.includes('?') ? '&' : '?';
        return `${url}${separator}telematic=${id}`;
    }
}
