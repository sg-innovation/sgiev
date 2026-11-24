<?php

namespace App\Http\Requests;

use App\Enums\ConnectorType;
use App\Support\IndianStates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'registration_no' => strtoupper(preg_replace('/[\s-]+/', '', (string) $this->input('registration_no'))),
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
            'phone' => preg_replace('/\D/', '', (string) $this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'charger_id' => ['required', 'integer'],
            'connector_id' => ['nullable', 'integer'],
            'start' => ['required', 'date'],
            'duration' => ['required', 'integer', 'min:15', 'max:720'],

            'make' => ['required', 'string', 'max:60'],
            'model' => ['required', 'string', 'max:80'],
            'registration_no' => ['required', 'string', 'regex:/^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{4}$|^[0-9]{2}BH[0-9]{4}[A-Z]{1,2}$/'],
            'battery_kwh' => ['required', 'decimal:0,2', 'between:1,200'],
            'connector' => ['required', Rule::enum(ConnectorType::class)],
            'battery_from' => ['required', 'integer', 'between:0,99'],
            'battery_to' => ['required', 'integer', 'between:1,100', 'gt:battery_from'],
            'save_vehicle' => ['boolean'],

            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'state_code' => ['required', Rule::in(array_keys(IndianStates::STATES))],

            'rule_set_id' => ['required', 'integer'],
            'accept_rules' => ['accepted'],
            'coupon_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'registration_no.regex' => 'Enter a valid Indian registration number, e.g. MH12AB1234 or 22BH1234AA.',
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'accept_rules.accepted' => 'You must read and accept the station rules to book.',
            'battery_to.gt' => 'Target charge must be higher than your current charge.',
        ];
    }
}
