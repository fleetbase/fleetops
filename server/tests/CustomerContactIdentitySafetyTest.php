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
