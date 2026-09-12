<?php

namespace Fleetbase\FleetOps\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * A photo as the driver app sends it: either a URL to an uploaded file, or the
 * image bytes base64 encoded — bare, or as a `data:image/...;base64,` URI.
 *
 * Deliberately not a regex rule. A DVIR photo is easily a megabyte of base64,
 * and running a backtracking pattern over that many characters is the kind of
 * thing that only fails on a real handset. `base64_decode` in strict mode is
 * linear and rejects anything outside the alphabet.
 */
class Base64OrUrl implements Rule
{
    /**
     * Determine if the validation rule passes.
     *
     * @param string $attribute
     */
    public function passes($attribute, $value): bool
    {
        return static::isBase64OrUrl($value);
    }

    public static function isBase64OrUrl(mixed $value): bool
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        if (preg_match('#^data:[\w.+-]+/[\w.+-]+;base64,#i', $value, $prefix)) {
            $value = substr($value, strlen($prefix[0]));
        }

        $stripped = preg_replace('/\s+/', '', $value);

        return $stripped !== '' && base64_decode($stripped, true) !== false;
    }

    /**
     * Get the validation error message.
     */
    public function message(): string
    {
        return 'The :attribute must be a base64 encoded image or a URL.';
    }
}
