<?php

namespace Fleetbase\FleetOps\Http\Requests;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Illuminate\Validation\Rule;

class CreatePartRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return request()->session()->has('api_credential') || request()->session()->has('is_sanctum_token');
    }

    public function rules(): array
    {
        return [
            'sku'              => ['nullable', 'string', $this->uniqueSkuRule()],
            'name'             => [Rule::requiredIf($this->isMethod('POST')), 'string'],
            'manufacturer'     => ['nullable', 'string'],
            'model'            => ['nullable', 'string'],
            'serial_number'    => ['nullable', 'string'],
            'barcode'          => ['nullable', 'string'],
            'description'      => ['nullable', 'string'],
            'quantity_on_hand' => ['nullable', 'integer', 'min:0'],
            'unit_cost'        => ['nullable'],
            'msrp'             => ['nullable'],
            'currency'         => ['nullable', 'string', 'size:3'],
            'asset_type'       => ['nullable', 'string'],
            'asset'            => ['nullable', 'required_with:asset_type', 'string'],
            'type'             => ['nullable', 'string'],
            'status'           => ['nullable', 'string'],
            'vendor'           => ['nullable', 'string'],
            'warranty'         => ['nullable', 'string'],
            'photo'            => ['nullable', 'string'],
            'specs'            => ['nullable', 'array'],
            'meta'             => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'A part with this SKU already exists.',
        ];
    }

    /**
     * Mirrors the parts_company_uuid_active_sku_unique index.
     *
     * Without this rule a duplicate SKU reached the driver and came back as an
     * unhandled UniqueConstraintViolationException — an HTTP 500 with no field
     * attribution, since public v1 controllers have no QueryException catch.
     *
     * `nullable` runs first, so this never fires on a null SKU; that matches the
     * nullable column and MySQL's tolerance of repeated NULLs in a unique index.
     *
     * @return \Illuminate\Validation\Rules\Unique
     */
    protected function uniqueSkuRule()
    {
        $rule = Rule::unique('parts', 'sku')
            ->where(function ($query) {
                $query->where('company_uuid', session('company'));

                return $query->whereNull('deleted_at');
            });

        // On PUT the record re-sends its own SKU, so it must not collide with
        // itself. The v1 route parameter is a public_id (part_xxxxx), not a uuid.
        $id = $this->route('id');
        if (!$this->isMethod('POST') && filled($id)) {
            $rule->ignore($id, 'public_id');
        }

        return $rule;
    }
}
