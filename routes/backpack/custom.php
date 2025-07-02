<?php

use Illuminate\Support\Facades\Route;

// --------------------------
// Custom Backpack Routes
// --------------------------
// This route file is loaded automatically by Backpack\Base.
// Routes you generate using Backpack\Generators will be placed here.

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
    'namespace'  => 'App\Http\Controllers\Admin',
], function () {
    // ----- Standard CRUD routes -----
    Route::crud('network', 'NetworkCrudController');
    Route::crud('feed', 'FeedCrudController');
    Route::crud('website', 'WebsiteCrudController');


    // ----- Custom Connection Wizard Routes -----

  Route::post('connection', 'ConnectionController@store')->name('connection.store');

    // Step 1 - Show dashboard for connections
    Route::get('connection', 'ConnectionController@index')->name('connection.index');

    // Step 1 - Show the form to start creating a connection
    Route::get('connection/create', 'ConnectionController@create')->name('connection.create');
    Route::post('connection/step-1', 'ConnectionController@storeStep1')->name('connection.store.step1');

    // Step 2 - Routes for Wizard Step 2
    Route::get('connection/create-step-2', 'ConnectionController@createStep2')->name('connection.create.step2');
    Route::post('connection/create-step-2', 'ConnectionController@storeStep2')->name('connection.store.step2');

// --- Routes for Wizard Step 3 ---
Route::get('connection/create-step-3', 'ConnectionController@createStep3')->name('connection.create.step3');
Route::post('connection/create-step-3', 'ConnectionController@storeStep3')->name('connection.store.step3');
  
// --- Routes for Wizard Step 4 ---
Route::get('connection/create-step-4', 'ConnectionController@createStep4')->name('connection.create.step4');
Route::post('connection/create-step-4', 'ConnectionController@storeStep4')->name('connection.store.step4');
  
Route::post('connection/{id}/run', 'ConnectionController@runNow')->name('connection.run');
Route::post('connection/parse-categories', 'ConnectionController@parseCategories')->name('connection.parse_categories');

  
});
