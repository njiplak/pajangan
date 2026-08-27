<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderShippingRequest extends FormRequest
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
            'shipping_cost' => ['required', 'integer', 'min:0'],
            'shipping_area_id' => ['nullable', 'string', 'max:255'],
            'shipping_area_name' => ['nullable', 'string', 'max:255'],
            'courier_code' => ['required', 'string', 'max:255'],
            'courier_name' => ['nullable', 'string', 'max:255'],
            'courier_service' => ['required', 'string', 'max:255'],
            'courier_etd' => ['nullable', 'string', 'max:255'],
            'tracking_number' => ['nullable', 'string', 'max:255'],
        ];
    }
}
