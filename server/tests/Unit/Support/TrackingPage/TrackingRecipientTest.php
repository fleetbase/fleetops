<?php

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingRecipient;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Database\Eloquent\Model as EloquentModel;

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
});

test('a scope finds its contact or vendor and the details a code can use', function () {
    $db = TrackingPageDatabase::boot();
    TrackingPageDatabase::insert($db, 'contacts', ['uuid' => 'contact-1', 'email' => 'maya@example.com', 'phone' => '+1 (510) 555-4821']);
    TrackingPageDatabase::insert($db, 'vendors', ['uuid' => 'vendor-1', 'email' => 'not-an-email', 'phone' => '12']);

    $contact = TrackingRecipient::for(new TrackingScope('order-1', Contact::class, 'contact-1'));
    expect($contact->customer?->uuid)->toBe('contact-1')
        ->and($contact->email)->toBe('maya@example.com')
        ->and($contact->phone)->toBe('+1 (510) 555-4821')
        ->and($contact->destination(TrackingRecipient::CHANNEL_SMS))->toBe('+1 (510) 555-4821')
        ->and($contact->destination(TrackingRecipient::CHANNEL_EMAIL))->toBe('maya@example.com')
        ->and($contact->destination('fax'))->toBeNull();

    // Unusable details are dropped, and the stop's place phone stands in for a missing one.
    $vendor = TrackingRecipient::for(new TrackingScope('order-1', Vendor::class, 'vendor-1'), '+65 6555 1234');
    expect($vendor->customer?->uuid)->toBe('vendor-1')
        ->and($vendor->email)->toBeNull()
        ->and($vendor->phone)->toBe('+65 6555 1234');

    $unknown = TrackingRecipient::for(new TrackingScope('order-1', Place::class, 'place-1'));
    expect($unknown->customer)->toBeNull()
        ->and($unknown->email)->toBeNull()
        ->and($unknown->phone)->toBeNull()
        ->and(TrackingRecipient::for(null)->customer)->toBeNull();
});

test('channels follow the settings and the instance, with masked destinations', function () {
    $recipient = new TrackingRecipient(null, 'maya.chen@gmail.com', '+15105554821');

    expect($recipient->channels([], true))->toBe([
        ['type' => 'sms', 'masked' => '•••• •• 4821'],
        ['type' => 'email', 'masked' => 'm•••@g•••.com'],
    ])
        ->and($recipient->channels([], false))->toBe([['type' => 'email', 'masked' => 'm•••@g•••.com']])
        ->and($recipient->channels(['access' => ['channels' => ['sms' => false, 'email' => false]]], true))->toBe([])
        ->and(TrackingRecipient::maskEmail('ops@localhost'))->toBe('o•••@l•••');
});
