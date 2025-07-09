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
    // Note: feed.delete_products route removed - functionality available in main dashboard
    Route::crud('website', 'WebsiteCrudController');
    Route::post('website/test-connection', 'WebsiteCrudController@testConnection')->name('website.test_connection');

    // --- Connection Manager & Wizard Routes ---
    Route::get('connection', 'ConnectionController@index')->name('connection.index');
    Route::get('connection/export', 'ConnectionController@export')->name('connection.export');
    Route::get('connection/create', 'ConnectionController@create')->name('connection.create');
    Route::post('connection', 'ConnectionController@storeStep1')->name('connection.store.step1');
    Route::get('connection/create-step-2', 'ConnectionController@createStep2')->name('connection.create.step2');
    Route::post('connection/create-step-2', 'ConnectionController@storeStep2')->name('connection.store.step2');
    Route::get('connection/create-step-3', 'ConnectionController@createStep3')->name('connection.create.step3');
    Route::post('connection/create-step-3', 'ConnectionController@storeStep3')->name('connection.store.step3');
    Route::get('connection/create-step-4', 'ConnectionController@createStep4')->name('connection.create.step4');
    Route::post('connection/create-step-4', 'ConnectionController@storeStep4')->name('connection.store.step4');
    Route::post('connection/parse-categories', 'ConnectionController@parseCategories')->name('connection.parse_categories');
    
    // --- Settings Routes ---
    Route::get('setting', 'SettingController@index')->name('setting.index');
    Route::post('setting', 'SettingController@update')->name('setting.update');
    // Provide both POST and GET for run: GET redirects to connections list to avoid 405
    Route::get('connection/{id}/run', function() {
        return redirect()->route('connection.index')
            ->with('warning', 'To start an import, click the Run button on the Connections page.');
    });
    Route::post('connection/{id}/run', 'ConnectionController@runNow')
        ->middleware('throttle:1,1')
        ->name('connection.run');
    // Import run error logs download & status endpoint
    Route::get('import-run/{id}/errors', 'DashboardController@errors')->name('import_run.errors');
    Route::get('connection/{id}/status', 'ConnectionController@importStatus')->name('connection.status');
    // Catch-all GET for individual connection to avoid 405 on direct URL
    Route::get('connection/{id}', function($id) {
        // Redirect direct connection show URL to the edit form
        return redirect()->route('connection.edit', $id);
    });
    // Connection management: delete and clone
    Route::delete('connection/{id}', 'ConnectionController@destroy')->name('connection.destroy');
    Route::post('connection/{id}/clone', 'ConnectionController@clone')->name('connection.clone');
    // Edit Connection
    Route::get('connection/{id}/edit', 'ConnectionController@edit')->name('connection.edit');
});