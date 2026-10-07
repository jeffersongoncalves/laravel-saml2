<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

$controller = config('saml2.routes.controller');

Route::prefix((string) config('saml2.routes.prefix', 'saml2'))
    ->middleware((array) config('saml2.routes.middleware', ['web']))
    ->name('saml2.')
    ->group(function () use ($controller) {
        Route::get('{tenant}/login', [$controller, 'login'])->name('login');
        Route::get('{tenant}/logout', [$controller, 'logout'])->name('logout');
        Route::get('{tenant}/metadata', [$controller, 'metadata'])->name('metadata');
        Route::post('{tenant}/acs', [$controller, 'acs'])->name('acs');
        Route::match(['GET', 'POST'], '{tenant}/sls', [$controller, 'sls'])->name('sls');
    });
