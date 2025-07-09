<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\FeedRequest;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use App\Http\Controllers\Admin\Traits\ProvidesLanguageOptions;
use App\Jobs\DeleteFeedProductsJob;
use Illuminate\Support\Facades\Route;

class FeedCrudController extends CrudController
{
    use ProvidesLanguageOptions;
    
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;

    public function setup()
    {
        CRUD::setModel(\App\Models\Feed::class);
        CRUD::setRoute(config('backpack.base.route_prefix') . '/feed');
        CRUD::setEntityNameStrings('feed', 'feeds');
    }

    protected function setupListOperation()
    {
        CRUD::column('name')->label('Feed Name');
        CRUD::column('network')->label('Network');
        CRUD::column('feed_url')->label('Feed URL')->type('url');
        CRUD::column('language')->label('Language');
        CRUD::column('is_active')->type('boolean')->label('Active');

        // Note: Delete feed products functionality is available in the main dashboard
        // No additional button needed here
    }

    protected function setupCreateOperation()
    {
        CRUD::setValidation(FeedRequest::class);

        CRUD::field('name')
            ->label('Descriptive Feed Name')
            ->hint('Give this feed a clear name, e.g., "HobbyHall.fi (All Products)".');

        CRUD::field('network_id')
            ->label('Network')
            ->type('select')
            ->entity('network')
            ->attribute('name')
            ->hint('Select the affiliate network this feed belongs to.');

        CRUD::field('feed_url')
            ->label('Feed URL')
            ->type('url')
            ->hint('Paste the full URL for the CSV, XML, or JSON feed file here.');

        CRUD::field('language')
            ->label('Feed Language & Country')
            ->type('select_from_array')
            ->options($this->getLanguageOptions())
            ->allows_null(false)
            ->hint('Select the primary language and country of the products in this feed.');

        CRUD::field('delimiter')
            ->label('Column Delimiter')
            ->type('select_from_array')
            ->options([
                'comma'     => 'Comma (,)',
                'tab'       => 'Tab',
                'pipe'      => 'Pipe (|)',
                'semicolon' => 'Semicolon (;)',
            ])
            ->default('comma')
            ->hint('The character that separates columns in your feed file.');
            
        CRUD::field('enclosure')
            ->label('Text Enclosure')
            ->type('select_from_array')
            ->options([
                '"' => 'Double-Quote (")',
                "'" => "Single-Quote (')",
            ])
            ->default('"')
            ->hint('The character that wraps around text values.');

        CRUD::field('is_active')
            ->label('Activate This Feed')
            ->type('checkbox')
            ->default(true)
            ->hint('This is the master on/off switch. If off, no products will be processed from this feed.');
    }

    protected function setupUpdateOperation()
    {
        $this->setupCreateOperation();
    }

    // Note: deleteFeedProducts method removed as the functionality
    // is available in the main dashboard interface
}