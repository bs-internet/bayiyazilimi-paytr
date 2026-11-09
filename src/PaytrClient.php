<?php

declare(strict_types=1);

namespace Bsinternet\Paytr;

use App\Exceptions\Shop\ShopException;
use App\Services\Shop\ShopSettings;
use App\Support\Money;
use Brick\Math\BigNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PayTR API istemcisi: iFrame token, bildirim (callback) imzası, taksit
 * oranları, BIN sorgusu ve iade. İmzalar PayTR dokümantasyonundaki
 * HMAC-SHA256 (base64) kuralıyla üretilir.
 */
final class PaytrClient
{
    public const BASE_URL = 'https://www.paytr.com';

    public function __construct(
        private readonly string $merchantId,
        private readonly string $merchantKey,
        private readonly string $merchantSalt,
        private readonly bool $testMode,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            (string) ShopSettings::get('paytr_merchant_id'),
            (string) ShopSettings::get('paytr_merchant_key'),
            (string) ShopSettings::get('paytr_merchant_salt'),
            ShopSettings::bool('paytr_test_mode'),
        );
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function testMode(): bool
    {
        return $this->testMode;
    }

    /**
     * iFrame token'ı (POST /odeme/api/get-token).
     *
     * @param  array{user_ip: string, merchant_oid: string, email: string, payment_amount: int, user_basket: string, no_installment: int, max_installment: int, currency: string, user_name: string, user_address: string, user_phone: string, merchant_ok_url: string, merchant_fail_url: string, timeout_limit: int, lang: string}  $params
     */
    public function iframeToken(array $params): string
    {
        $test = $this->testMode ? '1' : '0';
        $hash = $this->merchantId.$params['user_ip'].$params['merchant_oid'].$params['email'].$params['payment_amount']
            .$params['user_basket'].$params['no_installment'].$params['max_installment'].$params['currency'].$test;

        $response = Http::asForm()->timeout(20)->post(self::BASE_URL.'/odeme/api/get-token', [
            ...$params,
            'merchant_id' => $this->merchantId,
            'test_mode' => $test,
            'debug_on' => $this->testMode ? 1 : 0,
            'paytr_token' => $this->sign($hash.$this->merchantSalt),
        ]);

        $json = $response->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success' || empty($json['token'])) {
            throw new ShopException(__('PayTR ödeme sayfası açılamadı: :reason.', ['reason' => $json['reason'] ?? __('bağlantı hatası')]));
        }

        return (string) $json['token'];
    }

    /**
     * Bildirim imzası: hash = base64(hmac_sha256(merchant_oid + salt + status + total_amount, key)).
     *
     * @param  array<string, mixed>  $post
     */
    public function verifyCallback(array $post): bool
    {
        $expected = $this->sign(($post['merchant_oid'] ?? '').$this->merchantSalt.($post['status'] ?? '').($post['total_amount'] ?? ''));

        return hash_equals($expected, (string) ($post['hash'] ?? ''));
    }

    /**
     * Kart ailelerine göre taksit oranları (%), 6 saat önbellekli.
     *
     * @return array<string, array<int, float>> aile → [taksit sayısı → oran]
     */
    public function installmentRates(): array
    {
        return Cache::remember('paytr.installment_rates.'.$this->merchantId, now()->addHours(6), function () {
            $requestId = (string) now()->getTimestampMs();
            $json = Http::asForm()->timeout(15)->post(self::BASE_URL.'/odeme/taksit-oranlari', [
                'merchant_id' => $this->merchantId,
                'request_id' => $requestId,
                'paytr_token' => $this->sign($this->merchantId.$requestId.$this->merchantSalt),
            ])->json();

            if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
                throw new ShopException(__('PayTR taksit oranları alınamadı.'));
            }

            $rates = [];

            foreach ((array) ($json['oranlar'] ?? []) as $family => $values) {
                foreach ((array) $values as $key => $rate) {
                    if (preg_match('/(\d+)/', (string) $key, $m)) {
                        $rates[(string) $family][(int) $m[1]] = (float) $rate;
                    }
                }
            }

            return $rates;
        });
    }

    /**
     * BIN sorgusu (POST /odeme/api/bin-detail).
     *
     * @return array{bank: ?string, family: ?string, type: ?string}|null
     */
    public function binDetail(string $bin): ?array
    {
        $bin = substr($bin, 0, 6);
        $json = Http::asForm()->timeout(15)->post(self::BASE_URL.'/odeme/api/bin-detail', [
            'merchant_id' => $this->merchantId,
            'bin_number' => $bin,
            'paytr_token' => $this->sign($bin.$this->merchantId.$this->merchantSalt),
        ])->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
            return null;
        }

        return [
            'bank' => $json['bank'] ?? null,
            'family' => $json['brand'] ?? null,
            'type' => isset($json['cardType']) ? ($json['cardType'] === 'credit' ? __('Kredi kartı') : __('Banka kartı')) : null,
        ];
    }

    /**
     * İade (POST /odeme/iade). Başarısızsa ShopException.
     */
    public function refund(string $merchantOid, BigNumber|string $amount): void
    {
        $returnAmount = Money::str($amount);
        $json = Http::asForm()->timeout(20)->post(self::BASE_URL.'/odeme/iade', [
            'merchant_id' => $this->merchantId,
            'merchant_oid' => $merchantOid,
            'return_amount' => $returnAmount,
            'paytr_token' => $this->sign($this->merchantId.$merchantOid.$returnAmount.$this->merchantSalt),
        ])->json();

        if (! is_array($json) || ($json['status'] ?? null) !== 'success') {
            throw new ShopException(__('PayTR iadesi yapılamadı: :reason.', ['reason' => $json['err_msg'] ?? __('bağlantı hatası')]));
        }
    }

    private function sign(string $data): string
    {
        return base64_encode(hash_hmac('sha256', $data, $this->merchantKey, true));
    }
}
