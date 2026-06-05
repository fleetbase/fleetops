<?php

test('customer contacts reject existing non-customer users by identity', function () {
    $contactModel  = file_get_contents(__DIR__ . '/../src/Models/Contact.php');
    $conflictClass = file_get_contents(__DIR__ . '/../src/Exceptions/CustomerUserConflictException.php');
    $observer      = file_get_contents(__DIR__ . '/../src/Observers/ContactObserver.php');

    expect($contactModel)
        ->toContain('assertCustomerIdentityIsAvailable')
        ->toContain('assertCustomerUserCanBeAssigned')
        ->toContain("orWhereHas('companyUsers'")
        ->toContain("\$user->type === 'customer'")
        ->toContain('cannot be used for a customer account');

    expect($conflictClass)->toContain('class CustomerUserConflictException extends UserAlreadyExistsException');

    expect($observer)
        ->toContain('$contact->assertCustomerIdentityIsAvailable();')
        ->toContain('$contact->normalizeCustomerUser();');
});

test('customer user assignment no longer promotes staff users', function () {
    $contactModel = file_get_contents(__DIR__ . '/../src/Models/Contact.php');

    expect($contactModel)
        ->toContain('public function assignUser(User $user, bool $sendInvite = false): self')
        ->toContain('$this->assertCustomerUserCanBeAssigned($user);')
        ->not->toContain("if (\$this->isCustomer() && \$user->type !== 'customer') {\n            \$user->setType('customer');");
});

test('customer creation entrypoints run the customer identity guard', function () {
    $apiContact      = file_get_contents(__DIR__ . '/../src/Http/Controllers/Api/v1/ContactController.php');
    $internalContact = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/ContactController.php');
    $orderController = file_get_contents(__DIR__ . '/../src/Http/Controllers/Api/v1/OrderController.php');
    $customerForm    = file_get_contents(__DIR__ . '/../../addon/components/customer/form.hbs');

    expect($apiContact)->toContain('$contactCandidate->assertCustomerIdentityIsAvailable();');
    expect($internalContact)->toContain('$this->assertCustomerIdentityIsAvailable($input');
    expect($orderController)->toContain('$customerCandidate->assertCustomerIdentityIsAvailable();');
    expect($customerForm)->toContain('is_customer=true');
});

test('customer conflict audit command is registered and read only', function () {
    $provider = file_get_contents(__DIR__ . '/../src/Providers/FleetOpsServiceProvider.php');
    $command  = file_get_contents(__DIR__ . '/../src/Console/Commands/AuditCustomerUserConflicts.php');

    expect($provider)->toContain('AuditCustomerUserConflicts::class');
    expect($command)
        ->toContain('fleetops:audit-customer-user-conflicts')
        ->toContain('Reports customer contacts linked to users')
        ->not->toContain('->save(')
        ->not->toContain('->update(')
        ->not->toContain('forceFill');
});

test('customer portal welcome email is opt in and does not create organization invites', function () {
    $internalContact     = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/ContactController.php');
    $internalCustomer    = file_get_contents(__DIR__ . '/../src/Http/Controllers/Internal/v1/CustomerController.php');
    $contactModel        = file_get_contents(__DIR__ . '/../src/Models/Contact.php');
    $customerCredentials = file_get_contents(__DIR__ . '/../src/Mail/CustomerCredentialsMail.php');
    $customerEmail       = file_get_contents(__DIR__ . '/../resources/views/mail/customer-credentials.blade.php');
    $customerForm        = file_get_contents(__DIR__ . '/../../addon/components/customer/form.hbs');

    expect($internalContact)
        ->toContain('meta.customer_portal.send_welcome_email')
        ->toContain('assertCustomerPortalCanSendWelcomeEmail')
        ->toContain('sendCustomerPortalWelcomeEmail')
        ->toContain('Contact::createUserFromContact($contact, false, true)')
        ->toContain('Mail::to($user)->send(new CustomerCredentialsMail($password, $contact))')
        ->toContain("data_forget(\$meta, 'customer_portal.send_welcome_email')")
        ->toContain('fleetbase/customer-portal-api')
        ->not->toContain('UserInvited')
        ->not->toContain('Invite::create');

    expect($internalCustomer)
        ->toContain('Contact::createUserFromContact($customer, false, true)')
        ->not->toContain('send_invite');

    expect($customerCredentials)->toContain('customer portal access is ready')
        ->and($customerCredentials)->toContain("Utils::consoleUrl(\$accessUrlSlug ?: 'customer-portal')")
        ->and($customerEmail)->toContain('Your customer portal access is ready')
        ->and($customerEmail)->toContain('Sign in to customer portal')
        ->and($customerEmail)->toContain('Temporary password:')
        ->and($customerEmail)->toContain('You can change your password after signing in.')
        ->and($customerForm)->toContain('this.showWelcomeEmailOption')
        ->and($customerForm)->toContain('this.toggleWelcomeEmail');

    expect($contactModel)
        ->toContain('assignUserToContactCompany')
        ->toContain('$contact->company->addUser($user, $role)')
        ->toContain('Deprecated and ignored. Contact and customer users do not receive organization invitations.')
        ->not->toContain('UserInvited')
        ->not->toContain('Invite::create')
        ->not->toContain('->assignCompany(');
});
