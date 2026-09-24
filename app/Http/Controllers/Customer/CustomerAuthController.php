<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class CustomerAuthController extends Controller
{
    public static function googleEnabled(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    public function login()
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('account.index');
        }

        return Inertia::render('storefront/account/login', [
            'googleEnabled' => self::googleEnabled(),
        ]);
    }

    public function redirect()
    {
        if (! self::googleEnabled()) {
            return redirect()->route('customer.login')
                ->withErrors(['google' => 'Masuk dengan Google belum tersedia.']);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request)
    {
        if (! self::googleEnabled()) {
            return redirect()->route('customer.login');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Includes the customer pressing "cancel" on Google's screen.
            Log::warning("Google sign-in failed: {$e->getMessage()}");

            return redirect()->route('customer.login')
                ->withErrors(['google' => 'Gagal masuk dengan Google. Silakan coba lagi.']);
        }

        $email = mb_strtolower(trim((string) $google->getEmail()));
        $verified = (bool) data_get($google->user, 'email_verified', false);

        if ($email === '') {
            return redirect()->route('customer.login')
                ->withErrors(['google' => 'Akun Google Anda tidak memiliki alamat email.']);
        }

        $customer = Customer::query()->where('google_id', $google->getId())->first()
            ?? Customer::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($customer && $customer->google_id === null && ! $verified) {
            // Attaching a Google identity to an existing account on an
            // unverified email would let anyone claim it by typing the
            // address into a Google account.
            return redirect()->route('customer.login')
                ->withErrors(['google' => 'Email akun Google Anda belum terverifikasi.']);
        }

        $customer = DB::transaction(function () use ($customer, $google, $email, $verified) {
            $customer ??= new Customer(['email' => $email]);

            $customer->fill([
                'name' => $google->getName() ?: ($customer->name ?: $email),
                'google_id' => $google->getId(),
                'avatar_url' => $google->getAvatar(),
            ]);

            if ($verified && ! $customer->email_verified_at) {
                $customer->email_verified_at = now();
            }

            $customer->save();

            if ($customer->email_verified_at) {
                // Guest orders placed under this address become part of
                // the account, so a first sign-in shows the full history.
                Order::query()
                    ->whereNull('customer_id')
                    ->whereRaw('LOWER(customer_email) = ?', [$email])
                    ->update(['customer_id' => $customer->id]);
            }

            return $customer;
        });

        Auth::guard('customer')->login($customer, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('account.index'));
    }

    public function logout(Request $request)
    {
        // Only the customer guard: invalidating the whole session would also
        // empty their cart and sign out a staff member on the same browser.
        Auth::guard('customer')->logout();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
