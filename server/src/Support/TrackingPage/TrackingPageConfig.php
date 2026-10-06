<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Illuminate\Support\Str;

/**
 * The shape, defaults and sanitizing of a company's customer tracking page settings.
 *
 * A company keeps its config in the company setting `tracking-page`. Instance-wide
 * defaults for the generic page live in the system setting `fleet-ops.tracking-page`.
 * Everything here is pure, so the controller and the public tracking API read one shape.
 *
 * Privacy rules are not settings: the map and driver hide after delivery, and the driver
 * shows only while en route to the viewer's stops, whatever is saved here.
 */
class TrackingPageConfig
{
    public const SETTING_KEY       = 'tracking-page';
    public const ADMIN_SETTING_KEY = 'fleet-ops.tracking-page';
    public const SLUG_INDEX_PREFIX = 'fleet-ops.tracking-page-slugs.';
    public const DEFAULT_ACCENT    = '#1F5FA8';
    public const DARK_INK          = '#14191A';
    public const LIGHT_INK         = '#FFFFFF';

    /**
     * The FleetOps locales a tracking page can be shown in.
     */
    public const LOCALES = ['en-us', 'ar-ae', 'bg-bg', 'es-pa', 'fr-fr', 'mn-mn', 'pt-br', 'ru-ru', 'uk-ua', 'vi-vn'];

    /**
     * Slugs a company can't take: they would read as part of the page's own URLs.
     */
    public const RESERVED_SLUGS = [
        'admin', 'api', 'auth', 'console', 'customer-access', 'customer-portal', 'fleetbase', 'help',
        'int', 'login', 'new', 'portal', 'public', 'settings', 'support', 'track', 'tracking', 'www',
    ];

    /**
     * Instance-wide defaults for the generic page, as an administrator saved them.
     */
    public static function sanitizeAdmin(array $input): array
    {
        return [
            'generic_page' => [
                'enabled' => static::bool(data_get($input, 'generic_page.enabled'), true),
            ],
            'branding'     => [
                'display_name'  => static::text(data_get($input, 'branding.display_name'), 80),
                'accent'        => static::color(data_get($input, 'branding.accent')) ?? static::DEFAULT_ACCENT,
                'support_phone' => static::text(data_get($input, 'branding.support_phone'), 32),
                'support_email' => static::email(data_get($input, 'branding.support_email')),
                'website'       => static::url(data_get($input, 'branding.website')),
                'powered_by'    => static::bool(data_get($input, 'branding.powered_by'), true),
            ],
        ];
    }

    /**
     * A company's config, with every key present and every value valid.
     *
     * @param array       $admin       the sanitized instance defaults
     * @param string|null $companyName used for the default slug
     */
    public static function sanitize(array $input, array $admin = [], ?string $companyName = null): array
    {
        $admin  = static::sanitizeAdmin($admin);
        $locale = static::oneOf(data_get($input, 'branding.default_locale'), static::LOCALES, 'en-us');

        return [
            'org_page'     => [
                'enabled' => static::bool(data_get($input, 'org_page.enabled'), false),
                'slug'    => static::normalizeSlug(data_get($input, 'org_page.slug')) ?: static::slugify((string) $companyName),
            ],
            'generic_page' => [
                'allowed' => static::bool(data_get($input, 'generic_page.allowed'), true),
            ],
            'links'        => [
                'target' => static::oneOf(data_get($input, 'links.target'), ['auto', 'org', 'generic'], 'auto'),
            ],
            'branding'     => [
                'display_name'  => static::text(data_get($input, 'branding.display_name'), 80),
                'logo_uuid'     => static::text(data_get($input, 'branding.logo_uuid'), 64),
                'accent'        => static::color(data_get($input, 'branding.accent')) ?? $admin['branding']['accent'],
                'accent_dark'   => static::color(data_get($input, 'branding.accent_dark')),
                'support_phone' => static::text(data_get($input, 'branding.support_phone'), 32),
                'support_email' => static::email(data_get($input, 'branding.support_email')),
                'website'       => static::url(data_get($input, 'branding.website')),
                'powered_by'    => static::bool(data_get($input, 'branding.powered_by'), $admin['branding']['powered_by']),
                'theme'         => static::oneOf(data_get($input, 'branding.theme'), ['system', 'light'], 'system'),
                'default_locale' => $locale,
                'locales'       => static::locales(data_get($input, 'branding.locales'), $locale),
            ],
            'access'       => [
                'public_status'   => static::bool(data_get($input, 'access.public_status'), true),
                'channels'        => [
                    'sms'   => static::bool(data_get($input, 'access.channels.sms'), true),
                    'email' => static::bool(data_get($input, 'access.channels.email'), true),
                ],
                'session_hours'   => static::clampInt(data_get($input, 'access.session_hours'), 1, 168, 24),
                'account_sign_in' => static::bool(data_get($input, 'access.account_sign_in'), true),
                'account_upsell'  => static::bool(data_get($input, 'access.account_upsell'), true),
            ],
            'visibility'   => [
                'map'               => static::bool(data_get($input, 'visibility.map'), true),
                'driver_name'       => static::bool(data_get($input, 'visibility.driver_name'), true),
                'vehicle'           => static::bool(data_get($input, 'visibility.vehicle'), true),
                'driver_contact'    => static::oneOf(data_get($input, 'visibility.driver_contact'), ['company', 'driver'], 'company'),
                'items'             => static::bool(data_get($input, 'visibility.items'), true),
                'item_prices'       => static::bool(data_get($input, 'visibility.item_prices'), false),
                'pod_photo'         => static::bool(data_get($input, 'visibility.pod_photo'), true),
                'pod_signature'     => static::bool(data_get($input, 'visibility.pod_signature'), true),
                'instructions_edit' => static::bool(data_get($input, 'visibility.instructions_edit'), false),
                'report_problem'    => static::bool(data_get($input, 'visibility.report_problem'), true),
            ],
        ];
    }

    /**
     * Why a slug can't be used, or null when its shape is fine. Availability is the caller's check.
     *
     * @return string|null `invalid` or `reserved`
     */
    public static function slugError(?string $slug): ?string
    {
        if (!is_string($slug) || preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38})[a-z0-9]$/', $slug) !== 1 || str_contains($slug, '--')) {
            return 'invalid';
        }

        return in_array($slug, static::RESERVED_SLUGS, true) ? 'reserved' : null;
    }

    /**
     * A slug made from a company name: lowercase, hyphenated, 3 to 40 characters.
     */
    public static function slugify(string $name): string
    {
        $slug = trim(Str::limit(Str::slug($name), 40, ''), '-');

        if (strlen($slug) >= 3) {
            return $slug;
        }

        return $slug === '' ? 'company-tracking' : $slug . '-tracking';
    }

    /**
     * The text colour readable on an accent: white when it reaches 4.5:1, otherwise dark.
     */
    public static function inkFor(string $accent): string
    {
        return static::contrastRatio($accent, static::LIGHT_INK) >= 4.5 ? static::LIGHT_INK : static::DARK_INK;
    }

    /**
     * The WCAG contrast ratio of two `#RRGGBB` colours.
     */
    public static function contrastRatio(string $a, string $b): float
    {
        $lighter = max(static::luminance($a), static::luminance($b));
        $darker  = min(static::luminance($a), static::luminance($b));

        return round(($lighter + 0.05) / ($darker + 0.05), 2);
    }

    protected static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair) {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    protected static function normalizeSlug(mixed $value): string
    {
        return is_string($value) ? strtolower(trim($value)) : '';
    }

    protected static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    protected static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected static function email(mixed $value): ?string
    {
        $value = static::text($value, 191);

        return $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    protected static function url(mixed $value): ?string
    {
        $value = static::text($value, 255);

        return $value !== null && preg_match('#^https?://#i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    /**
     * `#RGB` or `#RRGGBB` as uppercase `#RRGGBB`, or null.
     */
    protected static function color(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $match) !== 1) {
            return null;
        }

        $hex = $match[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . strtoupper($hex);
    }

    protected static function oneOf(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    protected static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * The enabled locales, always including the default one; every locale when none are valid.
     */
    protected static function locales(mixed $value, string $default): array
    {
        $locales = array_values(array_intersect(static::LOCALES, is_array($value) ? $value : []));
        if ($locales === []) {
            return static::LOCALES;
        }

        return in_array($default, $locales, true) ? $locales : array_merge([$default], $locales);
    }
}
