<?php

namespace App\Http\Requests;

use App\Service\Payment\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $gatewayKeys = app(PaymentGatewayManager::class)->all();

        return [
            'payment_active_gateway' => ['required', 'string', Rule::in($gatewayKeys)],
            'payment_default_channel' => ['nullable', 'string', 'max:100'],
            'payment_admin_fee_borne_by' => ['required', 'string', Rule::in(['customer', 'merchant'])],
            'payment_admin_fee_flat' => ['required_if:payment_admin_fee_borne_by,customer', 'nullable', 'integer', 'min:0'],
            // Per-(gateway, channel) fee overrides: { [gateway]: { [channel]: { flat, percent } } }.
            'payment_channel_fees' => ['nullable', 'array'],
            'payment_channel_fees.*' => ['array'],
            'payment_channel_fees.*.*' => ['array'],
            'payment_channel_fees.*.*.flat' => ['nullable', 'integer', 'min:0'],
            'payment_channel_fees.*.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
