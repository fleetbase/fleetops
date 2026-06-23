<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateFuelTransactionRequest extends FleetbaseRequest
{
    public function authorize()
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules()
    {
        return [
            'provider'                => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'provider_transaction_id' => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'connection'              => ['nullable', 'string'],
            'fuel_report'             => ['nullable', 'string'],
            'vehicle'                 => ['nullable', 'string'],
            'driver'                  => ['nullable', 'string'],
            'order'                   => ['nullable', 'string'],
            'provider_vehicle_id'     => ['nullable', 'string'],
            'vehicle_card_id'         => ['nullable', 'string'],
            'internal_number'         => ['nullable', 'string'],
            'structure_number'        => ['nullable', 'string'],
            'plate_number'            => ['nullable', 'string'],
            'vin'                     => ['nullable', 'string'],
            'serial_number'           => ['nullable', 'string'],
            'call_sign'               => ['nullable', 'string'],
            'trip_number'             => ['nullable', 'string'],
            'station_name'            => ['nullable', 'string'],
            'station_latitude'        => ['nullable', 'numeric'],
            'station_longitude'       => ['nullable', 'numeric'],
            'transaction_at'          => ['nullable', 'date'],
            'volume'                  => ['nullable', 'numeric'],
            'metric_unit'             => ['nullable', 'string'],
            'amount'                  => ['nullable'],
            'currency'                => ['nullable', 'string', 'size:3'],
            'odometer'                => ['nullable', 'numeric'],
            'sync_status'             => ['nullable', 'string'],
            'matched_at'              => ['nullable', 'date'],
            'normalized_payload'      => ['nullable', 'array'],
            'raw_payload'             => ['nullable', 'array'],
            'meta'                    => ['nullable', 'array'],
        ];
    }
}
