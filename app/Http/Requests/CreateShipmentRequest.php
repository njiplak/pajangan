<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
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
            'destination_area_id' => ['required', 'string', 'max:255'],
            'destination_area_name' => ['nullable', 'string', 'max:255'],
            'weight_gram' => ['required', 'integer', 'min:1'],
            'courier_code' => ['required', 'string', 'max:255'],
            'courier_service_code' => ['required', 'string', 'max:255'],
        ];
    }
}
