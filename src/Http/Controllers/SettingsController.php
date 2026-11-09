<?php

declare(strict_types=1);

namespace Bsinternet\Paytr\Http\Controllers;

use App\Events\Shop\CatalogChangedEvent;
use App\Http\Controllers\Controller;
use App\Services\Shop\ShopSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tanımlamalar › PayTR: mağaza no, anahtar / gizli anahtar (maskeli; boş
 * bırakılırsa değişmez), test modu, taksit ve taksit farkını kim öder.
 */
class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('panel/tools/definitions/paytr/index', [
            'settings' => [
                'enabled' => ShopSettings::bool('paytr_enabled'),
                'merchant_id' => ShopSettings::get('paytr_merchant_id'),
                'has_key' => ShopSettings::get('paytr_merchant_key') !== '',
                'has_salt' => ShopSettings::get('paytr_merchant_salt') !== '',
                'test_mode' => ShopSettings::bool('paytr_test_mode'),
                'max_installment' => ShopSettings::int('paytr_max_installment'),
                'fee_payer' => ShopSettings::get('paytr_fee_payer'),
            ],
            'notifyUrl' => route('shop.payment.notify', 'paytr'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'string', 'max:32'],
            'merchant_key' => ['nullable', 'string', 'max:64'],
            'merchant_salt' => ['nullable', 'string', 'max:64'],
            'max_installment' => ['required', 'integer', 'min:0', 'max:12'],
            'fee_payer' => ['required', Rule::in(['customer', 'merchant'])],
        ], [], ['merchant_id' => __('mağaza no'), 'merchant_key' => __('mağaza parolası'), 'merchant_salt' => __('mağaza gizli anahtarı'), 'max_installment' => __('en fazla taksit'), 'fee_payer' => __('taksit farkı')]);

        $values = [
            'paytr_enabled' => $request->boolean('enabled'),
            'paytr_test_mode' => $request->boolean('test_mode'),
            'paytr_merchant_id' => trim((string) ($data['merchant_id'] ?? '')),
            'paytr_max_installment' => (string) (int) $data['max_installment'],
            'paytr_fee_payer' => $data['fee_payer'],
        ];

        // Gizli değerler maskeli gösterilir; boş gönderilirse eskisi korunur.
        foreach (['merchant_key', 'merchant_salt'] as $secret) {
            if (! empty($data[$secret])) {
                $values["paytr_{$secret}"] = trim((string) $data[$secret]);
            }
        }

        ShopSettings::save($values);

        CatalogChangedEvent::dispatch(null, 'settings', 'updated', __('PayTR ayarlarını güncelledi.'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => __('PayTR ayarları kaydedildi.')]);
    }
}
