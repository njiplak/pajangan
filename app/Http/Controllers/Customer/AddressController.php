<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Service\Customer\AddressBook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AddressController extends Controller
{
    public function __construct(private readonly AddressBook $book) {}

    public function index()
    {
        return Inertia::render('storefront/account/addresses', [
            'addresses' => $this->customer()->addresses()
                ->orderByDesc('is_default')->latest()->get()
                ->map(fn (CustomerAddress $a) => self::present($a))->values(),
            'max' => AddressBook::MAX_ADDRESSES,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $this->book->create($this->customer(), $data, (bool) $request->boolean('is_default'));

        return back();
    }

    public function update(Request $request, int $address)
    {
        $model = $this->owned($address);

        $this->book->update($model, $this->validated($request));

        if ($request->boolean('is_default')) {
            $this->book->makeDefault($this->customer(), $model);
        }

        return back();
    }

    public function makeDefault(int $address)
    {
        $this->book->makeDefault($this->customer(), $this->owned($address));

        return back();
    }

    public function destroy(int $address)
    {
        $this->book->delete($this->customer(), $this->owned($address));

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CustomerAddress $address): array
    {
        return $address->only([
            'id', 'label', 'recipient_name', 'phone', 'address', 'city', 'province',
            'postal_code', 'destination_area_id', 'destination_area_name', 'is_default',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:1000'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'destination_area_id' => ['required', 'string', 'max:255'],
            'destination_area_name' => ['required', 'string', 'max:255'],
        ], [
            'destination_area_id.required' => 'Pilih kecamatan/kota tujuan dari daftar.',
        ]);
    }

    /**
     * Scoped to the signed-in customer: another customer's address id is
     * a 404, not a permission error that confirms it exists.
     */
    private function owned(int $id): CustomerAddress
    {
        return $this->customer()->addresses()->findOrFail($id);
    }

    private function customer(): Customer
    {
        /** @var Customer */
        return Auth::guard('customer')->user();
    }
}
