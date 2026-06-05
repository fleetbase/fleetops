import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

export default class VendorPersonnelManagerComponent extends Component {
    @service fetch;
    @service notifications;

    @tracked personnels = [];
    @tracked selectedContact;
    @tracked mode = 'existing';
    @tracked name;
    @tracked email;
    @tracked phone;
    @tracked role = 'member';
    @tracked createLogin = true;

    roleOptions = [
        {
            label: 'Admin',
            value: 'admin',
            description: 'Can manage vendor account settings and personnel.',
        },
        {
            label: 'Member',
            value: 'member',
            description: 'Can access the vendor customer workspace.',
        },
    ];

    constructor(owner, args) {
        super(owner, args);
        this.loadPersonnel.perform();
    }

    get selectedRole() {
        return this.roleOptions.find((option) => option.value === this.role);
    }

    get isAddingExistingContact() {
        return this.mode === 'existing';
    }

    get isCreatingContact() {
        return this.mode === 'new';
    }

    get hasPersonnel() {
        return this.personnels.length > 0;
    }

    get canSubmit() {
        if (this.isAddingExistingContact) {
            return this.selectedContact;
        }

        return this.name && this.email;
    }

    @action setMode(mode) {
        this.mode = mode;
        this.clearForm();
    }

    @action selectContact(contact) {
        this.selectedContact = contact;
    }

    @action selectRole(role) {
        this.role = role?.value ?? 'member';
    }

    @action toggleCreateLogin(value) {
        this.createLogin = value;
    }

    clearForm() {
        this.selectedContact = null;
        this.name = '';
        this.email = '';
        this.phone = '';
        this.role = 'member';
        this.createLogin = true;
    }

    @task *loadPersonnel() {
        try {
            const response = yield this.fetch.get(`vendors/${this.args.vendor.id}/personnels`, {}, { namespace: 'int/v1' });
            this.personnels = response.personnels ?? [];
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *addPersonnel(event) {
        if (event instanceof Event) {
            event.preventDefault();
        }

        if (!this.canSubmit) {
            this.notifications.warning(this.isAddingExistingContact ? 'Select a contact to add.' : 'Name and email are required.');
            return;
        }

        try {
            const payload = {
                role: this.role,
                create_login: this.createLogin,
            };

            if (this.isAddingExistingContact) {
                payload.contact = this.selectedContact?.public_id ?? this.selectedContact?.uuid ?? this.selectedContact?.id;
            } else {
                payload.name = this.name;
                payload.email = this.email;
                payload.phone = this.phone;
            }

            const response = yield this.fetch.post(`vendors/${this.args.vendor.id}/personnels`, payload, { namespace: 'int/v1' });
            const personnel = response.personnel;
            if (personnel) {
                this.personnels = [...this.personnels.filter((item) => item.contact_uuid !== personnel.contact_uuid), personnel];
            }

            this.clearForm();
            this.notifications.success('Personnel added.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    @task *removePersonnel(personnel) {
        try {
            yield this.fetch.delete(`vendors/${this.args.vendor.id}/personnels/${personnel.id}`, {}, { namespace: 'int/v1' });
            this.personnels = this.personnels.filter((item) => item.id !== personnel.id);
            this.notifications.success('Personnel removed.');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }
}
