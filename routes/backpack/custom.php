<?php

use Illuminate\Support\Facades\Route;

// --------------------------
// Custom Backpack Routes
// --------------------------

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace'  => 'App\Http\Controllers\Admin',
], function () {
    // This line overrides the default dashboard and points to your new controller.
    Route::get('dashboard', 'DashboardController@dashboard')->name('backpack.dashboard');

    // --- Standard CRUDs ---
    Route::crud('network', 'NetworkCrudController');
    Route::crud('feed', 'FeedCrudController');
    Route::crud('website', 'WebsiteCrudController');
    Route::post('website/test-connection', 'WebsiteCrudController@testConnection')->name('website.test_connection');

    // --- Connection Manager & Wizard Routes ---
    Route::get('connection', 'ConnectionController@index')->name('connection.index');
    Route::get('connection/create', 'ConnectionController@create')->name('connection.create');
    Route::post('connection', 'ConnectionController@storeStep1')->name('connection.store.step1');
    Route::get('connection/create-step-2', 'ConnectionController@createStep2')->name('connection.create.step2');
    Route::post('connection/create-step-2', 'ConnectionController@storeStep2')->name('connection.store.step2');
    Route::get('connection/create-step-3', 'ConnectionController@createStep3')->name('connection.create.step3');
    Route::post('connection/create-step-3', 'ConnectionController@storeStep3')->name('connection.store.step3');
    Route::get('connection/create-step-4', 'ConnectionController@createStep4')->name('connection.create.step4');
    Route::post('connection/create-step-4', 'ConnectionController@storeStep4')->name('connection.store.step4');
    Route::post('connection/parse-categories', 'ConnectionController@parseCategories')->name('connection.parse_categories');
    Route::post('connection/{id}/run', 'ConnectionController@runNow')->name('connection.run');
});