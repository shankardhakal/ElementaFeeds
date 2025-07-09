<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\WebsiteRequest;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use App\Http\Controllers\Admin\Traits\ProvidesLanguageOptions;

class WebsiteCrudController extends CrudController
{
    use ProvidesLanguageOptions;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
    use \Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;

    /**
     * Configure the CRUD panel for the model.
     */
    public function setup()
    {
        CRUD::setModel(\App\Models\Website::class);
        CRUD::setRoute(config('backpack.base.route_prefix') . '/website');
        CRUD::setEntityNameStrings('website', 'websites');
    }

    /**
     * Configure the list view (columns).
     */
    protected function setupListOperation()
    {
        // Add ID column as the first column (leftmost) for debugging and administration
        CRUD::column('id')
            ->label('ID')
            ->type('number')
            ->orderable(true)
            ->priority(1); // Highest priority to show first
            
        CRUD::column('name')->label('Name');
        CRUD::column('url')->label('URL')->type('url');
        CRUD::column('platform')->label('Platform');

        CRUD::addColumn([
            'name'  => 'connection_status',
            'label' => 'Status',
            'type'  => 'view',
            'view'  => 'backpack.custom.columns.connection_status_with_info',
        ]);
    }

    /**
     * Configure the create/edit form (fields).
     * This version is simpler and more reliable, mapping fields directly to the database.
     */
    protected function setupCreateOperation()
    {
        CRUD::setValidation(WebsiteRequest::class);

        CRUD::field('name')->label('Website Name');
        CRUD::field('url')->type('url')->label('Website URL');
        CRUD::field('language')->type('select_from_array')->options($this->getLanguageOptions());
        
        CRUD::field('platform')->type('enum')->label('Platform')->wrapper([
            'id' => 'platform-field-wrapper', // ID for the JS to target
        ]);

        // Separator for visual clarity
        CRUD::addField([
            'name' => 'credentials_separator',
            'type' => 'custom_html',
            'value' => '<hr><h5>Credentials</h5><p>Fill out the section that matches the platform you selected above. The other will be ignored.</p>'
        ]);

        // WooCommerce credentials field - maps directly to the `woocommerce_credentials` database column
        $wooPlaceholder = json_encode(['type' => 'token', 'key' => 'ck_...', 'secret' => 'cs_...'], JSON_PRETTY_PRINT);
        CRUD::addField([
            'name' => 'woocommerce_credentials',
            'label' => 'WooCommerce Credentials',
            'type' => 'textarea',
            'attributes' => ['rows' => 5],
            'hint' => '<b>Example to copy:</b><pre class="border rounded p-2 bg-light"><code>' . $wooPlaceholder . '</code></pre>',
            'wrapper' => [
                'id' => 'woocommerce_wrapper' // ID for the JS to target
            ]
        ]);

        // WordPress credentials field - maps directly to the `wordpress_credentials` database column
        $wpPlaceholder = json_encode(['type' => 'password', 'username' => 'your_wp_username', 'password' => 'xxxx xxxx xxxx xxxx xxxx xxxx'], JSON_PRETTY_PRINT);
        CRUD::addField([
            'name' => 'wordpress_credentials',
            'label' => 'WordPress Credentials',
            'type' => 'textarea',
            'attributes' => ['rows' => 5],
            'hint' => '<b>Example to copy:</b><pre class="border rounded p-2 bg-light"><code>' . $wpPlaceholder . '</code></pre>',
            'wrapper' => [
                'id' => 'wordpress_wrapper' // ID for the JS to target
            ]
        ]);

        // Add the simple script to show/hide the credential fields
        CRUD::addField([
            'name' => 'script',
            'type' => 'custom_html',
            'value' => $this->getCredentialsScript()
        ]);
    }

    /**
     * Configure the update operation.
     */
    protected function setupUpdateOperation()
    {
        $this->setupCreateOperation();
    }
    
    /**
     * Configure the show operation.
     */
    protected function setupShowOperation()
    {
        $this->setupListOperation();
    }

    /**
     * A private helper function to hold the simple JavaScript for toggling visibility.
     */
    private function getCredentialsScript(): string
    {
        return <<<HTML
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const platformSelect = document.querySelector('#platform-field-wrapper select');
        const wooWrapper = document.getElementById('woocommerce_wrapper');
        const wpWrapper = document.getElementById('wordpress_wrapper');

        function toggleCredentialFields() {
            if (!platformSelect || !wooWrapper || !wpWrapper) {
                console.error("ElementaFeeds Debug: Could not find all required elements for dynamic form.");
                return;
            }
            const selectedPlatform = platformSelect.value;
            
            wooWrapper.style.display = (selectedPlatform === 'woocommerce') ? 'block' : 'none';
            wpWrapper.style.display = (selectedPlatform === 'wordpress') ? 'block' : 'none';
        }

        // Run on page load and when the platform dropdown changes
        toggleCredentialFields();
        platformSelect.addEventListener('change', toggleCredentialFields);
    });
</script>
HTML;
    }
}