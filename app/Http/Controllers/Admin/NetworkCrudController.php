<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\NetworkRequest;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

class NetworkCrudController extends CrudController
{
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;

    public function setup()
    {
        CRUD::setModel(\App\Models\Network::class);
        CRUD::setRoute(config('backpack.base.route_prefix') . '/network');
        CRUD::setEntityNameStrings('network', 'networks');
    }

    protected function setupListOperation()
    {
        // This will now work correctly.
        CRUD::addClause('withCount', 'feeds');

        CRUD::column('name')->label('Network Name');
        
        CRUD::addColumn([
            'name'  => 'feeds_count',
            'label' => 'Feed Count',
            'type'  => 'text', // The result of withCount is a simple attribute: feeds_count
        ]);
    }

    protected function setupCreateOperation()
    {
        CRUD::setValidation(NetworkRequest::class);

        CRUD::field('name')
            ->label('Network Name')
            ->hint('Enter the official name of the affiliate network (e.g., "Awin", "Adtraction").');
    }

    protected function setupUpdateOperation()
    {
        $this->setupCreateOperation();
    }
    
    protected function setupShowOperation()
    {
        // We also apply the clause here to ensure the count shows on the details page.
        $this->setupListOperation();
    }
}