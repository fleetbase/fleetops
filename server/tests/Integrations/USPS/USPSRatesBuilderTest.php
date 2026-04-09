<?php

use Fleetbase\FleetOps\Integrations\USPS\USPS;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class USPSTestHarness
{
    public USPS $bridge;
    public array $history = [];
    public \ArrayObject $cache;

    public function __construct(array $responses = [], bool $sandbox = true)
    {
        $mock = new MockHandler(array_map(
            fn ($payload) => new Response(
                $payload['status'] ?? 200,
                ['Content-Type' => 'application/json'],
                json_encode($payload['body'] ?? [])
            ),
            $responses
        ));

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $this->cache  = new \ArrayObject();
        $this->bridge = new USPS('test-client', 'test-secret', $sandbox, $stack, $this->cache);
    }
}

// ── placeToUspsAddress ───────────────────────────────────────────────────

test('placeToUspsAddress maps street/city/state/zip', function () {
    $place = (object) [
        'street1'     => '1 Main St',
        'city'        => 'Boise',
        'province'    => 'ID',
        'postal_code' => '83702',
        'country'     => 'US',
    ];
    $addr = USPS::placeToUspsAddress($place);
    expect($addr['streetAddress'])->toBe('1 Main St');
    expect($addr['city'])->toBe('Boise');
    expect($addr['state'])->toBe('ID');
    expect($addr['ZIPCode'])->toBe('83702');
});

test('placeToUspsAddress coerces nulls to empty strings', function () {
    $place = (object) ['street1' => null, 'city' => null, 'province' => null, 'postal_code' => null];
    $addr = USPS::placeToUspsAddress($place);
    expect($addr['streetAddress'])->toBe('');
    expect($addr['city'])->toBe('');
    expect($addr['ZIPCode'])->toBe('');
});

// ── entityToUspsParcel ───────────────────────────────────────────────────

test('entityToUspsParcel builds parcel shape with length/width/height/weight', function () {
    $entity = (object) [
        'type'   => 'parcel',
        'length' => 12.0,
        'width'  => 9.0,
        'height' => 3.0,
        'weight' => 2.5,
    ];
    $parcel = USPS::entityToUspsParcel($entity);
    expect($parcel['length'])->toBe(12.0);
    expect($parcel['width'])->toBe(9.0);
    expect($parcel['height'])->toBe(3.0);
    // USPS weight is in pounds for v3
    expect($parcel['weight'])->toBe(2.5);
});

// ── buildRatesRequest ────────────────────────────────────────────────────

test('buildRatesRequest assembles origin/destination ZIP + parcel', function () {
    $body = USPS::buildRatesRequest(
        USPS::placeToUspsAddress((object) ['postal_code' => '94110']),
        USPS::placeToUspsAddress((object) ['postal_code' => '10001']),
        USPS::entityToUspsParcel((object) ['length' => 10, 'width' => 10, 'height' => 10, 'weight' => 3])
    );

    expect($body['originZIPCode'])->toBe('94110');
    expect($body['destinationZIPCode'])->toBe('10001');
    expect($body['weight'])->toBe(3.0);
    expect($body['length'])->toBe(10.0);
    expect($body['width'])->toBe(10.0);
    expect($body['height'])->toBe(10.0);
});

test('buildRatesRequest includes mailClass when provided', function () {
    $body = USPS::buildRatesRequest(
        USPS::placeToUspsAddress((object) ['postal_code' => '94110']),
        USPS::placeToUspsAddress((object) ['postal_code' => '10001']),
        USPS::entityToUspsParcel((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1]),
        'PRIORITY_MAIL'
    );
    expect($body['mailClass'])->toBe('PRIORITY_MAIL');
});

test('buildRatesRequest omits mailClass when null for search-all behavior', function () {
    $body = USPS::buildRatesRequest(
        USPS::placeToUspsAddress((object) ['postal_code' => '94110']),
        USPS::placeToUspsAddress((object) ['postal_code' => '10001']),
        USPS::entityToUspsParcel((object) ['length' => 1, 'width' => 1, 'height' => 1, 'weight' => 1])
    );
    expect(isset($body['mailClass']))->toBeFalse();
});

// ── normalizeRatesResponse ───────────────────────────────────────────────

test('normalizeRatesResponse converts dollars to integer cents', function () {
    $rows = USPS::normalizeRatesResponse(
        ['rates' => [
            ['mailClass' => 'PRIORITY_MAIL', 'price' => 8.10],
            ['mailClass' => 'USPS_GROUND_ADVANTAGE', 'price' => 5.20],
        ]],
        'flat',
        0
    );

    expect($rows)->toHaveCount(2);
    expect($rows[0]['amount'])->toBe(810);
    expect($rows[0]['meta']['carrier'])->toBe('USPS');
    expect($rows[0]['meta']['mail_class'])->toBe('PRIORITY_MAIL');
    expect($rows[0]['meta']['carrier_amount'])->toBe(810);
    expect($rows[1]['amount'])->toBe(520);
});

test('normalizeRatesResponse applies flat markup in cents', function () {
    $rows = USPS::normalizeRatesResponse(
        ['rates' => [['mailClass' => 'PRIORITY_MAIL', 'price' => 8.10]]],
        'flat',
        25
    );
    // 810 carrier + 25 flat = 835 sell
    expect($rows[0]['amount'])->toBe(835);
    expect($rows[0]['meta']['carrier_amount'])->toBe(810);
    expect($rows[0]['meta']['markup_amount'])->toBe(25);
    expect($rows[0]['meta']['markup_type'])->toBe('flat');
});

test('normalizeRatesResponse applies percent markup', function () {
    $rows = USPS::normalizeRatesResponse(
        ['rates' => [['mailClass' => 'PRIORITY_MAIL', 'price' => 10.00]]],
        'percent',
        10
    );
    // 1000 + 10% = 1100
    expect($rows[0]['amount'])->toBe(1100);
    expect($rows[0]['meta']['markup_amount'])->toBe(100);
});

test('normalizeRatesResponse resolves description via USPSServiceType', function () {
    $rows = USPS::normalizeRatesResponse(
        ['rates' => [['mailClass' => 'PRIORITY_MAIL', 'price' => 5.00]]],
        'flat',
        0
    );
    expect($rows[0]['service'])->toBe('USPS Priority Mail');
});

test('normalizeRatesResponse skips rows missing a price', function () {
    $rows = USPS::normalizeRatesResponse(
        ['rates' => [
            ['mailClass' => 'PRIORITY_MAIL', 'price' => 5.00],
            ['mailClass' => 'FLAT_RATE_ENVELOPE', 'error' => 'not_available'],
            ['mailClass' => 'USPS_GROUND_ADVANTAGE', 'price' => 4.00],
        ]],
        'flat',
        0
    );
    expect($rows)->toHaveCount(2);
});

test('normalizeRatesResponse returns empty array when rates key missing', function () {
    expect(USPS::normalizeRatesResponse([], 'flat', 0))->toBe([]);
});

test('normalizeRatesResponse handles sub-cent rounding', function () {
    $rows = USPS::normalizeRatesResponse(
        ['rates' => [
            ['mailClass' => 'PRIORITY_MAIL', 'price' => 8.425],
            ['mailClass' => 'USPS_GROUND_ADVANTAGE', 'price' => 8.424],
        ]],
        'flat',
        0
    );
    expect($rows[0]['amount'])->toBe(843);
    expect($rows[1]['amount'])->toBe(842);
});

// ── Inline OAuth ─────────────────────────────────────────────────────────

test('sandbox host routes to apis-tem.usps.com', function () {
    $h = new USPSTestHarness([
        ['status' => 200, 'body' => ['access_token' => 'tok', 'expires_in' => 3600]],
    ], true);
    $h->bridge->getAccessToken();

    expect((string) $h->history[0]['request']->getUri())
        ->toStartWith('https://apis-tem.usps.com/oauth2/v3/token');
});

test('production host routes to api.usps.com', function () {
    $h = new USPSTestHarness([
        ['status' => 200, 'body' => ['access_token' => 'tok', 'expires_in' => 3600]],
    ], false);
    $h->bridge->getAccessToken();

    expect((string) $h->history[0]['request']->getUri())
        ->toStartWith('https://api.usps.com/oauth2/v3/token');
});

test('oauth token is cached under usps_oauth_token_{clientId}', function () {
    $h = new USPSTestHarness([
        ['status' => 200, 'body' => ['access_token' => 'usps-tok', 'expires_in' => 3600]],
    ]);

    $token = $h->bridge->getAccessToken();

    expect($token)->toBe('usps-tok');
    expect($h->cache['usps_oauth_token_test-client'])->toBe('usps-tok');
});

test('second getAccessToken call within TTL returns cached value without HTTP', function () {
    $h = new USPSTestHarness([
        ['status' => 200, 'body' => ['access_token' => 'first', 'expires_in' => 3600]],
    ]);

    $first  = $h->bridge->getAccessToken();
    $second = $h->bridge->getAccessToken();

    expect($first)->toBe('first');
    expect($second)->toBe('first');
    expect($h->history)->toHaveCount(1);
});

test('oauth request body is grant_type=client_credentials with basic auth', function () {
    $h = new USPSTestHarness([
        ['status' => 200, 'body' => ['access_token' => 'tok', 'expires_in' => 3600]],
    ]);
    $h->bridge->getAccessToken();

    $req = $h->history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Authorization'))->toBe('Basic ' . base64_encode('test-client:test-secret'));
    expect((string) $req->getBody())->toBe('grant_type=client_credentials');
});

test('non-2xx oauth response throws RuntimeException', function () {
    $h = new USPSTestHarness([
        ['status' => 401, 'body' => ['error' => 'invalid_client']],
    ]);
    expect(fn () => $h->bridge->getAccessToken())->toThrow(RuntimeException::class);
});

test('missing access_token in oauth response throws RuntimeException', function () {
    $h = new USPSTestHarness([
        ['status' => 200, 'body' => ['expires_in' => 3600]],
    ]);
    expect(fn () => $h->bridge->getAccessToken())->toThrow(RuntimeException::class);
});
