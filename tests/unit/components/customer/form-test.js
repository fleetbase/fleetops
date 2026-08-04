import { module, test } from 'qunit';
import CustomerFormComponent from 'dummy/components/customer/form';

function makeResource(initial = {}) {
    return {
        ...initial,
        set(key, value) {
            this[key] = value;
        },
    };
}

function makeForm(resource, { installedExtensions = [] } = {}) {
    return Object.create(CustomerFormComponent.prototype, {
        args: { value: { resource } },
        extensionManager: {
            value: {
                isInstalled: (name) => installedExtensions.includes(name),
            },
        },
    });
}

module('Unit | Component | customer/form', function () {
    test('the welcome email option is offered when creating a customer with the portal installed', function (assert) {
        const component = makeForm(makeResource({ isNew: true }), { installedExtensions: ['@fleetbase/customer-portal-engine'] });

        assert.true(component.showWelcomeEmailOption);
    });

    test('the welcome email option is hidden without the customer portal extension', function (assert) {
        const component = makeForm(makeResource({ isNew: true }));

        assert.false(component.showWelcomeEmailOption);
    });

    test('the welcome email option is hidden when editing an existing customer', function (assert) {
        const component = makeForm(makeResource({ isNew: false }), { installedExtensions: ['@fleetbase/customer-portal-engine'] });

        assert.false(component.showWelcomeEmailOption);
    });

    test('the welcome email is opt in and defaults to off', function (assert) {
        assert.false(makeForm(makeResource({ isNew: true })).sendWelcomeEmail);
        assert.false(makeForm(makeResource({ isNew: true, meta: {} })).sendWelcomeEmail);
        assert.true(makeForm(makeResource({ meta: { customer_portal: { send_welcome_email: true } } })).sendWelcomeEmail);
    });

    test('toggling the welcome email writes the opt in flag without dropping other meta', function (assert) {
        const resource = makeResource({ isNew: true, meta: { customer_portal: { access_url_slug: 'acme' }, source: 'console' } });
        const component = makeForm(resource, { installedExtensions: ['@fleetbase/customer-portal-engine'] });

        component.toggleWelcomeEmail(true);

        assert.deepEqual(resource.meta, {
            source: 'console',
            customer_portal: {
                access_url_slug: 'acme',
                send_welcome_email: true,
            },
        });
        assert.true(component.sendWelcomeEmail);

        component.toggleWelcomeEmail(false);

        assert.false(component.sendWelcomeEmail);
        assert.strictEqual(resource.meta.customer_portal.access_url_slug, 'acme');
    });

    test('toggling without an argument flips the current opt in value', function (assert) {
        const resource = makeResource({ isNew: true });
        const component = makeForm(resource, { installedExtensions: ['@fleetbase/customer-portal-engine'] });

        component.toggleWelcomeEmail();

        assert.true(component.sendWelcomeEmail);
    });
});
