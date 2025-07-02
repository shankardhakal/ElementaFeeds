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
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

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
        session()->forget('connection_wizard_data');
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
            'name'       => 'required|string|max:255',
            'website_id' => 'required|integer|exists:websites,id',
            'feed_id'    => [
                'required',
                'integer',
                'exists:feeds,id',
                Rule::unique('feed_website')->where(function ($query) use ($request) {
                    return $query->where('website_id', $request->website_id);
                }),
            ],
        ], [
            'feed_id.unique' => 'A connection for this feed and website already exists.'
        ]);

        session(['connection_wizard_data' => $validated]);
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

        DownloadFeedSample::dispatchSync($wizardData['feed_id']);
        
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
        session(['connection_wizard_data.filters' => $filters]);
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
        $field_mappings = $request->input('field_mappings', []);
        $category_mappings = $request->input('category_mappings', []);
        $attribute_mappings = $request->input('attribute_mappings', []);

        session([
            'connection_wizard_data.field_mappings' => $field_mappings,
            'connection_wizard_data.category_mappings' => $category_mappings,
            'connection_wizard_data.attribute_mappings' => array_values($attribute_mappings ?? []),
        ]);
        return redirect()->route('connection.create.step4');
    }

    /**
     * Show Step 4 of the wizard: Settings & Schedule.
     */
    public function createStep4()
    {
        $wizardData = session('connection_wizard_data', []);
        if (empty($wizardData['name'])) {
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
        if (empty($wizardData['name'])) {
            return redirect()->route('connection.create')->withErrors('Wizard session expired. Please start over.');
        }

        $validated = $request->validate([
            'schedule' => 'required|string|in:daily,weekly,monthly',
            'update_settings.skip_new' => 'nullable|boolean',
            'update_settings.update_existing' => 'nullable|boolean',
            'update_settings.update_logic' => 'required|string|in:all,partial',
            'update_settings.partial_update_fields' => 'nullable|string',
            'update_settings.stale_action' => 'required|string|in:set_stock_zero,delete',
            'update_settings.stale_days' => 'required|integer|min:1',
        ]);
        
        $finalData = [
            'feed_id' => $wizardData['feed_id'],
            'website_id' => $wizardData['website_id'],
            'name' => $wizardData['name'],
            'is_active' => true,
            'filtering_rules' => $wizardData['filters'] ?? [],
            'field_mappings' => $wizardData['field_mappings'] ?? [],
            'category_mappings' => $wizardData['category_mappings'] ?? [],
            'attribute_mappings' => $wizardData['attribute_mappings'] ?? [],
            'schedule' => $validated['schedule'],
            'update_settings' => [
                'skip_new' => (bool) Arr::get($validated, 'update_settings.skip_new', false),
                'update_existing' => (bool) Arr::get($validated, 'update_settings.update_existing', false),
                'update_logic' => Arr::get($validated, 'update_settings.update_logic'),
                'partial_update_fields' => array_map('trim', explode(',', Arr::get($validated, 'update_settings.partial_update_fields', ''))),
                'stale_action' => Arr::get($validated, 'update_settings.stale_action'),
                'stale_days' => Arr::get($validated, 'update_settings.stale_days'),
            ]
        ];

        FeedWebsite::create($finalData);
        session()->forget('connection_wizard_data');

        \Alert::success('Connection created successfully!')->flash();
        return redirect()->route('connection.index');
    }
    
    /**
     * Manually dispatch a job to start an import run for a connection.
     */
    public function runNow(int $id)
    {
        $connection = FeedWebsite::findOrFail($id);
        StartImportRunJob::dispatch($connection);
        \Alert::success("Import process for '{$connection->name}' has been started.")->flash();
        return redirect()->back();
    }
}