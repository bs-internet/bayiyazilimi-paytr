<?php

declare(strict_types=1);

namespace Bsinternet\Paytr;

use App\Exceptions\Shop\ShopException;
use App\Models\Payment;
use App\Services\Shop\ShopSettings;
use App\Support\Money;
use App\Support\Payments\HostedCardGateway;
use App\Support\Payments\PaymentContext;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * PayTR iFrame ile kart ödemesi (3D Secure PayTR sayfasında). Bildirim URL'si
 * PayTR mağaza panelinde `/odeme/bildirim/paytr` olarak tanımlanmalıdır.
 */
final class PaytrGateway extends HostedCardGateway
{
    public const KEY = 'paytr';

    public function __construct(
        private readonly ?PaytrClient $client = null,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('Kredi / Banka Kartı');
    }

    public function description(PaymentContext $context): ?string
    {
        $max = ShopSettings::int('paytr_max_installment');

        return $max > 1
            ? __('Ödemenizi PayTR güvenli ödeme sayfasında 3D Secure ile yapın, :max taksite kadar. Kart bilgileriniz saklanmaz.', ['max' => $max])
            : __('Ödemenizi PayTR güvenli ödeme sayfasında 3D Secure ile yapın. Kart bilgileriniz saklanmaz.');
    }

    protected function configured(): bool
    {
        return ShopSettings::bool('paytr_enabled')
            && ShopSettings::get('paytr_merchant_id') !== ''
            && ShopSettings::get('paytr_merchant_key') !== ''
            && ShopSettings::get('paytr_merchant_salt') !== '';
    }

    public function renderPayment(Payment $payment, Request $request): Response
    {
        $buyer = $this->buyer($payment);
        $max = max(0, min(12, ShopSettings::int('paytr_max_installment')));
        $returnUrl = fn (string $result) => route('shop.payment.return', ['gateway' => self::KEY, 'oid' => $payment->merchant_oid, 'r' => $result]);

        $token = $this->client()->iframeToken([
            'user_ip' => (string) $request->ip(),
            'merchant_oid' => $payment->merchant_oid,
            'email' => $buyer['email'],
            // Kuruş cinsinden tam sayı (kesin).
            'payment_amount' => $payment->amount->toScale(2)->getUnscaledValue()->toInt(),
            'user_basket' => base64_encode((string) json_encode([[$buyer['description'], Money::str($payment->amount), 1]], JSON_UNESCAPED_UNICODE)),
            'no_installment' => $max <= 1 ? 1 : 0,
            'max_installment' => $max <= 1 ? 0 : $max,
            'currency' => self::currency($payment->currency_code),
            'user_name' => mb_substr($buyer['name'], 0, 60),
            'user_address' => mb_substr($buyer['address'], 0, 400),
            'user_phone' => mb_substr($buyer['phone'], 0, 20),
            'merchant_ok_url' => $returnUrl('ok'),
            'merchant_fail_url' => $returnUrl('fail'),
            'timeout_limit' => (int) ($this->pendingTimeout() ?? 60),
            'lang' => 'tr',
        ]);

        return response()->view('paytr::pay', [
            'token' => $token,
            'amount' => Money::number($payment->amount).' '.$payment->currency_code,
            'description' => $buyer['description'],
        ]);
    }

    /**
     * PayTR bildirimi: imza doğrulanır, ödeme sonuçlanır; yanıt düz "OK".
     */
    public function handleCallback(Request $request): Response
    {
        $client = $this->client();

        if (! $client->verifyCallback($request->all())) {
            return response('PAYTR notification failed: bad hash', 400);
        }

        $payment = $this->paymentByReference($request->input('merchant_oid'));

        if ($payment !== null) {
            $payload = $request->only(['status', 'total_amount', 'payment_amount', 'installment_count', 'payment_type', 'currency', 'test_mode', 'failed_reason_code', 'failed_reason_msg']);

            $paid = Money::div((string) (int) $request->input('total_amount'), 100);

            // Savunma: imzalı token tutarı taşır; yine de tahsil edilen tutar ödemeden az olamaz.
            if ($request->input('status') === 'success' && $paid->isLessThan(Money::decimal($payment->amount))) {
                $this->fail($payment, __('Tahsil edilen tutar ödeme tutarından az (tutar uyuşmazlığı).'), ['paytr' => $payload]);
            } elseif ($request->input('status') === 'success') {
                $this->complete($payment, $paid, (int) $request->input('installment_count', 1), ['paytr' => $payload]);
            } else {
                $this->fail($payment, (string) ($request->input('failed_reason_msg') ?: __('Ödeme reddedildi')), ['paytr' => $payload]);
            }
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Müşterinin dönüşü (PayTR veri göndermez; sonuç bildirimle gelir).
     */
    public function handleReturn(Request $request): Response
    {
        return $this->finish($this->paymentByReference($request->query('oid')));
    }

    public function installments(BigDecimal $amount, string $currency, ?string $bin = null): array
    {
        $client = $this->client();
        $max = max(1, min(12, ShopSettings::int('paytr_max_installment')));
        $customerPays = ShopSettings::get('paytr_fee_payer') !== 'merchant';
        $card = $bin !== null ? $client->binDetail($bin) : null;
        $rows = [];

        foreach ($client->installmentRates() as $family => $rates) {
            if ($card !== null && $card['family'] !== null && mb_strtolower((string) $card['family']) !== mb_strtolower($family)) {
                continue;
            }

            $options = [['count' => 1, 'total' => Money::round($amount), 'monthly' => Money::round($amount)]];

            foreach ($rates as $count => $rate) {
                if ($count < 2 || $count > $max) {
                    continue;
                }

                $total = $customerPays ? Money::withRate($amount, $rate) : Money::round($amount);
                $options[] = ['count' => $count, 'total' => $total, 'monthly' => Money::div($total, $count)];
            }

            $rows[] = ['family' => ucfirst($family), 'options' => $options];
        }

        return ['card' => $card, 'rows' => $rows];
    }

    public function refund(Payment $payment, BigDecimal $amount): void
    {
        if ($payment->provider !== self::KEY) {
            throw new ShopException(__('Bu ödeme PayTR ile alınmamış.'));
        }

        $this->client()->refund($payment->merchant_oid, $amount);
    }

    public static function currency(string $code): string
    {
        return $code === 'TRY' ? 'TL' : $code;
    }

    private function client(): PaytrClient
    {
        return $this->client ?? PaytrClient::fromSettings();
    }
}
