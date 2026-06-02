<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InitiatePaymobSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'integrations.paymob.enabled' => true,
            'integrations.paymob.base_url' => 'https://sandbox.paymob.test',
            'integrations.paymob.api_key' => 'TEST-API-KEY',
            'integrations.paymob.integration_id' => '123456',
            'integrations.paymob.iframe_id' => '999',
            'integrations.paymob.currency' => 'EGP',
            'integrations.paymob.hmac_secret' => 'TEST-HMAC-SECRET',
        ]);
    }

    /** @return array{0:Tenant,1:Invoice,2:string} */
    private function tenantWithInvoice(float $balance = 750.0): array
    {
        ensureAllPropertiesAsset();
        $asset = makeAsset();
        $tenant = Tenant::create([
            'name' => 'Cafe Crema',
            'email' => 'pay-' . uniqid() . '@t.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ]);
        $unit = makeUnit($asset);
        $lease = makeLease($unit, $tenant);
        $invoice = makeInvoice($lease, ['total' => $balance, 'balance' => $balance]);

        $token = $tenant->createToken('test-device', ['tenant:*'])->plainTextToken;

        return [$tenant, $invoice, $token];
    }

    private function fakePaymobOnce(int $orderId, string $payKey): void
    {
        Http::fake([
            'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
            'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => $orderId]),
            'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => $payKey]),
        ]);
    }

    public function test_authenticated_tenant_gets_a_session_with_token_and_iframe_url(): void
    {
        [$tenant, $invoice, $token] = $this->tenantWithInvoice(1500);
        $this->fakePaymobOnce(orderId: 5555, payKey: 'PAY-KEY-Z');

        $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )
            ->assertOk()
            ->assertJsonPath('data.payment_token', 'PAY-KEY-Z')
            ->assertJsonPath('data.iframe_url', 'https://sandbox.paymob.test/api/acceptance/iframes/999?payment_token=PAY-KEY-Z')
            ->assertJsonPath('data.iframe_id', '999')
            ->assertJsonPath('data.order_id', 5555)
            ->assertJsonPath('data.reused', false)
            ->assertJsonStructure(['data' => ['payment_id', 'expires_at']]);

        $this->assertSame('initiated',
            Payment::where('gateway_transaction_id', 'paymob:order:5555')->value('status'));
    }

    public function test_unauthenticated_request_is_rejected_with_401(): void
    {
        [, $invoice] = $this->tenantWithInvoice();

        $this->postJson("/api/v1/invoices/{$invoice->id}/paymob-session")
            ->assertStatus(401);
    }

    public function test_tenant_cannot_initiate_for_another_tenants_invoice(): void
    {
        [, $invoice] = $this->tenantWithInvoice();
        // A second, unrelated tenant tries to pay the first tenant's invoice.
        $intruder = Tenant::create([
            'name' => 'Intruder',
            'email' => 'intruder-' . uniqid() . '@t.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ]);
        $intruderToken = $intruder->createToken('device', ['tenant:*'])->plainTextToken;

        $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$intruderToken}"],
        )->assertStatus(403);

        $this->assertSame(0, Payment::where('gateway', 'paymob')->count(),
            'Foreign-tenant attempt must not create a Payment row.');
    }

    public function test_invoice_with_zero_balance_returns_422(): void
    {
        [$tenant, $invoice, $token] = $this->tenantWithInvoice();
        $invoice->update(['paid_amount' => $invoice->total, 'balance' => 0, 'status' => 'paid']);

        $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'no_balance');
    }

    public function test_cancelled_invoice_returns_422(): void
    {
        [, $invoice, $token] = $this->tenantWithInvoice();
        $invoice->update(['status' => 'cancelled']);

        $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )
            ->assertStatus(422)
            ->assertJsonPath('error', 'invoice_not_payable');
    }

    public function test_paymob_disabled_returns_409(): void
    {
        config(['integrations.paymob.enabled' => false]);
        [, $invoice, $token] = $this->tenantWithInvoice();

        $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )
            ->assertStatus(409)
            ->assertJsonPath('error', 'paymob_disabled');
    }

    public function test_paymob_upstream_failure_returns_502(): void
    {
        [, $invoice, $token] = $this->tenantWithInvoice();
        Http::fake([
            'sandbox.paymob.test/api/auth/tokens' => Http::response(['detail' => 'bad key'], 401),
        ]);

        $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )
            ->assertStatus(502)
            ->assertJsonPath('error', 'paymob_upstream_error');
    }

    public function test_repeated_taps_reuse_the_same_paymob_order_within_the_window(): void
    {
        [, $invoice, $token] = $this->tenantWithInvoice();
        $this->fakePaymobOnce(orderId: 6001, payKey: 'PAY-KEY-RE');

        $first = $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )->assertOk()->json('data');

        $second = $this->postJson(
            "/api/v1/invoices/{$invoice->id}/paymob-session",
            [],
            ['Authorization' => "Bearer {$token}"],
        )->assertOk()->json('data');

        $this->assertSame($first['payment_id'], $second['payment_id']);
        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertTrue($second['reused']);
        $this->assertSame(1, Payment::where('gateway', 'paymob')->count(),
            'Reuse window must not produce a second Payment row.');
    }

    public function test_throttle_kicks_in_after_5_fresh_calls(): void
    {
        // Each call needs a distinct invoice so the idempotency layer doesn't
        // short-circuit before the throttle. We give the same tenant six
        // separate outstanding invoices.
        ensureAllPropertiesAsset();
        $asset = makeAsset();
        $tenant = Tenant::create([
            'name' => 'Throttle Test',
            'email' => 'thr-' . uniqid() . '@t.local',
            'password' => Hash::make('secret-pw'),
            'status' => 'active',
            'type' => 'company',
        ]);
        $token = $tenant->createToken('dev', ['tenant:*'])->plainTextToken;

        $invoices = [];
        $unit = makeUnit($asset);
        $lease = makeLease($unit, $tenant);
        for ($i = 0; $i < 6; $i++) {
            $invoices[] = makeInvoice($lease, ['total' => 100, 'balance' => 100]);
        }

        // The HTTP fake auto-recycles for every request.
        Http::fake([
            'sandbox.paymob.test/api/auth/tokens' => Http::response(['token' => 'BEARER']),
            'sandbox.paymob.test/api/ecommerce/orders' => Http::response(['id' => random_int(10000, 99999)]),
            'sandbox.paymob.test/api/acceptance/payment_keys' => Http::response(['token' => 'PAY']),
        ]);

        $statuses = [];
        foreach ($invoices as $invoice) {
            $statuses[] = $this->postJson(
                "/api/v1/invoices/{$invoice->id}/paymob-session",
                [],
                ['Authorization' => "Bearer {$token}"],
            )->getStatusCode();
        }

        // First 5 succeed (200), the 6th is throttled (429).
        $this->assertSame(200, $statuses[0]);
        $this->assertSame(200, $statuses[4]);
        $this->assertSame(429, $statuses[5]);
    }
}
