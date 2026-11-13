<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\OrderConfig;
use Fleetbase\Models\CustomField;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Custom field values sent with an order through the API, as `custom_fields: {name: value}`.
 * Names are the config's custom field names (or labels); a value for a field the config doesn't
 * have is ignored. Each field's own `validation_rules` and `required` flag are applied.
 */
class OrderCustomFields
{
    /**
     * The first problem with the values for the config's fields, or null when they are fine.
     * A request that sends no custom fields isn't checked, so clients that never sent them keep
     * working; one that sends any must send each required field.
     */
    public static function validate(OrderConfig $config, array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $fields = $config->customFields()->get();
        if ($fields->isEmpty()) {
            return null;
        }

        $data  = [];
        $rules = [];
        foreach ($fields as $field) {
            $own = array_filter(is_array($field->validation_rules) ? $field->validation_rules : [], fn ($rule) => is_string($rule) && !in_array($rule, ['required', 'nullable'], true));

            $data[$field->name]  = static::valueFor($field, $values);
            $rules[$field->name] = array_values(array_merge([$field->required ? 'required' : 'nullable'], $own));
        }

        $validator = Validator::make($data, $rules, [], $fields->mapWithKeys(fn (CustomField $field) => [$field->name => $field->label ?: $field->name])->all());

        return $validator->fails() ? $validator->errors()->first() : null;
    }

    /**
     * Store the values on the order, for fields its config has.
     */
    public static function apply(Order $order, array $values): int
    {
        if ($values === []) {
            return 0;
        }

        // The same path the console saves custom field values through, with each value's type.
        $rows = [];
        foreach ($order->customFields()->get() as $field) {
            $value = static::valueFor($field, $values);
            if ($value !== null) {
                $rows[] = ['custom_field_uuid' => $field->uuid, 'value' => $value, 'value_type' => $field->type ?: 'text'];
            }
        }

        if ($rows === []) {
            return 0;
        }

        $result = $order->syncCustomFieldValues($rows);

        return (int) ($result['created'] ?? 0) + (int) ($result['updated'] ?? 0);
    }

    /**
     * The value given for the field under its name, its label, or its label in snake case.
     */
    protected static function valueFor(CustomField $field, array $values): mixed
    {
        foreach (array_filter([$field->name, $field->label, $field->label ? Str::snake($field->label) : null]) as $key) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        }

        return null;
    }
}
