<?php

use Fleetbase\FleetOps\Http\Controllers\Public\TrackingController;
use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Proof;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingAccess;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingPageConfig;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingResolver;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingTarget;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingView;
use Fleetbase\FleetOps\Support\TrackingScope;
use Fleetbase\Models\VerificationCode;
use Fleetbase\Tests\Support\TrackingPageDatabase;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class TrackingControllerResolverFake extends TrackingResolver
{
    public ?TrackingTarget $target = null;
    public ?string $slugCompany   = null;
    public array $company         = [];
    public array $admin           = [];
    public array $resolved        = [];

    public function resolve(mixed $input, ?string $slug = null): ?TrackingTarget
    {
        $this->resolved[] = [$input, $slug];

        return $this->target;
    }

    public function companyForSlug(?string $slug): ?string
    {
        return $this->slugCompany;
    }

    public function config(?string $companyUuid): array
    {
        return TrackingPageConfig::sanitize($this->company);
    }

    public function adminConfig(): array
    {
        return TrackingPageConfig::sanitizeAdmin($this->admin);
    }
}

class TrackingControllerAccessFake extends TrackingAccess
{
    public bool $lookup      = true;
    public bool $verify      = true;
    public bool $reportOk    = true;
    public int $paused       = 0;
    public array $claim      = ['status' => self::SEND_SENT, 'retry_after' => 30];
    public array $check      = ['status' => VerificationCode::CHECK_VALID, 'channel' => 'sms'];
    public bool $granted     = false;
    public array $issued     = [];
    public array $grants     = [];
    public array $revoked    = [];

    public function allowLookup(Request $request): bool
    {
        return $this->lookup;
    }

    public function allowVerify(Request $request): bool
    {
        return $this->verify;
    }

    public function allowReport(Request $request, string $orderUuid): bool
    {
        return $this->reportOk;
    }

    public function pausedFor(TrackingScope $scope): int
    {
        return $this->paused;
    }

    public function claimSend(TrackingScope $scope, Request $request): array
    {
        return $this->claim;
    }

    public function issueCode(TrackingScope $scope, mixed $recipient, string $channel): VerificationCode
    {
        $this->issued[]   = [$scope->key(), $recipient?->uuid, $channel];
        $code             = new VerificationCode();
        $code->plainCode  = '482193';

        return $code;
    }

    public function checkCode(TrackingScope $scope, string $code): array
    {
        return $this->check + ['code' => $code];
    }

    public function hasGrant(Request $request, ?TrackingScope $scope): bool
    {
        return $this->granted && $scope !== null;
    }

    public function grantExpiresAt(Request $request, TrackingScope $scope): ?string
    {
        return '2026-10-09T09:00:00.000000Z';
    }

    public function grant(Request $request, TrackingScope $scope, ?string $channel, int $hours): ?Cookie
    {
        $this->grants[] = [$scope->key(), $channel, $hours];

        return $this->cookie('token-value-token-value-token-value-xx', $hours * 60);
    }

    public function revoke(Request $request, ?TrackingScope $scope = null): ?Cookie
    {
        $this->revoked[] = $scope?->key();

        return $scope ? null : $this->cookie('', -1);
    }
}

class TrackingControllerViewFake extends TrackingView
{
    public ?Proof $proof = null;
    public array $calls  = [];

    public function publicView(TrackingTarget $target, array $branding, array $channels, bool $signIn): array
    {
        return ['level' => 0, 'branding' => $branding, 'channels' => $channels, 'sign_in' => $signIn];
    }

    public function fullView(TrackingTarget $target, array $branding, string $viewer, ?string $expiresAt = null): array
    {
        $this->calls[] = $viewer;

        return ['level' => $viewer === self::VIEWER_ACCOUNT ? 2 : 1, 'viewer' => $viewer, 'expires_at' => $expiresAt, 'company' => $branding];
    }

    public function proofFor(TrackingTarget $target, string $publicId): ?Proof
    {
        return $this->proof;
    }
}

class TrackingControllerProbe extends TrackingController
{
    public mixed $user           = null;
    public array $sms            = [];
    public array $mails          = [];
    public array $providers      = [];
    public bool $portal          = true;
    public bool $sockets         = false;
    public ?array $contactsOver  = null;

    public function __construct(public TrackingControllerResolverFake $fakeResolver, public TrackingControllerAccessFake $fakeAccess, public TrackingControllerViewFake $fakeView)
    {
        parent::__construct($fakeResolver, $fakeAccess, $fakeView);
    }

    protected function requestUser(Request $request): mixed
    {
        return $this->user;
    }

    protected function smsProviders(): array
    {
        return $this->providers;
    }

    protected function customerPortalInstalled(): bool
    {
        return $this->portal;
    }

    protected function instanceLogo(): ?string
    {
        return 'https://instance.example/logo.png';
    }

    protected function smsService(): Fleetbase\Services\SmsService
    {
        $probe = $this;

        return new class($probe) extends Fleetbase\Services\SmsService {
            public function __construct(private $probe)
            {
            }

            public function send(string $to, string $text, array $options = [], ?string $provider = null): array
            {
                $this->probe->sms[] = [$to, $text, $options];

                return ['success' => true];
            }
        };
    }

    protected function mail(string $to, string $subject, string $body): void
    {
        $this->mails[] = [$to, $subject, $body];
    }

    protected function socketsEnabled(): bool
    {
        return $this->sockets && parent::socketsEnabled() === false;
    }

    protected function socketTokenFor(TrackingScope $scope): array
    {
        return ['token' => 'jwt', 'expires_in' => 1800, 'expires_at' => '2026-10-08T09:30:00Z'];
    }
}

class TrackingControllerOrder extends Order
{
    public bool $saved = false;

    public function saveQuietly(array $options = [])
    {
        $this->saved = true;

        return true;
    }
}

afterEach(function () {
    EloquentModel::unsetConnectionResolver();
    config(['services.twilio.sid' => null, 'services.twilio.token' => null]);
});

function trackingController(): TrackingControllerProbe
{
    TrackingPageDatabase::boot();

    return new TrackingControllerProbe(new TrackingControllerResolverFake(), new TrackingControllerAccessFake(), new TrackingControllerViewFake());
}

function trackingControllerTarget(array $config = [], bool $scoped = true, ?Waypoint $waypoint = null): TrackingTarget
{
    $order = new TrackingControllerOrder();
    $order->setRawAttributes(['uuid' => 'order-1', 'company_uuid' => 'co-1', 'customer_uuid' => 'contact-1', 'customer_type' => Contact::class, 'meta' => json_encode([])], true);

    $trackingNumber = new TrackingNumber();
    $trackingNumber->setRawAttributes(['uuid' => 'tn-1', 'tracking_number' => 'NOR1000000001US'], true);

    $scope = $scoped ? new TrackingScope('order-1', Contact::class, 'contact-1', $order) : null;

    return new TrackingTarget($order, $scope, $trackingNumber, TrackingTarget::KIND_ORDER, $waypoint, TrackingPageConfig::sanitize($config));
}

function trackingControllerRequest(array $input = []): Request
{
    return Request::create('/public/track/NOR1000000001US', 'GET', $input, [], [], ['REMOTE_ADDR' => '203.0.113.7']);
}

test('the page config shows a company page or the shared page, and nothing for a page that is off', function () {
    $controller = trackingController();
    TrackingPageDatabase::insert(Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection(), 'companies', ['uuid' => 'co-1', 'name' => 'Northwind Couriers', 'phone' => '+15105550142']);

    expect($controller->config(trackingControllerRequest(['org' => 'northwind']))->getStatusCode())->toBe(404);

    $controller->fakeResolver->slugCompany = 'co-1';
    $controller->fakeResolver->company     = ['access' => ['session_hours' => 12]];
    $page                                  = $controller->config(trackingControllerRequest(['org' => ' Northwind ']))->getData(true);
    expect($page['company']['name'])->toBe('Northwind Couriers')
        ->and($page['org'])->toBe('northwind')
        ->and($page['sign_in'])->toBeTrue()
        ->and($page['session_hours'])->toBe(12);

    $shared = $controller->config(trackingControllerRequest(['org' => '  ']))->getData(true);
    expect($shared['org'])->toBeNull()
        ->and($shared['company']['logo_url'])->toBe('https://instance.example/logo.png')
        ->and($shared['session_hours'])->toBe(24);

    $controller->fakeResolver->admin = ['generic_page' => ['enabled' => false]];
    expect($controller->config(trackingControllerRequest())->getStatusCode())->toBe(404);
});

test('lookups answer unknown and throttled numbers alike, and show what the viewer may see', function () {
    $controller = trackingController();

    $notFound = $controller->show(trackingControllerRequest(), 'NOR1000000001US');
    expect($notFound->getStatusCode())->toBe(404)
        ->and($notFound->getData(true))->toBe(['error' => TrackingController::NOT_FOUND, 'code' => 'not_found']);

    $controller->fakeResolver->target = trackingControllerTarget();
    $controller->fakeAccess->lookup   = false;
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true))->toBe($notFound->getData(true));

    // A tracking number alone: the public view, with the channels a code can use.
    $controller->fakeAccess->lookup = true;
    $controller->providers          = ['vonage' => ['available' => true]];
    TrackingPageDatabase::insert(Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection(), 'contacts', ['uuid' => 'contact-1', 'email' => 'maya@example.com', 'phone' => '+15105554821']);
    $public = $controller->show(trackingControllerRequest(['org' => 'Northwind']), 'NOR1000000001US')->getData(true);
    expect($public['level'])->toBe(0)
        ->and(array_column($public['channels'], 'type'))->toBe(['sms', 'email'])
        ->and($public['sign_in'])->toBeTrue()
        ->and($controller->fakeResolver->resolved[1])->toBe(['NOR1000000001US', 'northwind']);

    $controller->fakeResolver->target = trackingControllerTarget(['access' => ['account_sign_in' => false]], false);
    $unscoped                         = $controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true);
    expect($unscoped['channels'])->toBe([])->and($unscoped['sign_in'])->toBeFalse();

    // A verified device, a signed-in customer and staff of the owning company see the full view.
    $controller->fakeResolver->target = trackingControllerTarget();
    $controller->fakeAccess->granted  = true;
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true))->toMatchArray(['viewer' => 'verified', 'expires_at' => '2026-10-09T09:00:00.000000Z']);

    $controller->fakeAccess->granted = false;
    $controller->user                = (object) ['uuid' => 'user-1', 'type' => 'customer', 'company_uuid' => 'co-1'];
    TrackingPageDatabase::insert(Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection(), 'contacts', ['uuid' => 'contact-x', 'user_uuid' => 'user-1']);
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true)['level'])->toBe(0);

    Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection()->table('contacts')->where('uuid', 'contact-1')->update(['user_uuid' => 'user-1']);
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true))->toMatchArray(['viewer' => 'account', 'level' => 2, 'expires_at' => null]);

    $controller->user = (object) ['uuid' => 'user-2', 'type' => 'user', 'company_uuid' => 'co-1'];
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true)['viewer'])->toBe('staff');

    // Staff of another company, or a user with no id, get the public view.
    $controller->user = (object) ['uuid' => 'user-3', 'type' => 'user', 'company_uuid' => 'co-2'];
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true)['level'])->toBe(0);
    $controller->user = (object) ['type' => 'customer'];
    expect($controller->show(trackingControllerRequest(), 'NOR1000000001US')->getData(true)['level'])->toBe(0);
});

test('codes go out on an available channel, within the limits', function () {
    $controller = trackingController();
    $db         = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection();
    TrackingPageDatabase::insert($db, 'contacts', ['uuid' => 'contact-1', 'email' => 'maya@example.com']);
    TrackingPageDatabase::insert($db, 'companies', ['uuid' => 'co-1', 'name' => 'Northwind', 'options' => json_encode(['alpha_numeric_sender_id_enabled' => true, 'alpha_numeric_sender_id' => 'NORTHWIND'])]);

    expect($controller->sendCode(trackingControllerRequest(['channel' => 'email']), 'X')->getStatusCode())->toBe(404);

    $place = new Place();
    $place->setRawAttributes(['uuid' => 'place-1', 'phone' => '+15105554821'], true);
    $waypoint = new Waypoint();
    $waypoint->setRelation('place', $place);
    $controller->fakeResolver->target = trackingControllerTarget([], true, $waypoint);

    // SMS needs a provider; the stop's place phone stands in for the contact's missing one.
    expect($controller->sendCode(trackingControllerRequest(['channel' => 'sms']), 'NOR1')->getData(true))->toBe(['error' => 'channel_unavailable']);

    config(['services.twilio.sid' => 'AC1', 'services.twilio.token' => 'secret']);
    $sent = $controller->sendCode(trackingControllerRequest(['channel' => 'sms']), 'NOR1');
    expect($sent->getStatusCode())->toBe(202)
        ->and($sent->getData(true))->toBe(['channel' => 'sms', 'masked' => '•••• •• 4821', 'expires_in' => 600, 'resend_after' => 30])
        ->and($controller->fakeAccess->issued[0])->toBe(['order-1:' . Contact::class . ':contact-1', 'contact-1', 'sms'])
        ->and($controller->sms[0][0])->toBe('+15105554821')
        ->and($controller->sms[0][1])->toBe('482193 is your Northwind delivery code. It expires in 10 minutes.')
        ->and($controller->sms[0][2])->toBe(['twilioParams' => ['from' => 'NORTHWIND']]);

    $controller->sendCode(trackingControllerRequest(['channel' => 'email']), 'NOR1');
    expect($controller->mails[0])->toBe(['maya@example.com', '482193 is your Northwind delivery code', '482193 is your Northwind delivery code. It expires in 10 minutes.']);

    $controller->fakeAccess->claim = ['status' => TrackingAccess::SEND_COOLDOWN, 'retry_after' => 12];
    expect($controller->sendCode(trackingControllerRequest(['channel' => 'email']), 'NOR1')->getStatusCode())->toBe(429);
    $controller->fakeAccess->claim = ['status' => TrackingAccess::SEND_PAUSED, 'retry_after' => 200];
    expect($controller->sendCode(trackingControllerRequest(['channel' => 'email']), 'NOR1')->getData(true))->toBe(['error' => 'paused', 'retry_after' => 200]);

    // A company with no name of its own falls back to a generic sender name, and no alphanumeric sender.
    $db->table('companies')->delete();
    $controller->fakeAccess->claim = ['status' => TrackingAccess::SEND_SENT, 'retry_after' => 30];
    $controller->sendCode(trackingControllerRequest(['channel' => 'sms']), 'NOR1');
    expect($controller->sms[1][1])->toStartWith('482193 is your Your delivery delivery code')
        ->and($controller->sms[1][2])->toBe([]);

    $controller->fakeResolver->target = trackingControllerTarget([], false);
    expect($controller->sendCode(trackingControllerRequest(['channel' => 'email']), 'NOR1')->getStatusCode())->toBe(422);
});

test('checking a code verifies the device or says why it can’t', function () {
    $controller = trackingController();

    $controller->fakeAccess->verify = false;
    expect($controller->verifyCode(trackingControllerRequest(['code' => '482193']), 'NOR1')->getStatusCode())->toBe(429);

    $controller->fakeAccess->verify = true;
    expect($controller->verifyCode(trackingControllerRequest(['code' => '482193']), 'NOR1')->getStatusCode())->toBe(404);

    $controller->fakeResolver->target = trackingControllerTarget(['access' => ['session_hours' => 6]]);
    $verified                         = $controller->verifyCode(trackingControllerRequest(['code' => '48 21 93']), 'NOR1');
    expect($verified->getStatusCode())->toBe(200)
        ->and($verified->getData(true)['viewer'])->toBe('verified')
        ->and($verified->headers->getCookies()[0]->getName())->toBe(TrackingAccess::COOKIE)
        ->and($controller->fakeAccess->grants[0])->toBe(['order-1:' . Contact::class . ':contact-1', 'sms', 6]);

    $controller->fakeAccess->check = ['status' => VerificationCode::CHECK_INVALID, 'attempts_left' => 2];
    expect($controller->verifyCode(trackingControllerRequest(['code' => '111111']), 'NOR1')->getData(true))->toBe(['error' => 'invalid', 'attempts_left' => 2]);

    $controller->fakeAccess->check = ['status' => VerificationCode::CHECK_LOCKED];
    expect($controller->verifyCode(trackingControllerRequest(['code' => '111111']), 'NOR1')->getData(true))->toBe(['error' => 'paused', 'retry_after' => TrackingAccess::PAUSE_SECONDS]);

    $controller->fakeAccess->check = ['status' => VerificationCode::CHECK_EXPIRED];
    expect($controller->verifyCode(trackingControllerRequest(['code' => '111111']), 'NOR1')->getStatusCode())->toBe(410);

    $controller->fakeAccess->paused = 120;
    expect($controller->verifyCode(trackingControllerRequest(['code' => '111111']), 'NOR1')->getData(true))->toBe(['error' => 'paused', 'retry_after' => 120]);
});

test('signing out drops one delivery or the whole device', function () {
    $controller                       = trackingController();
    $controller->fakeResolver->target = trackingControllerTarget();

    $one = $controller->signOut(trackingControllerRequest(['number' => 'NOR1']));
    expect($one->getData(true))->toBe(['status' => 'signed_out'])
        ->and($one->headers->getCookies())->toBe([])
        ->and($controller->fakeAccess->revoked[0])->toBe('order-1:' . Contact::class . ':contact-1');

    $all = $controller->signOut(trackingControllerRequest());
    expect($all->headers->getCookies()[0]->getExpiresTime())->toBe(1)
        ->and($controller->fakeAccess->revoked[1])->toBeNull();
});

test('delivery instructions, proofs, reports and socket tokens need a verified viewer', function () {
    $controller = trackingController();

    foreach (['updateInstructions', 'report', 'socketToken'] as $method) {
        expect($controller->{$method}(trackingControllerRequest(), 'NOR1')->getStatusCode())->toBe(404);
    }
    expect($controller->proof(trackingControllerRequest(), 'NOR1', 'proof_1')->getStatusCode())->toBe(404);

    $controller->fakeResolver->target = trackingControllerTarget();
    expect($controller->updateInstructions(trackingControllerRequest(), 'NOR1')->getStatusCode())->toBe(404);

    $controller->fakeAccess->granted = true;

    // Instructions
    expect($controller->updateInstructions(trackingControllerRequest(['text' => 'Gate 4']), 'NOR1')->getStatusCode())->toBe(403);
    $controller->fakeResolver->target = trackingControllerTarget(['visibility' => ['instructions_edit' => true]]);
    $controller->updateInstructions(trackingControllerRequest(['text' => '  Leave with concierge  ']), 'NOR1');
    $order = $controller->fakeResolver->target->order;
    expect($order->saved)->toBeTrue()
        ->and($order->getMeta('recipient_instructions'))->toBe([sha1('order-1:' . Contact::class . ':contact-1') => 'Leave with concierge']);

    // Proofs
    expect($controller->proof(trackingControllerRequest(), 'NOR1', 'proof_1')->getStatusCode())->toBe(404);
    $controller->fakeView->proof = new Proof();
    $controller->fakeView->proof->setRawAttributes(['raw_data' => 'iVBOR'], true);
    $controller->fakeView->proof->setRelation('file', null);
    expect($controller->proof(trackingControllerRequest(), 'NOR1', 'proof_1')->getData(true))->toBe(['url' => 'data:image/png;base64,iVBOR', 'type' => 'signature']);
    $controller->fakeView->proof->setRawAttributes(['raw_data' => 'data:image/svg+xml;base64,PHN2Zz4='], true);
    expect($controller->proof(trackingControllerRequest(), 'NOR1', 'proof_1')->getData(true)['url'])->toBe('data:image/svg+xml;base64,PHN2Zz4=');
    $controller->fakeView->proof->setRawAttributes([], true);
    expect($controller->proof(trackingControllerRequest(), 'NOR1', 'proof_1')->getStatusCode())->toBe(404);
    $controller->fakeView->proof->setRelation('file', (object) ['url' => 'https://bucket.example/signed']);
    expect($controller->proof(trackingControllerRequest(), 'NOR1', 'proof_1')->getData(true)['url'])->toBe('https://bucket.example/signed');

    // Reports
    expect($controller->report(trackingControllerRequest(['message' => 'Box damaged']), 'NOR1')->getStatusCode())->toBe(422);
    $controller->fakeResolver->target = trackingControllerTarget(['branding' => ['support_email' => 'help@northwind.example']]);
    expect($controller->report(trackingControllerRequest(['message' => '   ']), 'NOR1')->getStatusCode())->toBe(422);
    $controller->fakeAccess->reportOk = false;
    expect($controller->report(trackingControllerRequest(['message' => 'Box damaged']), 'NOR1')->getStatusCode())->toBe(429);
    $controller->fakeAccess->reportOk = true;
    expect($controller->report(trackingControllerRequest(['message' => 'Box damaged']), 'NOR1')->getStatusCode())->toBe(202)
        ->and($controller->mails[0])->toBe(['help@northwind.example', 'Problem reported for NOR1000000001US', "A customer reported a problem with delivery NOR1000000001US:\n\nBox damaged"]);

    // Socket tokens, only when the instance publishes tracking channels.
    expect($controller->socketToken(trackingControllerRequest(), 'NOR1')->getStatusCode())->toBe(404);
    $controller->sockets = true;
    expect($controller->socketToken(trackingControllerRequest(), 'NOR1')->getData(true))->toBe(['token' => 'jwt', 'expires_in' => 1800, 'expires_at' => '2026-10-08T09:30:00Z']);
});

test('a signed-in customer lists their orders with the number that opens their part', function () {
    $controller = trackingController();
    $db         = Illuminate\Database\Eloquent\Model::getConnectionResolver()->connection();

    expect($controller->accountOrders(trackingControllerRequest())->getStatusCode())->toBe(401);

    $controller->user = (object) ['uuid' => 'user-1', 'type' => 'customer'];
    TrackingPageDatabase::insert($db, 'contacts', ['uuid' => 'contact-1', 'user_uuid' => 'user-1']);
    TrackingPageDatabase::insert($db, 'tracking_numbers', ['uuid' => 'tn-own', 'tracking_number' => 'NOR1']);
    TrackingPageDatabase::insert($db, 'tracking_numbers', ['uuid' => 'tn-stop', 'tracking_number' => 'NOR2-STOP']);
    TrackingPageDatabase::insert($db, 'orders', ['uuid' => 'order-own', 'customer_uuid' => 'contact-1', 'customer_type' => Contact::class, 'tracking_number_uuid' => 'tn-own', 'payload_uuid' => 'p-own', 'status' => 'completed', 'created_at' => '2026-10-07 09:00:00', 'updated_at' => '2026-10-07 10:00:00']);
    TrackingPageDatabase::insert($db, 'orders', ['uuid' => 'order-stop', 'customer_uuid' => 'shipper', 'customer_type' => Contact::class, 'payload_uuid' => 'p-stop', 'status' => 'created', 'created_at' => '2026-10-08 09:00:00']);
    TrackingPageDatabase::insert($db, 'orders', ['uuid' => 'order-none', 'customer_uuid' => 'contact-1', 'customer_type' => Contact::class, 'payload_uuid' => 'p-none', 'created_at' => '2026-10-06 09:00:00']);
    TrackingPageDatabase::insert($db, 'waypoints', ['uuid' => 'wp-1', 'payload_uuid' => 'p-stop', 'customer_uuid' => 'contact-1', 'customer_type' => Contact::class, 'tracking_number_uuid' => 'tn-stop']);
    TrackingPageDatabase::insert($db, 'entities', ['uuid' => 'e-1', 'payload_uuid' => 'p-own', 'name' => 'Ceramic lamp']);

    $orders = $controller->accountOrders(trackingControllerRequest())->getData(true)['orders'];
    expect($orders)->toBe([
        ['tracking_number' => 'NOR2-STOP', 'title' => null, 'status' => 'created', 'stage' => 'preparing', 'updated_at' => null],
        ['tracking_number' => 'NOR1', 'title' => 'Ceramic lamp', 'status' => 'completed', 'stage' => 'delivered', 'updated_at' => '2026-10-07T10:00:00.000000Z'],
    ]);
});
