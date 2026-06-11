<?php

declare(strict_types=1);

use App\Http\Controllers\LandingController;
use App\Http\Controllers\LandingSitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'home'])->name('landing.home');
Route::get('/calculator', [LandingController::class, 'calculator'])->name('landing.calculator');
Route::get('/sitemap.xml', LandingSitemapController::class)->name('landing.sitemap');
Route::get('/check-tenant-slug', [LandingController::class, 'checkTenantSlug'])
    ->middleware('throttle:60,1')
    ->name('landing.check-tenant-slug');
Route::post('/signup-request', [LandingController::class, 'signupRequest'])
    ->middleware('throttle:10,1')
    ->name('landing.signup-request');

Route::get('/404', [LandingController::class, 'notFound'])->name('landing.not-found');
Route::fallback([LandingController::class, 'notFound']);
