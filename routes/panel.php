<?php

declare(strict_types=1);

use Bsinternet\Paytr\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

/*
| Panel — PayTR ayarları (`panel.routes.register` içinde; /panel öneki,
| `panel.` ad öneki ve panelin middleware yığını). Rota adı = yetki adı.
*/

Route::controller(SettingsController::class)->prefix('paytr')->name('paytr.settings.')->group(function () {
    Route::get('/', 'edit')->name('edit');
    Route::put('/', 'update')->name('update');
});
