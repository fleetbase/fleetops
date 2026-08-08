<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\FleetOps\Models\FuelProviderTransaction;
use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreateFuelTransactionRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules(): array
    {
        return [
            'provider'                => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'provider_transaction_id' => [Rule::requiredIf($this->isMethod('POST')), 'string', $this->uniqueProviderTransactionIdRule()],
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

    public function messages(): array
    {
        return [
            'provider_transaction_id.unique' => 'A fuel transaction with this provider transaction id already exists for this provider.',
        ];
    }

    /**
     * Mirrors the fuel_provider_txn_company_provider_unique index.
     *
     * Provider transaction ids are natural idempotency keys, so a re-sent batch
     * used to raise an unhandled UniqueConstraintViolationException — an HTTP 500
     * rather than a duplicate signal the client could act on.
     *
     * @return \Illuminate\Validation\Rules\Unique
     */
    protected function uniqueProviderTransactionIdRule()
    {
        $provider = $this->resolveProvider();

        $rule = Rule::unique('fuel_provider_transactions', 'provider_transaction_id')
            ->where(function ($query) use ($provider) {
                $query->where('company_uuid', session('company'));
                $query->where('provider', $provider);

                return $query->whereNull('deleted_at');
            });

        // On PUT the record re-sends its own provider_transaction_id. The v1 route
        // parameter is a public_id (fuel_provider_transaction_xxxxx), not a uuid.
        $id = $this->route('id');
        if (!$this->isMethod('POST') && filled($id)) {
            $rule->ignore($id, 'public_id');
        }

        return $rule;
    }

    /**
     * The uniqueness scope is per provider, but `provider` is only required on
     * POST — a PUT may change the transaction id while leaving the provider out of
     * the body. Fall back to the stored value so the scope is never null, which
     * would otherwise compare against the wrong set of rows.
     */
    protected function resolveProvider(): ?string
    {
        if ($this->filled('provider')) {
            return $this->input('provider');
        }

        $id = $this->route('id');
        if (blank($id)) {
            return null;
        }

        return FuelProviderTransaction::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('public_id', $id)->orWhere('uuid', $id);
            })
            ->value('provider');
    }
}
