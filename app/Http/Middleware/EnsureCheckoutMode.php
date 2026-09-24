<?php

namespace App\Http\Middleware;

use App\Contract\Setting\SettingContract;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCheckoutMode
{
    public function __construct(private readonly SettingContract $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = $this->settings->allAsKeyValue()['storefront_mode'] ?? 'checkout';

        if ($mode === 'display') {
            // A fetch()-driven endpoint (area search, rate quotes) gets a
            // JSON error it can show inline; a page load gets sent to an
            // explanation instead of a bare 404.
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Toko sedang tidak menerima pesanan.',
                ], 503);
            }

            return redirect()->route('checkout.closed');
        }

        return $next($request);
    }
}
