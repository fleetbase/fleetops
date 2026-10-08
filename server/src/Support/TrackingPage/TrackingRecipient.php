<?php

namespace Fleetbase\FleetOps\Support\TrackingPage;

use Fleetbase\FleetOps\Models\Contact;
use Fleetbase\FleetOps\Models\Vendor;
use Fleetbase\FleetOps\Support\TrackingScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a one-time code goes to for a tracking scope, and how their contact details are
 * shown on the page: masked, and never editable, so a code can only reach details the
 * company already holds.
 */
class TrackingRecipient
{
    public const CHANNEL_SMS   = 'sms';
    public const CHANNEL_EMAIL = 'email';

    public function __construct(
        public readonly ?Model $customer,
        public readonly ?string $email,
        public readonly ?string $phone,
    ) {
    }

    /**
     * The scope's customer (a Contact or a Vendor) and the contact details a code can use.
     *
     * @param string|null $fallbackPhone the stop place's phone, used when the customer has none
     */
    public static function for(?TrackingScope $scope, ?string $fallbackPhone = null, ?Model $customer = null): self
    {
        $customer ??= $scope ? static::loadCustomer($scope) : null;

        $email = static::validEmail(data_get($customer, 'email'));
        $phone = static::validPhone(data_get($customer, 'phone')) ?? static::validPhone($fallbackPhone);

        return new self($customer, $email, $phone);
    }

    protected static function loadCustomer(TrackingScope $scope): ?Model
    {
        $class = match (true) {
            is_a($scope->customer_type, Contact::class, true) => Contact::class,
            is_a($scope->customer_type, Vendor::class, true)  => Vendor::class,
            default                                          => null,
        };

        return $class ? $class::withoutGlobalScopes()->where('uuid', $scope->customer_uuid)->first() : null;
    }

    /**
     * The channels a code can go out on, given the company's settings and the instance.
     *
     * @return array<int, array{type: string, masked: string}>
     */
    public function channels(array $config, bool $smsAvailable): array
    {
        $channels = [];
        if ($this->phone && $smsAvailable && data_get($config, 'access.channels.sms', true)) {
            $channels[] = ['type' => self::CHANNEL_SMS, 'masked' => static::maskPhone($this->phone)];
        }

        if ($this->email && data_get($config, 'access.channels.email', true)) {
            $channels[] = ['type' => self::CHANNEL_EMAIL, 'masked' => static::maskEmail($this->email)];
        }

        return $channels;
    }

    public function destination(string $channel): ?string
    {
        return $channel === self::CHANNEL_SMS ? $this->phone : ($channel === self::CHANNEL_EMAIL ? $this->email : null);
    }

    /**
     * `•••• •• 4821`: only the last four digits.
     */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return '•••• •• ' . substr($digits, -4);
    }

    /**
     * `m•••@g•••.com`: the first letter of each part and the top-level domain.
     */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $tld              = str_contains($domain, '.') ? substr($domain, strrpos($domain, '.')) : '';

        return mb_substr($local, 0, 1) . '•••@' . mb_substr($domain, 0, 1) . '•••' . $tld;
    }

    protected static function validEmail(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    protected static function validPhone(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return strlen(preg_replace('/\D/', '', $value)) >= 6 ? $value : null;
    }
}
