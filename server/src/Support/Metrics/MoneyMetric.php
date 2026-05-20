<?php

namespace Fleetbase\FleetOps\Support\Metrics;

/**
 * Base for money-valued metrics. Enforces currency awareness so we never repeat
 * the legacy bug of summing mixed-currency rows.
 */
abstract class MoneyMetric extends AbstractMetric
{
    protected ?string $currencyOverride = null;

    public function inCurrency(?string $currency): static
    {
        $this->currencyOverride = $currency;

        return $this;
    }

    public function format(): string
    {
        return 'money';
    }

    public function currency(): string
    {
        return $this->currencyOverride
            ?? ($this->company->currency ?? 'USD');
    }
}
