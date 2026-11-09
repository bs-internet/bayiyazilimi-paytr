<?php

declare(strict_types=1);

use App\Enums\Shop\CustomerType;
use App\Enums\Shop\OrderStatus;
use App\Enums\Shop\PaymentStatus;
use App\Enums\Shop\RefundStatus;
use App\Enums\Shop\SaleMode;
use App\Enums\Shop\StockMovementType;
use App\Enums\Status;
use App\Exceptions\Shop\ShopException;
use App\Http\Controllers\Shop\CheckoutController;
use App\Models\Carrier;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Shop\Basket;
use App\Services\Shop\LedgerService;
use App\Services\Shop\OrderService;
use App\Services\Shop\PaymentRefundService;
use App\Services\Shop\ShopSettings;
use App\Services\Shop\StockService;
use App\Support\Money;
use Bsinternet\Paytr\PaytrGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->withoutVite();
    Notification::fake();
    Carrier::query()->update(['status' => Status::Passive->value]);
    ShopSettings::save([
        'paytr_enabled' => '1', 'paytr_merchant_id' => '123456', 'paytr_merchant_key' => 'KEY123', 'paytr_merchant_salt' => 'SALT123',
        'paytr_test_mode' => '1', 'paytr_max_installment' => '6', 'paytr_fee_payer' => 'customer',
    ]);

    $this->admin = User::factory()->admin()->create();
    $this->admin->assignRole('Super Admin');
    $this->member = User::query()->where('email', 'user@user.com')->firstOrFail();
    $this->member->account->update(['type' => CustomerType::Dealer, 'dealer_group_id' => null, 'discount_rate' => 0]);

    $this->try = Currency::query()->where('code', 'TRY')->first();
    $this->product = Product::create([
        'sku' => 'PT-1', 'name' => 'Kart Ürünü', 'slug' => 'kart-urunu', 'price' => 100,
        'currency_id' => $this->try->id, 'tax_rate' => 20, 'sale_mode' => SaleMode::Cart, 'status' => Status::Active,
    ]);
    app(StockService::class)->change($this->product, Warehouse::query()->where('code', 'DEPO')->first(), 20, StockMovementType::In);

    $this->place = function (int $qty = 1): Order {
        $this->actingAs($this->member);
        $cart = Basket::cart();
        $cart->add($this->product->fresh(), $qty);

        return app(OrderService::class)->placeFromCart($cart, [
            'email' => 'bayi@example.com', 'phone' => '05550000000',
            'billing' => ['type' => 'corporate', 'name' => 'Ayşe Bayi', 'address' => 'Adres', 'city' => 'Bursa', 'district' => 'Nilüfer'],
            'same_address' => true, 'payment_method' => 'paytr',
        ], $this->member->fresh(), $this->try);
    };

    // PayTR bildirim imzası: base64(hmac_sha256(oid + salt + status + total, key)).
    $this->callback = fn (string $oid, string $status, int $total, int $installment = 1) => [
        'merchant_oid' => $oid, 'status' => $status, 'total_amount' => (string) $total, 'payment_amount' => (string) $total,
        'installment_count' => (string) $installment, 'currency' => 'TL', 'test_mode' => '1',
        'failed_reason_msg' => $status === 'failed' ? 'Yetersiz bakiye' : null,
        'hash' => base64_encode(hash_hmac('sha256', $oid.'SALT123'.$status.$total, 'KEY123', true)),
    ];
});

it('offers PayTR at checkout and opens the PayTR iframe for a placed order', function () {
    $methods = collect(CheckoutController::paymentMethods($this->member->fresh(), 120, $this->try));
    expect($methods->pluck('value')->all())->toContain('paytr')
        ->and($methods->firstWhere('value', 'paytr')['installments_url'])->toContain('/odeme/taksit/paytr');

    $order = ($this->place)();
    $payment = $order->payments()->sole();

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($order->paymentStart?->redirectUrl)->toContain('/odeme/kart/'.$payment->merchant_oid);

    Http::fake(['www.paytr.com/odeme/api/get-token' => Http::response(['status' => 'success', 'token' => 'TOKEN-XYZ'])]);

    $this->get((string) $order->paymentStart?->redirectUrl)->assertOk()->assertSee('odeme/guvenli/TOKEN-XYZ', false);

    Http::assertSent(fn ($request) => $request['merchant_oid'] === $payment->merchant_oid
        && (int) $request['payment_amount'] === 12000
        && (int) $request['max_installment'] === 6
        && $request['currency'] === 'TL');

    // İmzasız bağlantı açılmaz.
    $this->get('/odeme/kart/'.$payment->merchant_oid)->assertForbidden();
});

it('completes the order from a verified PayTR notification once and records the installment fee', function () {
    $order = ($this->place)();
    $payment = $order->payments()->sole();

    $this->post('/odeme/bildirim/paytr', ['merchant_oid' => $payment->merchant_oid, 'status' => 'success', 'total_amount' => '12000', 'hash' => 'sahte'])->assertStatus(400);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending);

    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 12600, 3))->assertOk()->assertSee('OK');
    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 12600, 3))->assertOk();

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->installment)->toBe(3)
        ->and($payment->fee_amount)->toEqualDecimal('6.0')
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and(Payment::query()->count())->toBe(1)
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('0.0');

    $this->get(route('shop.payment.return', ['gateway' => 'paytr', 'oid' => $payment->merchant_oid, 'r' => 'ok']))->assertRedirect();
});

it('fails the order and restores stock when PayTR reports a failed payment', function () {
    $order = ($this->place)(4);
    expect($this->product->fresh()->stock)->toBe(16);

    $this->post('/odeme/bildirim/paytr', ($this->callback)($order->payments()->sole()->merchant_oid, 'failed', 48000))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Failed)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Failed)
        ->and($this->product->fresh()->stock)->toBe(20);
});

it('does not fail a payment that a concurrent success notification already completed', function () {
    $order = ($this->place)(4);
    $stale = $order->payments()->sole(); // başarısızlık bildirimi işlenirken okunan (henüz Bekliyor) kayıt

    $this->post('/odeme/bildirim/paytr', ($this->callback)($stale->merchant_oid, 'success', 48000))->assertOk();

    $fail = new ReflectionMethod(PaytrGateway::class, 'fail');
    $fail->invoke(app(PaytrGateway::class), $stale, 'Yetersiz bakiye');

    expect($stale->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($order->fresh()->status)->toBe(OrderStatus::Processing)
        ->and($this->product->fresh()->stock)->toBe(16);
});

it('lists installment options with the customer paying the fee and filters by BIN', function () {
    Http::fake([
        'www.paytr.com/odeme/taksit-oranlari' => Http::response(['status' => 'success', 'oranlar' => [
            'world' => ['taksit_2' => 2.5, 'taksit_3' => 4, 'taksit_9' => 12],
            'bonus' => ['taksit_2' => 3],
        ]]),
        'www.paytr.com/odeme/api/bin-detail' => Http::response(['status' => 'success', 'brand' => 'world', 'bank' => 'Yapı Kredi', 'cardType' => 'credit']),
    ]);

    $all = (new PaytrGateway)->installments(Money::round(1000), 'TRY');
    expect($all['rows'])->toHaveCount(2)
        ->and($all['rows'][0]['options'][2])->toBeMoney(['count' => 3, 'total' => '1040.00', 'monthly' => '346.67'])
        // En fazla 6 taksit: 9 taksit gösterilmez.
        ->and(collect($all['rows'][0]['options'])->pluck('count')->all())->toBe([1, 2, 3]);

    // Vitrinsiz çekirdekte site kapalıdır: taksit tablosunu ödeme ekranındaki (girişli) bayi sorar.
    $this->actingAs($this->member)->getJson('/odeme/taksit/paytr?amount=1000&bin=450803')
        ->assertOk()
        ->assertJsonPath('card.bank', 'Yapı Kredi')
        ->assertJsonCount(1, 'rows');
});

it('refunds a PayTR payment through the panel', function () {
    $order = ($this->place)();
    $payment = $order->payments()->sole();
    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 12000))->assertOk();

    Http::fake(['www.paytr.com/odeme/iade' => Http::response(['status' => 'success'])]);

    $this->flushSession();
    $this->actingAs($this->admin)->post(route('panel.shop.payments.refund', $payment->id), ['amount' => '120'])->assertSessionHasNoErrors();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('120.0');
    Http::assertSent(fn ($request) => $request['return_amount'] === '120.00' && $request['merchant_oid'] === $payment->merchant_oid);
});

it('refunds in parts, debits the ledger for each part and refuses more than what is left', function () {
    $order = ($this->place)();
    $payment = $order->payments()->sole();
    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 12000))->assertOk();
    Http::fake(['www.paytr.com/odeme/iade' => Http::response(['status' => 'success'])]);
    $refunds = app(PaymentRefundService::class);

    $refunds->refund($payment->fresh(), 50, $this->admin);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('50.0');

    expect(fn () => $refunds->refund($payment->fresh(), '70.01', $this->admin))->toThrow(ShopException::class, '70,00');

    $refunds->refund($payment->fresh(), 70, $this->admin);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($order->fresh()->payment_status)->toBe(PaymentStatus::Refunded)
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->where('status', RefundStatus::Succeeded)->oldest()->get()->map(fn (PaymentRefund $r) => (string) $r->amount)->all())->toBe(['50.00', '70.00'])
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('120.0');
    Http::assertSentCount(2);
});

it('does not refund twice when two admins refund the same payment at once', function () {
    $order = ($this->place)();
    $payment = $order->payments()->sole();
    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 12000))->assertOk();
    Http::fake(['www.paytr.com/odeme/iade' => Http::response(['status' => 'success'])]);
    $first = $payment->fresh();
    $stale = $payment->fresh(); // ikinci yöneticinin açık ekranındaki hali

    app(PaymentRefundService::class)->refund($first, 120, $this->admin);

    expect(fn () => app(PaymentRefundService::class)->refund($stale, 120, $this->admin))->toThrow(ShopException::class);
    Http::assertSentCount(1);
    expect(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('120.0');
});

it('keeps the payment untouched when the provider refuses the refund', function () {
    $order = ($this->place)();
    $payment = $order->payments()->sole();
    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 12000))->assertOk();
    Http::fake(['www.paytr.com/odeme/iade' => Http::response(['status' => 'error', 'err_msg' => 'İade süresi geçti'])]);

    expect(fn () => app(PaymentRefundService::class)->refund($payment->fresh(), 120, $this->admin))->toThrow(ShopException::class);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        // Sağlayıcı reddetti: iade kaydı "başarısız" kalır, iade edilebilir tutardan düşmez.
        ->and(PaymentRefund::query()->where('payment_id', $payment->id)->sole()->status)->toBe(RefundStatus::Failed)
        ->and($payment->fresh()->refundedAmount())->toEqualDecimal('0')
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('0.0');
});

it('lets a dealer pay their balance by card from Cari Hesabım', function () {
    $this->actingAs($this->member)->get(route('app.shop.payments.index'))
        ->assertInertia(fn ($page) => $page->where('balancePay.gateways.0.value', 'paytr'));

    $response = $this->actingAs($this->member)->post(route('app.shop.balance-payments.store'), ['gateway' => 'paytr', 'amount' => '250']);
    $payment = Payment::query()->withoutGlobalScopes()->whereNull('order_id')->sole();
    $response->assertRedirectContains('/odeme/kart/'.$payment->merchant_oid);

    $this->post('/odeme/bildirim/paytr', ($this->callback)($payment->merchant_oid, 'success', 25000))->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and(app(LedgerService::class)->balance($this->member->account_id))->toEqualDecimal('-250.0');
});

it('keeps the PayTR secrets masked on the settings screen', function () {
    $this->actingAs($this->admin)->get(route('panel.paytr.settings.edit'))
        ->assertInertia(fn ($page) => $page->component('panel/tools/definitions/paytr/index')->where('settings.has_key', true)->missing('settings.merchant_key'));

    $this->actingAs($this->admin)->put(route('panel.paytr.settings.update'), [
        'enabled' => '1', 'merchant_id' => '999', 'merchant_key' => '', 'merchant_salt' => '', 'max_installment' => '3', 'fee_payer' => 'merchant',
    ])->assertSessionHasNoErrors();

    expect(ShopSettings::get('paytr_merchant_key'))->toBe('KEY123')
        ->and(ShopSettings::get('paytr_merchant_id'))->toBe('999')
        ->and(ShopSettings::get('paytr_fee_payer'))->toBe('merchant');
});
