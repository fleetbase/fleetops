<?php

namespace Illuminate\Foundation\Auth {
    if (!class_exists(User::class, false)) {
        class User extends \Illuminate\Database\Eloquent\Model
        {
        }
    }
}

namespace {
    use Fleetbase\FleetOps\Exceptions\CustomerUserConflictException;
    use Fleetbase\FleetOps\Exceptions\IntegratedVendorException;
    use Fleetbase\FleetOps\Exceptions\TelematicProviderException;
    use Fleetbase\FleetOps\Exceptions\TelematicRateLimitExceededException;
    use Fleetbase\FleetOps\Exceptions\UserAlreadyExistsException;
    use Fleetbase\FleetOps\Models\IntegratedVendor;
    use Fleetbase\Models\User;

    test('telematic provider exceptions expose provider context', function () {
        $previous  = new RuntimeException('transport failed');
        $exception = new TelematicProviderException(
            'Provider failed',
            ['endpoint' => '/devices', 'retryable' => true],
            503,
            $previous,
        );

        expect($exception->getMessage())->toBe('Provider failed')
            ->and($exception->getCode())->toBe(503)
            ->and($exception->getPrevious())->toBe($previous)
            ->and($exception->context())->toBe(['endpoint' => '/devices', 'retryable' => true]);
    });

    test('rate limit exceptions use the telematic provider exception contract', function () {
        $exception = new TelematicRateLimitExceededException('Rate limit exceeded', ['limit' => 60]);

        expect($exception)->toBeInstanceOf(TelematicProviderException::class)
            ->and($exception->context())->toBe(['limit' => 60]);
    });

    test('user already exists exceptions retain the duplicate user context', function () {
        $user = new User();
        $user->setRawAttributes([
            'uuid'  => 'user-1',
            'email' => 'customer@example.test',
        ], true);

        $exception = new UserAlreadyExistsException('User exists', $user, 409);

        expect($exception->getMessage())->toBe('User exists')
            ->and($exception->getCode())->toBe(409)
            ->and($exception->getUser())->toBe($user);
    });

    test('customer conflict exceptions reuse duplicate user context', function () {
        $user      = new User();
        $exception = new CustomerUserConflictException('Customer user conflict', $user);

        expect($exception)->toBeInstanceOf(UserAlreadyExistsException::class)
            ->and($exception->getUser())->toBe($user);
    });

    test('integrated vendor exceptions serialize the error response payload', function () {
        $vendor = new IntegratedVendor();
        $vendor->setRawAttributes([
            'uuid' => 'vendor-1',
        ], true);

        $exception = new IntegratedVendorException('Vendor trigger failed', $vendor, 'quote');
        $response  = $exception->toResponse(null);

        expect($exception->integratedVendor)->toBe($vendor)
            ->and($exception->triggerMethod)->toBe('quote')
            ->and($response->getStatusCode())->toBe(400)
            ->and($response->getData(true))->toBe([
                'errors'             => ['Vendor trigger failed'],
                'integratedVendorId' => 'vendor-1',
            ]);
    });
}
