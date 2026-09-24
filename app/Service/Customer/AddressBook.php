<?php

namespace App\Service\Customer;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's saved addresses, with exactly one default whenever any
 * exist. Both the address book and checkout write through here so that
 * invariant holds from either side.
 */
class AddressBook
{
    public const MAX_ADDRESSES = 20;

    public const FIELDS = [
        'label',
        'recipient_name',
        'phone',
        'address',
        'city',
        'province',
        'postal_code',
        'destination_area_id',
        'destination_area_name',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Customer $customer, array $data, bool $makeDefault = false): CustomerAddress
    {
        if ($customer->addresses()->count() >= self::MAX_ADDRESSES) {
            throw ValidationException::withMessages([
                'address' => 'Maksimal '.self::MAX_ADDRESSES.' alamat. Hapus salah satu untuk menambah yang baru.',
            ]);
        }

        return DB::transaction(function () use ($customer, $data, $makeDefault) {
            // The first address is the default whether asked or not.
            $makeDefault = $makeDefault || ! $customer->addresses()->exists();

            if ($makeDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $customer->addresses()->create(
                array_intersect_key($data, array_flip(self::FIELDS)) + ['is_default' => $makeDefault]
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        $address->update(array_intersect_key($data, array_flip(self::FIELDS)));

        return $address;
    }

    public function makeDefault(Customer $customer, CustomerAddress $address): void
    {
        DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
        });
    }

    public function delete(Customer $customer, CustomerAddress $address): void
    {
        DB::transaction(function () use ($customer, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                // Never leave a customer with addresses but no default.
                $customer->addresses()->latest('updated_at')->first()?->update(['is_default' => true]);
            }
        });
    }

    /**
     * Saves the address typed at checkout, unless the customer already
     * has that exact one — re-saving on every order would bury the book
     * in duplicates.
     *
     * @param  array<string, mixed>  $data
     */
    public function rememberFromCheckout(Customer $customer, array $data): ?CustomerAddress
    {
        $exists = $customer->addresses()
            ->where('recipient_name', $data['recipient_name'])
            ->where('phone', $data['phone'])
            ->where('address', $data['address'])
            ->where('destination_area_id', $data['destination_area_id'])
            ->exists();

        if ($exists || $customer->addresses()->count() >= self::MAX_ADDRESSES) {
            return null;
        }

        return $this->create($customer, $data);
    }
}
