<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\Models\Company;
use Fleetbase\Models\Setting;
use Illuminate\Support\Str;

/**
 * What an organization lets a new driver do from the Navigator app, and the join
 * code that says which organization a new driver is signing up to.
 *
 * Navigator is built for a whole instance, so a person without an account belongs
 * to no organization yet. The organization gives them a short join code (typed, or
 * carried by the QR code they scan); the code names the organization and nothing
 * else, and only works while the organization has self sign-up switched on.
 */
class DriverOnboarding
{
    public const SETTINGS_PREFIX = 'fleet-ops.driver-onboard-settings.';
    public const CODE_PREFIX     = 'fleet-ops.driver-join-code.';

    /** No 0/O, 1/I/L: the code is read aloud and typed on a phone. */
    public const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH   = 8;

    public static function settings(string $companyUuid): array
    {
        $settings = Setting::where('key', static::SETTINGS_PREFIX . $companyUuid)->value('value');

        return is_array($settings) ? $settings : [];
    }

    public static function saveSettings(string $companyUuid, array $settings): array
    {
        Setting::configure(static::SETTINGS_PREFIX . $companyUuid, $settings);

        return $settings;
    }

    public static function isEnabled(array $settings): bool
    {
        return !empty($settings['enableDriverOnboardFromApp']);
    }

    /**
     * `button` lets anyone holding the join code create a driver account; `invite`
     * means the organization adds its drivers itself.
     */
    public static function method(array $settings): ?string
    {
        $method = $settings['driverOnboardAppMethod'] ?? null;

        return static::isEnabled($settings) && in_array($method, ['invite', 'button'], true) ? $method : null;
    }

    public static function allowsSelfSignUp(array $settings): bool
    {
        return static::method($settings) === 'button';
    }

    public static function documents(array $settings): array
    {
        if (!static::isEnabled($settings) || empty($settings['driverMustProvideOnboardDocuments'])) {
            return [];
        }

        return array_values(array_filter((array) ($settings['requiredOnboardDocuments'] ?? []), fn ($name) => is_string($name) && trim($name) !== ''));
    }

    /**
     * The settings in the form the driver app reads.
     */
    public static function summary(Company $company): array
    {
        $settings  = static::settings($company->uuid);
        $documents = static::documents($settings);
        $method    = static::method($settings);

        return [
            'organization'       => ['id' => $company->public_id, 'name' => $company->name, 'logo_url' => $company->logo_url],
            'enabled'            => static::isEnabled($settings),
            'method'             => $method,
            'self_sign_up'       => $method === 'button',
            'invite_only'        => $method === 'invite',
            'documents_required' => count($documents) > 0,
            'documents'          => $documents,
        ];
    }

    public static function normalizeCode(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    /** `ABCD-2345`: two groups of four, which is how it is shown and read out. */
    public static function formatCode(string $code): string
    {
        $code = static::normalizeCode($code);

        return strlen($code) > 4 ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
    }

    public static function generateCode(): string
    {
        $alphabet = static::CODE_ALPHABET;
        do {
            $code = '';
            for ($i = 0; $i < static::CODE_LENGTH; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Setting::where('key', static::CODE_PREFIX . $code)->exists());

        return $code;
    }

    /**
     * The organization's join code, formatted. Null when it has none yet.
     */
    public static function joinCode(string $companyUuid): ?string
    {
        $code = static::settings($companyUuid)['joinCode'] ?? null;

        return is_string($code) && $code !== '' ? static::formatCode($code) : null;
    }

    public static function ensureJoinCode(string $companyUuid): string
    {
        return static::joinCode($companyUuid) ?? static::rotateJoinCode($companyUuid);
    }

    /**
     * Replaces the organization's join code. The old one stops working at once.
     */
    public static function rotateJoinCode(string $companyUuid): string
    {
        $settings = static::settings($companyUuid);
        $old      = static::normalizeCode($settings['joinCode'] ?? '');
        if ($old !== '') {
            Setting::where('key', static::CODE_PREFIX . $old)->delete();
        }

        $code                 = static::generateCode();
        $settings['joinCode'] = $code;
        static::saveSettings($companyUuid, $settings);
        Setting::configure(static::CODE_PREFIX . $code, $companyUuid);

        return static::formatCode($code);
    }

    /**
     * The organization a join code belongs to, while that code is still its current one.
     */
    public static function companyForCode(?string $code): ?Company
    {
        $code = static::normalizeCode($code);
        if (strlen($code) !== static::CODE_LENGTH) {
            return null;
        }

        $companyUuid = Setting::where('key', static::CODE_PREFIX . $code)->value('value');
        if (!is_string($companyUuid) || !Str::isUuid($companyUuid)) {
            return null;
        }

        if (static::normalizeCode(static::settings($companyUuid)['joinCode'] ?? '') !== $code) {
            return null;
        }

        return Company::where('uuid', $companyUuid)->first();
    }
}
