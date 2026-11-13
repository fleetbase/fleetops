<?php

namespace Fleetbase\FleetOps\Flow;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Str;

class Condition extends FlowResource
{
    /**
     * Evaluate the condition against an order.
     *
     * The field is read from the order (`Order::resolveDynamicValue()`: payload shortcuts such as
     * `pickup.city`, attributes, the config's custom fields, meta, then relations). Strings compare
     * without case. A list operator (`in`, `notIn`) takes the value as a list, or a comma
     * separated string; `has` and `doesntHave` look for the value in a field that is a list.
     *
     * @param Order $order the order to evaluate the condition against
     *
     * @return bool the result of the condition evaluation
     *
     * @throws \Exception if the operator is unknown
     */
    public function eval(Order $order)
    {
        $operator    = $this->operator;
        $value       = static::normalize($this->value);
        $field       = (string) $this->field;
        $resolved    = $order->resolveDynamicValue($field);
        // `resolveDynamicValue()` hands back the field name itself when the order has no such value.
        $actualValue = static::normalize($resolved === $field ? null : $resolved);

        switch ($operator) {
            case 'exists':
                return $actualValue !== null && $actualValue !== '' && $actualValue !== [];

            case 'in':
                return !is_array($actualValue) && in_array($actualValue, static::toList($value), false);

            case 'notIn':
                return !in_array($actualValue, static::toList($value), false);

            case 'has':
                return in_array($value, static::toList($actualValue), false);

            case 'doesntHave':
                return !in_array($value, static::toList($actualValue), false);

            case 'contains':
                return is_string($value) && is_string($actualValue) && Str::contains($actualValue, $value);

            case 'beginsWith':
                return is_string($value) && is_string($actualValue) && Str::startsWith($actualValue, $value);

            case 'endsWith':
                return is_string($value) && is_string($actualValue) && Str::endsWith($actualValue, $value);

            case 'equal':
                return $actualValue == $value;

            case 'and':
                return $actualValue && $value;

            case 'or':
                return $actualValue || $value;

            case 'not':
                return !$actualValue;

            case 'notEqual':
                return $actualValue != $value;

            case 'greaterThan':
                return $actualValue > $value;

            case 'greaterThanOrEqual':
                return $actualValue >= $value;

            case 'lessThan':
                return $actualValue < $value;

            case 'lessThanOrEqual':
                return $actualValue <= $value;

            default:
                throw new \Exception("Unknown operator: {$operator}");
        }
    }

    /**
     * Lower-case strings, and each string in a list, so comparisons ignore case.
     */
    protected static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return strtolower(trim($value));
        }

        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return array_map(fn ($item) => is_string($item) ? strtolower(trim($item)) : $item, $value);
        }

        return $value;
    }

    protected static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_string($value) && str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }

        return $value === null ? [] : [$value];
    }
}
