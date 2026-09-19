<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'SaaS Subscription & Tenant Management API is running.',
    ]);
})->name('service.info');

Route::fallback(fn () => response()->json([
    'success' => false,
    'message' => 'Requested resource not found.',
], 404));
