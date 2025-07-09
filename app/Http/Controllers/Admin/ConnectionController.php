<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DownloadAndChunkJob;
use App\Jobs\DownloadFeedSample;
use App\Jobs\StartImportRunJob;
use App\Models\FeedWebsite;
use App\Models\Network;
use App\Models\Website;
use App\Services\Api\WooCommerceApiClient;
use App\Services\Api\WordPressApiClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Alert; 

class ConnectionController extends Controller
{
    /**
     * Display the main dashboard for all connections.
     */
    public function index()
    {
        $connections = FeedWebsite::with(['feed', 'website', 'latestImportRun'])
            ->orderBy('id', 'desc')
            ->paginate(25);

        $data['connections'] = $connections;
        $data['title'] = 'Manage Connections';
        $data['breadcrumbs'] = [
            trans('backpack::crud.admin') => backpack_url('dashboard'),
            'Connections' => false,
        ];
        return view('backpack.custom.connections_dashboard', $data);
    }

    /**
     * Show Step 1 of the connection creation wizard.
     */
    public function create()
    {
        $data['networks'] = Network::with(['feeds' => fn($q) => $q->where('is_active', true)])->get();
        $data['websites'] = Website::all();
        
        // Only clear session when starting a brand new connection
        // Don't clear if we came from an edit session
        if (!session()->has('connection_wizard_data.id')) {
            session()->forget('connection_wizard_data');
        }
        
        // Pass existing wizard data for form pre-filling
        $data['wizardData'] = session('connection_wizard_data', []);
        $data['title'] = 'Create New Connection: Step 1';
        $data['breadcrumbs'] = [
            'Admin' => backpack_url('dashboard'),
            'Connections' => backpack_url('connection'),
            'Create (Step 1)' => false,
        ];
        return view('backpack.custom.wizards.step1', $data);
    }

    /**
     * Store the data from Step 1 and redirect to Step 2.
     */
    public function storeStep1(Request $request)
    {
        $validated = $request->validate([
            'feed_id' => 'required|integer|exists:feeds,id',
            'website_id' => 'required|integer|exists:websites,id',
            'name' => 'required|string|max:255',
        ]);

        // Get Feed and Website names to store in session for summary display
        $feedName = \App\Models\Feed::find($validated['feed_id'])->name;
        $websiteName = \App\Models\Website::find($validated['website_id'])->name;

        $wizardData = $request->session()->get('connection_wizard_data', []);
        $wizardData = array_merge($wizardData, $validated, [
            'feed_name' => $feedName,
            'website_name' => $websiteName
        ]);

        $request->session()->put('connection_wizard_data', $wizardData);

        return redirect()->route('connection.create.step2');
    }

    /**
     * Show Step 2 of the wizard: Preview & Filter
     */
    public function createStep2()
    {
        $wizardData = session('connection_wizard_data', []);
        if (empty($wizardData['feed_id'])) {
            return redirect()->route('connection.create')->withErrors('Wizard session expired. Please start over.');
        }

        // Only download sample if we don't already have it in session
        if (!isset($wizardData['sample_records']) || empty($wizardData['sample_records'])) {
            DownloadFeedSample::dispatchSync($wizardData['feed_id']);
        }
        
        $data['wizardData'] = session()->get('connection_wizard_data');
        $data['title'] = 'Create New Connection: Step 2';
        $data['breadcrumbs'] = [
            'Admin' => backpack_url('dashboard'),
            'Connections' => backpack_url('connection'),
            'Create (Step 2)' => false,
        ];
        return view('backpack.custom.wizards.step2', $data);
    }

    /**
     * Store filtering rules from Step 2 and redirect to Step 3.
     */
    public function storeStep2(Request $request)
    {
        $filters = $request->input('filters', []);
        $wizardData = session('connection_wizard_data', []);
        $wizardData['filters'] = $filters;
        session(['connection_wizard_data' => $wizardData]);
        return redirect()->route('connection.create.step3');
    }

    /**
     * Show Step 3 of the wizard: The Mapping Editor.
     */
    public function createStep3()
    {
        $wizardData = session('connection_wizard_data', []);
        if (empty($wizardData['website_id'])) {
            return redirect()->route('connection.create')->withErrors('Wizard session expired. Please start over.');
        }

        $website = Website::findOrFail($wizardData['website_id']);
        $data['destination_categories'] = [];
        $data['destination_attributes'] = [];

        // Define the standard destination fields available for mapping.
        $data['destination_fields'] = [
            'name' => 'Product Name',
            'sku' => 'SKU',
            'description' => 'Description',
            'short_description' => 'Short Description',
            'regular_price' => 'Regular Price',
            'sale_price' => 'Sale Price',
            'stock_quantity' => 'Stock Quantity',
            'images' => 'Image Gallery (comma-separated URLs)',
            'product_url' => 'External/Affiliate URL',
            'button_text' => 'Button Text (for external products)',
            'unique_identifier' => 'Unique Identifier (Feed Name + Source ID)', // Added field
        ];

        try {
            $apiClient = ($website->platform === 'woocommerce')
                ? new WooCommerceApiClient($website)
                : new WordPressApiClient($website);

            // Fetch live data from the destination site
            $data['destination_categories'] = $apiClient->getCategories();
            $data['destination_attributes'] = $apiClient->getAttributes();
            
            // ✅ NEW: Check if the API call succeeded but returned empty results.
            if (empty($data['destination_categories'])) {
                 \Alert::warning("<strong>Connection Succeeded But...</strong> The API call was successful but returned no categories. Please ensure the destination website has categories created and the API user has permission to view them.")->flash();
            }

        } catch (\Exception $e) {
            \Alert::error("<strong>Live Connection Failed:</strong> Could not retrieve data from the destination website. <br><small><b>Error:</b> " . $e->getMessage() . "</small>")->flash();
        }
        
        $data['wizardData'] = $wizardData;
        $data['website'] = $website;
        $data['title'] = 'Create New Connection: Step 3';
        $data['breadcrumbs'] = [
            'Admin' => backpack_url('dashboard'),
            'Connections' => backpack_url('connection'),
            'Create (Step 3)' => false,
        ];
        
        return view('backpack.custom.wizards.step3', $data);
    }
  
  
  public function parseCategories(Request $request)
    {
        $validated = $request->validate([
            'source_field' => 'required|string',
            'delimiter'    => 'required|string',
        ]);

        $wizardData = session('connection_wizard_data', []);
        $records = $wizardData['sample_records'] ?? [];

        if (empty($records)) {
            return response()->json(['error' => 'No feed sample found in session.'], 400);
        }

        $uniqueCategories = collect($records)
            ->pluck($validated['source_field'])
            ->flatMap(function ($categoryString) use ($validated) {
                return array_map('trim', explode($validated['delimiter'], $categoryString));
            })
            ->filter() // Remove any empty values
            ->unique()
            ->sort()
            ->values();

        return response()->json(['categories' => $uniqueCategories]);
    }

    /**
     * Store mapping rules from Step 3 and redirect to Step 4.
     */
    public function storeStep3(Request $request)
    {
        $validated = $request->validate([
            'field_mappings' => 'required|array',
            'category_source_field' => 'required|string',
            'category_delimiter' => 'nullable|string', // Can be nullable
            'category_mappings' => 'required|array',
        ]);

        // Merge the validated data into the session
        $wizardData = session('connection_wizard_data', []);
        $mergedData = array_merge($wizardData, $validated);
        session(['connection_wizard_data' => $mergedData]);

        // Advance to the next step without saving to the database until final submission
        return redirect()->route('connection.create.step4');
    }

    /**
     * Show Step 4 of the wizard: Settings & Schedule.
     */
    public function createStep4()
    {
        $wizardData = session('connection_wizard_data', []);
        if (empty($wizardData['feed_id'])) {
            return redirect()->route('connection.create')->withErrors('Wizard session expired. Please start over.');
        }
        $data['wizardData'] = $wizardData;
        $data['title'] = 'Create New Connection: Step 4';
        $data['breadcrumbs'] = [
            'Admin' => backpack_url('dashboard'),
            'Connections' => backpack_url('connection'),
            'Create (Step 4)' => false,
        ];
        return view('backpack.custom.wizards.step4', $data);
    }

    /**
     * Validate and save the completed connection to the database.
     */
    public function storeStep4(Request $request)
    {
        $wizardData = session('connection_wizard_data', []);
        if (empty($wizardData['feed_id'])) {
            return redirect()->route('connection.create')->withErrors('Wizard session expired. Please start over.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'schedule' => 'required|string|in:daily,weekly,monthly',
            'is_active' => 'nullable|boolean', // Allow toggling active status
            'update_settings.skip_new' => 'nullable|boolean',
            'update_settings.update_existing' => 'nullable|boolean',
            'update_settings.update_logic' => 'required|string|in:all,partial',
            'update_settings.partial_update_fields' => 'nullable|string',
            'update_settings.stale_action' => 'required|string|in:set_stock_zero,delete',
            'update_settings.stale_days' => 'required|integer|min:1',
        ]);

        // Merge the final step's data into the session data
        $finalData = array_merge($wizardData, $validated);

        $updateData = [
            'feed_id' => $finalData['feed_id'],
            'website_id' => $finalData['website_id'],
            'name' => $finalData['name'],
            'is_active' => (bool) Arr::get($finalData, 'is_active', true),
            'filtering_rules' => $finalData['filters'] ?? [],
            'field_mappings' => $finalData['field_mappings'] ?? [],
            'category_source_field' => $finalData['category_source_field'] ?? null,
            'category_delimiter' => $finalData['category_delimiter'] ?? null,
            'category_mappings' => $finalData['category_mappings'] ?? [],
            'attribute_mappings' => $finalData['attribute_mappings'] ?? [],
            'schedule' => $finalData['schedule'],
            'update_settings' => [
                'skip_new' => (bool) Arr::get($finalData, 'update_settings.skip_new', false),
                'update_existing' => (bool) Arr::get($finalData, 'update_settings.update_existing', false),
                'update_logic' => Arr::get($finalData, 'update_settings.update_logic'),
                'partial_update_fields' => array_map('trim', explode(',', Arr::get($finalData, 'update_settings.partial_update_fields', ''))),
                'stale_action' => Arr::get($finalData, 'update_settings.stale_action'),
                'stale_days' => Arr::get($finalData, 'update_settings.stale_days'),
            ]
        ];

        // Use updateOrCreate to handle both creation and editing scenarios seamlessly
        if (isset($finalData['id'])) {
            // We're updating an existing connection
            $connection = FeedWebsite::findOrFail($finalData['id']);
            $connection->update($updateData);
            $message = "Connection '{$connection->name}' updated successfully!";
        } else {
            // We're creating a new connection
            $connection = FeedWebsite::create($updateData);
            $message = "Connection '{$connection->name}' created successfully!";
        }

        // Clear the session only after successful database operation
        session()->forget('connection_wizard_data');

        \Alert::success($message)->flash();
        return redirect()->route('connection.index');
    }
    
    /**
     * Manually dispatch a job to start an import run for a connection.
     */
    public function runNow(int $id)
    {
        $connection = FeedWebsite::findOrFail($id);
        try {
            Cache::lock("import:{$id}", 300)->block(0, function() use ($connection) {
                StartImportRunJob::dispatch($connection);
                // Backpack native success notification
                \Alert::success("Import for '{$connection->name}' queued successfully.")->flash();
            });
        } catch (LockTimeoutException $e) {
            // Backpack native warning notification
            \Alert::warning("Import already running for '{$connection->name}'. Try again later.")->flash();
        }
        // Always redirect to connections list to avoid landing on unsupported routes
        return redirect()->route('connection.index');
    }

    /**
     * Return current import status for a connection.
     */
    public function importStatus(int $id)
    {
        $connection = FeedWebsite::with('latestImportRun')->findOrFail($id);
        $status = optional($connection->latestImportRun)->status;
        return response()->json(['status' => $status]);
    }

    /**
     * Delete an existing connection.
     */
    public function destroy(int $id)
    {
        $connection = FeedWebsite::findOrFail($id);
        $connection->delete();
        \Alert::success("Connection '{$connection->name}' deleted successfully.")->flash();
        return redirect()->route('connection.index');
    }

    /**
     * Clone an existing connection.
     */
    public function clone(int $id)
    {
        $orig = FeedWebsite::findOrFail($id);
        try {
            $clone = $orig->replicate();
            $clone->name = $orig->name . ' (Copy)';
            $clone->push();
            \Alert::success("Connection '{$orig->name}' cloned successfully.")->flash();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                \Alert::error("Cannot clone connection: a connection for the same feed and website already exists.")->flash();
            } else {
                \Alert::error("Unexpected error when cloning connection: " . $e->getMessage())->flash();
            }
        }
        return redirect()->route('connection.index');
    }
    
    /**
     * Edit an existing connection (pre-fill wizard session and redirect to step 1).
     */
    public function edit(int $id)
    {
        $connection = FeedWebsite::with(['feed', 'website'])->findOrFail($id);
        
        // Store all connection data in the session
        session(['connection_wizard_data' => [
            'name' => $connection->name,
            'website_id' => $connection->website_id,
            'feed_id' => $connection->feed_id,
            'feed_name' => $connection->feed->name,
            'website_name' => $connection->website->name,
            'id' => $connection->id,
            'filters' => $connection->filtering_rules,
            'field_mappings' => $connection->field_mappings,
            'category_source_field' => $connection->category_source_field,
            'category_delimiter' => $connection->category_delimiter,
            'category_mappings' => $connection->category_mappings ?? [],
            'attribute_mappings' => $connection->attribute_mappings ?? [],
            'schedule' => $connection->schedule,
            'update_settings' => $connection->update_settings,
            'is_active' => $connection->is_active,
            // Store a flag to indicate we're in edit mode
            'is_edit' => true,
        ]]);
        
        \Alert::info("Editing connection '{$connection->name}'. Navigate through the steps to update configuration.")->flash();
        
        // Jump directly to the final step since all data is pre-loaded
        return redirect()->route('connection.create.step4');
    }
}