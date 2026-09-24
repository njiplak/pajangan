<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProducerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'story' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'file', 'image', 'max:5120'],
            'removed_photo' => ['nullable', 'integer'],
        ];
    }
}
