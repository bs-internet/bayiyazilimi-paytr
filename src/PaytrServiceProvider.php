<?php

declare(strict_types=1);

namespace Bsinternet\Paytr;

use App\Support\Payments\PaymentRegistry;
use App\Support\Registry\MenuRegistry;
use App\Support\Registry\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * PayTR kart ödeme modülü. Çekirdeğe yalnız hook ve registry ile bağlanır:
 * ödeme sürücüsü, ayar anahtarları, Tanımlamalar altında ayar ekranı ve
 * yetkiler.
 */
final class PaytrServiceProvider extends ServiceProvider
{
    public const SOURCE = 'paytr';

    /** Ayar anahtarları (çekirdeğin ayar deposunda, `shop` grubu). */
    public const DEFAULTS = [
        'paytr_enabled' => '0',
        'paytr_merchant_id' => '',
        'paytr_merchant_key' => '',
        'paytr_merchant_salt' => '',
        'paytr_test_mode' => '1',
        // 0/1: taksit yok; 2–12: en fazla taksit.
        'paytr_max_installment' => '0',
        // Taksit farkını kim öder: customer (müşteri) / merchant (firma). PayTR panelindeki ayarla aynı olmalı.
        'paytr_fee_payer' => 'customer',
    ];

    public function register(): void
    {
        $this->app['config']->push('inertia.pages.paths', __DIR__.'/../resources/js/pages');

        hooks()->filter('shop.settings.defaults', fn (array $value) => [...$value, ...self::DEFAULTS]);

        // İçerik güvenlik politikası: ödeme sayfasındaki PayTR çerçevesi. Betikler
        // görünümde nonce taşır ('strict-dynamic'), ayrıca adres gerekmez.
        hooks()->filter('security.csp', fn (array $value) => array_merge_recursive($value, [
            'frame-src' => ['https://www.paytr.com'],
        ]));

        hooks()->action('shop.payment.methods.register', function (PaymentRegistry $payments): void {
            $payments->add(new PaytrGateway);
        }, 20);

        hooks()->action('panel.routes.register', function (): void {
            Route::group([], __DIR__.'/../routes/panel.php');
        });

        hooks()->action('panel.menu.register', function (MenuRegistry $menu): void {
            $menu->addChild('panel', 'settings', 'panel.tools.definitions', [
                'key' => 'panel.paytr.settings.edit',
                'title' => __('PayTR'),
                'href' => route('panel.paytr.settings.edit'),
                'icon' => 'creditCard',
                'description' => __('PayTR kart ödemesi: mağaza bilgileri, taksit, test modu.'),
                'permission' => 'panel.paytr.settings.edit',
                'order' => 57,
            ]);
        }, 20);

        hooks()->action('panel.permissions.register', function (PermissionRegistry $permissions): void {
            $permissions->addMany('panel', [
                ['name' => 'panel.paytr.settings.edit', 'group' => __('Ödeme: PayTR'), 'label' => __('PayTR ayarlarını görüntüle')],
                ['name' => 'panel.paytr.settings.update', 'group' => __('Ödeme: PayTR'), 'label' => __('PayTR ayarlarını güncelle')],
            ], source: self::SOURCE);
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'paytr');
        // Modülün çeviri kataloğu (lang/tr.json; başka dil için lang/{locale}.json).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
