<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Registers a minimal always-configured gateway, so a test that drives an
 * order through checkout.store but isn't exercising payment itself doesn't
 * have to fake one just to get past CheckoutController's "can this order
 * ever be paid" guard. A test that does care about payment registers and
 * activates its own gateway by name, which takes priority over this one.
 */
function fakeDefaultPaymentGateway(): void
{
    app(\App\Service\Payment\PaymentGatewayManager::class)->register(new class implements \App\Contract\Payment\PaymentGatewayContract
    {
        public function key(): string
        {
            return 'test-default-gateway';
        }

        public function label(): string
        {
            return 'Test Default Gateway';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function listChannels(): array
        {
            return [];
        }

        public function createTransaction(\App\Models\Order $order, int $amount, array $options = []): array
        {
            // No redirect_url: checkout falls through to the signed
            // confirmation page, same as this gateway not existing at all
            // except that the "can this order ever be paid" guard passes.
            // A test that cares about the gateway-redirect behavior itself
            // registers and activates its own gateway.
            return [
                'reference' => 'TEST-'.$order->order_number,
                'redirect_url' => null,
                'token' => null,
                'expires_at' => null,
                'raw' => ['amount' => $amount],
            ];
        }

        public function verifyNotification(string $rawBody, array $headers = []): bool
        {
            return true;
        }

        public function parseNotification(array $payload): array
        {
            return ['reference' => '', 'status' => \App\Contract\Payment\PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }

        public function getStatus(string $reference): array
        {
            return ['reference' => $reference, 'status' => \App\Contract\Payment\PaymentStatus::PENDING, 'amount' => 0, 'paid_at' => null, 'raw' => []];
        }
    });
}
