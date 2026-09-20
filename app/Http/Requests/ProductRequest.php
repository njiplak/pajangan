<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
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
        // A bundle carries no stock or weight of its own — both are derived
        // from its components — so those inputs only apply to a plain product.
        $isBundle = $this->boolean('is_bundle');

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'stock' => [Rule::requiredIf(! $isBundle), 'integer', 'min:0'],
            'weight_gram' => [Rule::requiredIf(! $isBundle), 'integer', 'min:1'],
            'producer_name' => ['nullable', 'string', 'max:255'],
            'producer_region' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_bundle' => ['nullable', 'boolean'],
            'bundle_items' => [Rule::requiredIf($isBundle), 'array', ...($isBundle ? ['min:1'] : [])],
            'bundle_items.*.product_id' => [
                'required_with:bundle_items',
                'integer',
                'distinct',
                // Excluding bundles keeps the composition one level deep, so
                // availableStock() never has to recurse — and makes a bundle
                // containing itself impossible.
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('is_bundle', false)),
            ],
            'bundle_items.*.quantity' => ['required_with:bundle_items', 'integer', 'min:1'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'max:5120'],
            'removed_images' => ['nullable', 'array'],
            'removed_images.*' => ['integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'bundle_items.required' => 'Paket harus berisi minimal satu produk.',
            'bundle_items.min' => 'Paket harus berisi minimal satu produk.',
            'bundle_items.*.product_id.exists' => 'Produk yang dipilih tidak tersedia, atau merupakan paket lain.',
            'bundle_items.*.product_id.distinct' => 'Produk yang sama tidak boleh dipilih dua kali dalam satu paket.',
        ];
    }
}
