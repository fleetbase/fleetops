<?php

namespace Fleetbase\FleetOps\Support\OrderConfig\Policies;

use Fleetbase\FleetOps\Support\AdhocDispatch\AdhocDispatch;
use Illuminate\Validation\Rule;

/**
 * How the config's adhoc orders are offered to nearby drivers. A setting left empty falls back
 * to the company's dispatch settings, then to Fleet-Ops' defaults.
 */
class AdhocDispatchPolicy extends Policy
{
    public const KEEP_OPEN = 'keep_open';
    public const CANCEL    = 'cancel';

    protected string $key         = 'dispatch.adhoc';
    protected string $label       = 'Adhoc dispatch';
    protected string $description = 'Whether orders customers place are offered to nearby drivers, how they are offered, and what happens when nobody accepts.';

    public function defaults(): array
    {
        return [
            // Orders placed through the customer API are adhoc.
            'customer_orders' => false,
            // A registered strategy name; empty uses the company's default.
            'strategy'        => null,
            // Seconds a driver has to accept a one-at-a-time offer; empty uses the company's.
            'offer_timeout'   => null,
            // Metres around the pickup to look for drivers; empty uses the order's or company's.
            'radius'          => null,
            // When every driver declined or let the offer run out: keep the order open for
            // the operator, or cancel it.
            'on_exhausted'    => self::KEEP_OPEN,
        ];
    }

    public function choices(): array
    {
        return [
            'strategy'     => array_map(fn ($strategy) => ['value' => $strategy['name'], 'label' => $strategy['label']], AdhocDispatch::describe()),
            'on_exhausted' => [
                ['value' => self::KEEP_OPEN, 'label' => 'Keep the order open for the operator'],
                ['value' => self::CANCEL, 'label' => 'Cancel the order'],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'customer_orders' => ['nullable', 'boolean'],
            'strategy'        => ['nullable', 'string', Rule::in(AdhocDispatch::names())],
            'offer_timeout'   => ['nullable', 'integer', 'min:' . AdhocDispatch::MIN_OFFER_TIMEOUT, 'max:' . AdhocDispatch::MAX_OFFER_TIMEOUT],
            'radius'          => ['nullable', 'integer', 'min:100', 'max:200000'],
            'on_exhausted'    => ['nullable', Rule::in([self::KEEP_OPEN, self::CANCEL])],
        ];
    }
}
