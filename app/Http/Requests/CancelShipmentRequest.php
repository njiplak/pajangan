<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CancelShipmentRequest extends FormRequest
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
        return [
            'cancellation_reason_code' => ['required', 'string', Rule::in(['change_courier', 'pickup_delay', 'change_address', 'others'])],
            'cancellation_reason' => ['required_if:cancellation_reason_code,others', 'nullable', 'string', 'max:500'],
        ];
    }
}
