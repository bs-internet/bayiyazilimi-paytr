<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Güvenli Ödeme — :app', ['app' => \App\Support\Branding::name()]) }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f6f6f7; color: #111; }
        header { max-width: 760px; margin: 0 auto; padding: 20px 16px 8px; display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
        h1 { font-size: 18px; margin: 0; }
        .muted { color: #555; font-size: 14px; }
        main { max-width: 760px; margin: 0 auto; padding: 0 16px 24px; }
        iframe { width: 100%; border: 0; min-height: 600px; background: #fff; border-radius: 8px; }
    </style>
</head>
<body>
    <header>
        <h1>{{ __('Güvenli ödeme') }}</h1>
        <div class="muted">{{ $description }} · <strong>{{ $amount }}</strong></div>
    </header>
    <main>
        <script src="https://www.paytr.com/js/iframeResizer.min.js" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>
        <iframe src="https://www.paytr.com/odeme/guvenli/{{ $token }}" id="paytriframe" frameborder="0" scrolling="no" title="{{ __('PayTR güvenli ödeme') }}"></iframe>
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">iFrameResize({}, '#paytriframe');</script>
        <p class="muted">{{ __('Kart bilgileriniz PayTR güvenli ödeme sayfasında işlenir; mağazada saklanmaz.') }}</p>
    </main>
</body>
</html>
