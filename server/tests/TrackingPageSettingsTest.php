<?php

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\SettingController;
use Fleetbase\FleetOps\Support\TrackingPage\TrackingPageConfig;
use Illuminate\Http\Request;

class FleetOpsTrackingPageSettingProbe extends SettingController
{
    public array $settings        = [];
    public array $companySettings = [];
    public array $configured      = [];
    public mixed $company         = null;
    public array $smsProviders    = [];
    public array $extensions      = [];

    public function __construct()
    {
    }

    protected function configureSetting(string $key, mixed $value): mixed
    {
        $this->configured[]   = [$key, $value];
        $this->settings[$key] = $value;

        return null;
    }

    protected function configureCompanySetting(string $key, mixed $value): mixed
    {
        $this->companySettings[$key] = $value;

        return null;
    }

    protected function lookupSetting(string $key, mixed $defaultValue = null): mixed
    {
        return $this->settings[$key] ?? $defaultValue;
    }

    protected function lookupCompanySetting(string $key, mixed $defaultValue = null): mixed
    {
        return $this->companySettings[$key] ?? $defaultValue;
    }

    protected function currentCompany(): mixed
    {
        return $this->company;
    }

    protected function smsProviders(): array
    {
        return $this->smsProviders;
    }

    protected function installedFleetbaseExtensions(): array
    {
        return $this->extensions;
    }
}

function fleetopsTrackingPageProbe(): FleetOpsTrackingPageSettingProbe
{
    $probe          = new FleetOpsTrackingPageSettingProbe();
    $probe->company = (object) ['uuid' => 'company-1', 'name' => 'Northwind Couriers'];

    config(['services.twilio.sid' => null, 'services.twilio.token' => null]);

    return $probe;
}

function fleetopsTrackingPageRequest(array $trackingPage): Request
{
    return new Request(['trackingPage' => $trackingPage]);
}

test('tracking page config fills every key with defaults for an empty company setting', function () {
    $config = TrackingPageConfig::sanitize([], [], 'Northwind Couriers');

    expect($config['org_page'])->toBe(['enabled' => false, 'slug' => 'northwind-couriers'])
        ->and($config['generic_page'])->toBe(['allowed' => true])
        ->and($config['links'])->toBe(['target' => 'auto'])
        ->and($config['branding'])->toBe([
            'display_name'   => null,
            'logo_uuid'      => null,
            'accent'         => '#1F5FA8',
            'accent_dark'    => null,
            'support_phone'  => null,
            'support_email'  => null,
            'website'        => null,
            'powered_by'     => true,
            'theme'          => 'system',
            'default_locale' => 'en-us',
            'locales'        => TrackingPageConfig::LOCALES,
        ])
        ->and($config['access'])->toBe([
            'public_status'   => true,
            'channels'        => ['sms' => true, 'email' => true],
            'session_hours'   => 24,
            'account_sign_in' => true,
            'account_upsell'  => true,
        ])
        ->and($config['visibility'])->toBe([
            'map'               => true,
            'driver_name'       => true,
            'vehicle'           => true,
            'driver_contact'    => 'company',
            'items'             => true,
            'item_prices'       => false,
            'pod_photo'         => true,
            'pod_signature'     => true,
            'instructions_edit' => false,
            'report_problem'    => true,
        ]);
});

test('tracking page config keeps valid values and replaces invalid ones', function () {
    $config = TrackingPageConfig::sanitize([
        'org_page'     => ['enabled' => 'true', 'slug' => '  Northwind  '],
        'generic_page' => ['allowed' => '0'],
        'links'        => ['target' => 'org'],
        'branding'     => [
            'display_name'   => '  Northwind  ',
            'logo_uuid'      => 'file-uuid',
            'accent'         => '0b6',
            'accent_dark'    => '#5cc9be',
            'support_phone'  => 5105550142,
            'support_email'  => 'help@northwind.example',
            'website'        => 'https://northwind.example',
            'powered_by'     => false,
            'theme'          => 'light',
            'default_locale' => 'de-de',
            'locales'        => ['fr-fr', 'xx-xx'],
            'unknown'        => 'dropped',
        ],
        'access'       => ['public_status' => 'no', 'channels' => ['sms' => 'maybe', 'email' => false], 'session_hours' => 500],
        'visibility'   => ['driver_contact' => 'driver', 'item_prices' => 1, 'map' => 'off'],
        'extra'        => ['dropped' => true],
    ], ['branding' => ['accent' => '#123456', 'powered_by' => false]], 'Ignored');

    expect($config['org_page'])->toBe(['enabled' => true, 'slug' => 'northwind'])
        ->and($config['generic_page']['allowed'])->toBeFalse()
        ->and($config['links']['target'])->toBe('org')
        ->and($config['branding']['display_name'])->toBe('Northwind')
        ->and($config['branding']['logo_uuid'])->toBe('file-uuid')
        ->and($config['branding']['accent'])->toBe('#00BB66')
        ->and($config['branding']['accent_dark'])->toBe('#5CC9BE')
        ->and($config['branding']['support_phone'])->toBe('5105550142')
        ->and($config['branding']['support_email'])->toBe('help@northwind.example')
        ->and($config['branding']['website'])->toBe('https://northwind.example')
        ->and($config['branding']['powered_by'])->toBeFalse()
        ->and($config['branding']['theme'])->toBe('light')
        ->and($config['branding']['default_locale'])->toBe('en-us')
        ->and($config['branding']['locales'])->toBe(['en-us', 'fr-fr'])
        ->and($config['branding'])->not->toHaveKey('unknown')
        ->and($config)->not->toHaveKey('extra')
        ->and($config['access']['public_status'])->toBeFalse()
        ->and($config['access']['channels'])->toBe(['sms' => true, 'email' => false])
        ->and($config['access']['session_hours'])->toBe(168)
        ->and($config['visibility']['driver_contact'])->toBe('driver')
        ->and($config['visibility']['item_prices'])->toBeTrue()
        ->and($config['visibility']['map'])->toBeFalse();

    $fallback = TrackingPageConfig::sanitize([
        'branding' => [
            'accent'        => 'teal',
            'accent_dark'   => ['#000000'],
            'support_email' => 'not-an-email',
            'website'       => 'ftp://northwind.example',
            'display_name'  => '   ',
            'locales'       => ['ar-ae', 'en-us'],
        ],
        'access'   => ['session_hours' => 'soon'],
    ], ['branding' => ['accent' => '#123456', 'powered_by' => false]], 'N');

    expect($fallback['org_page']['slug'])->toBe('n-tracking')
        ->and($fallback['branding']['accent'])->toBe('#123456')
        ->and($fallback['branding']['accent_dark'])->toBeNull()
        ->and($fallback['branding']['support_email'])->toBeNull()
        ->and($fallback['branding']['website'])->toBeNull()
        ->and($fallback['branding']['display_name'])->toBeNull()
        ->and($fallback['branding']['powered_by'])->toBeFalse()
        ->and($fallback['branding']['locales'])->toBe(['en-us', 'ar-ae'])
        ->and($fallback['access']['session_hours'])->toBe(24)
        ->and(TrackingPageConfig::sanitize(['access' => ['session_hours' => 0]])['access']['session_hours'])->toBe(1)
        ->and(TrackingPageConfig::sanitize([])['org_page']['slug'])->toBe('company-tracking');
});

test('tracking page admin defaults are sanitized on their own', function () {
    expect(TrackingPageConfig::sanitizeAdmin([]))->toBe([
        'generic_page' => ['enabled' => true],
        'branding'     => [
            'display_name'  => null,
            'accent'        => '#1F5FA8',
            'support_phone' => null,
            'support_email' => null,
            'website'       => null,
            'powered_by'    => true,
        ],
    ])->and(TrackingPageConfig::sanitizeAdmin([
        'generic_page' => ['enabled' => false],
        'branding'     => ['display_name' => 'Fleetbase Cloud', 'accent' => '#abcdef', 'support_email' => 'ops@fleetbase.example'],
    ]))->toBe([
        'generic_page' => ['enabled' => false],
        'branding'     => [
            'display_name'  => 'Fleetbase Cloud',
            'accent'        => '#ABCDEF',
            'support_phone' => null,
            'support_email' => 'ops@fleetbase.example',
            'website'       => null,
            'powered_by'    => true,
        ],
    ]);
});

test('tracking page slugs are checked for shape and reserved words', function () {
    expect(TrackingPageConfig::slugError('northwind'))->toBeNull()
        ->and(TrackingPageConfig::slugError('north-wind-2'))->toBeNull()
        ->and(TrackingPageConfig::slugError('ab'))->toBe('invalid')
        ->and(TrackingPageConfig::slugError(str_repeat('a', 41)))->toBe('invalid')
        ->and(TrackingPageConfig::slugError('-northwind'))->toBe('invalid')
        ->and(TrackingPageConfig::slugError('north--wind'))->toBe('invalid')
        ->and(TrackingPageConfig::slugError('North'))->toBe('invalid')
        ->and(TrackingPageConfig::slugError(null))->toBe('invalid')
        ->and(TrackingPageConfig::slugError('track'))->toBe('reserved')
        ->and(TrackingPageConfig::slugError('customer-portal'))->toBe('reserved')
        ->and(TrackingPageConfig::slugify(str_repeat('Northwind ', 8)))->toHaveLength(39)
        ->and(TrackingPageConfig::slugify('Northwind Couriers, Inc.'))->toBe('northwind-couriers-inc');
});

test('tracking page accents get a readable ink and a contrast ratio', function () {
    expect(TrackingPageConfig::inkFor('#0B6E68'))->toBe('#FFFFFF')
        ->and(TrackingPageConfig::inkFor('#5CC9BE'))->toBe('#14191A')
        ->and(TrackingPageConfig::contrastRatio('#FFFFFF', '#000000'))->toBe(21.0)
        ->and(TrackingPageConfig::contrastRatio('#1F5FA8', '#FFFFFF'))->toBeGreaterThan(4.5)
        ->and(TrackingPageConfig::contrastRatio('#FFFFFF', '#FFFFFF'))->toBe(1.0);
});

test('tracking page settings read back with what the settings screen explains', function () {
    $probe = fleetopsTrackingPageProbe();

    $view = $probe->getTrackingPageSettings()->getData(true);

    expect($view['org_page'])->toBe(['enabled' => false, 'slug' => 'northwind-couriers'])
        ->and($view['slug_validation'])->toBe(['valid' => true, 'code' => 'available', 'message' => 'This address is available.'])
        ->and($view['accent_ink'])->toBe('#FFFFFF')
        ->and($view['accent_contrast'])->toBeGreaterThan(4.5)
        ->and($view['sms_available'])->toBeFalse()
        ->and($view['customer_portal_installed'])->toBeFalse()
        ->and($view['admin']['generic_page'])->toBe(['enabled' => true]);

    $probe->extensions   = [['name' => 'fleetbase/customer-portal-api']];
    $probe->smsProviders = ['twilio' => ['available' => true], 'vonage' => ['available' => true]];

    expect($probe->getTrackingPageSettings()->getData(true))
        ->toMatchArray(['sms_available' => true, 'customer_portal_installed' => true]);

    $probe->smsProviders = ['twilio' => ['available' => true]];
    expect($probe->getTrackingPageSettings()->getData(true)['sms_available'])->toBeFalse();

    config(['services.twilio.sid' => 'AC123', 'services.twilio.token' => 'secret']);
    expect($probe->getTrackingPageSettings()->getData(true)['sms_available'])->toBeTrue();
});

test('saving tracking page settings indexes the slug and frees the old one', function () {
    $probe = fleetopsTrackingPageProbe();

    $saved = $probe->saveTrackingPageSettings(fleetopsTrackingPageRequest([
        'org_page' => ['enabled' => true, 'slug' => 'northwind'],
        'branding' => ['accent' => '#0B6E68'],
    ]))->getData(true);

    expect($saved['org_page'])->toBe(['enabled' => true, 'slug' => 'northwind'])
        ->and($saved['branding']['accent'])->toBe('#0B6E68')
        ->and($probe->companySettings[TrackingPageConfig::SETTING_KEY]['org_page']['slug'])->toBe('northwind')
        ->and($probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind'])->toBe('company-1')
        ->and($probe->configured)->toBe([[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind', 'company-1']]);

    $probe->saveTrackingPageSettings(fleetopsTrackingPageRequest(['org_page' => ['enabled' => true, 'slug' => 'northwind-couriers']]));

    expect($probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind'])->toBeNull()
        ->and($probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind-couriers'])->toBe('company-1');

    // A previous slug that another company now holds is left alone.
    $probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind-couriers'] = 'company-2';
    $probe->configured                                                            = [];
    $probe->saveTrackingPageSettings(fleetopsTrackingPageRequest(['org_page' => ['enabled' => false, 'slug' => 'northwind-express']]));

    expect($probe->configured)->toBe([[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind-express', 'company-1']])
        ->and($probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind-couriers'])->toBe('company-2');
});

test('an enabled organization page refuses an unusable slug while a disabled one keeps it unindexed', function () {
    $probe                                                                  = fleetopsTrackingPageProbe();
    $probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'taken-slug'] = 'company-2';

    expect($probe->saveTrackingPageSettings(fleetopsTrackingPageRequest(['org_page' => ['enabled' => true, 'slug' => 'taken-slug']]))->getData(true))
        ->toBe(['error' => 'Another organization already uses this address.'])
        ->and($probe->saveTrackingPageSettings(fleetopsTrackingPageRequest(['org_page' => ['enabled' => true, 'slug' => 'track']]))->getData(true))
        ->toBe(['error' => 'This address is reserved. Please choose another.'])
        ->and($probe->saveTrackingPageSettings(fleetopsTrackingPageRequest(['org_page' => ['enabled' => true, 'slug' => 'a b']]))->getData(true)['error'])
        ->toStartWith('Use 3 to 40 lowercase letters')
        ->and($probe->companySettings)->toBe([]);

    $saved = $probe->saveTrackingPageSettings(fleetopsTrackingPageRequest(['org_page' => ['enabled' => false, 'slug' => 'taken-slug']]))->getData(true);

    expect($saved['org_page'])->toBe(['enabled' => false, 'slug' => 'taken-slug'])
        ->and($saved['slug_validation']['code'])->toBe('taken')
        ->and($probe->configured)->toBe([])
        ->and($probe->companySettings[TrackingPageConfig::SETTING_KEY]['org_page']['slug'])->toBe('taken-slug');
});

test('tracking page slugs can be checked while typed', function () {
    $probe                                                                 = fleetopsTrackingPageProbe();
    $probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'northwind'] = 'company-1';
    $probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'harbor']    = 'company-2';

    expect($probe->validateTrackingPageSlug(new Request(['slug' => ' Northwind ']))->getData(true))
        ->toBe(['slug' => 'northwind', 'valid' => true, 'code' => 'available', 'message' => 'This address is available.'])
        ->and($probe->validateTrackingPageSlug(new Request(['slug' => 'harbor']))->getData(true)['code'])->toBe('taken')
        ->and($probe->validateTrackingPageSlug(new Request(['slug' => 'admin']))->getData(true)['code'])->toBe('reserved')
        ->and($probe->validateTrackingPageSlug(new Request())->getData(true)['code'])->toBe('invalid');

    $probe->settings[TrackingPageConfig::SLUG_INDEX_PREFIX . 'empty-owner'] = '';
    expect($probe->validateTrackingPageSlug(new Request(['slug' => 'empty-owner']))->getData(true)['valid'])->toBeTrue();
});

test('admin tracking page defaults are read and saved sanitized', function () {
    $probe = fleetopsTrackingPageProbe();

    expect($probe->getAdminTrackingPageSettings()->getData(true)['generic_page'])->toBe(['enabled' => true]);

    $saved = $probe->saveAdminTrackingPageSettings(fleetopsTrackingPageRequest([
        'generic_page' => ['enabled' => false],
        'branding'     => ['accent' => 'nope', 'website' => 'https://fleetbase.example'],
    ]))->getData(true);

    expect($saved['generic_page'])->toBe(['enabled' => false])
        ->and($saved['branding']['accent'])->toBe('#1F5FA8')
        ->and($saved['branding']['website'])->toBe('https://fleetbase.example')
        ->and($probe->settings[TrackingPageConfig::ADMIN_SETTING_KEY])->toBe($saved)
        ->and($probe->getAdminTrackingPageSettings()->getData(true))->toBe($saved)
        ->and($probe->getTrackingPageSettings()->getData(true)['admin'])->toBe($saved);
});
