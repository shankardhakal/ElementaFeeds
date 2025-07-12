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
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
use Alert; 

class ConnectionController extends Controller
{
    /**
     * Display the main dashboard for all connections.
     */
    public function index(Request $request)
    {
        // Validate sorting parameters
        $allowedSorts = ['id', 'name', 'created_at', 'last_run_at', 'is_active'];
        $sortField = in_array($request->get('sort'), $allowedSorts) ? $request->get('sort') : 'id';
        $sortDirection = $request->get('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        
        // Validate per-page parameter
        $perPage = in_array($request->get('per_page'), [10, 25, 50, 100]) ? $request->get('per_page') : 25;
        
        $query = FeedWebsite::with([
            'feed:id,name,is_active',
            'website:id,name', 
            'latestImportRun:id,feed_website_id,status,created_at'
        ]);
        
        // Search functionality with optimized queries
        if ($search = $request->get('search')) {
            // Only apply search if search term is not empty
            if (trim($search) !== '') {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhereHas('feed', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('website', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
                });
            }
        }
        
        // Status filter
        if ($request->has('status') && $request->get('status') !== '' && $request->get('status') !== null) {
            $query->where('is_active', $request->get('status'));
        }
        
        // Import status filter
        if ($importStatus = $request->get('import_status')) {
            // Only apply import status filter if not empty
            if (trim($importStatus) !== '') {
                $query->whereHas('latestImportRun', function($q) use ($importStatus) {
                    $q->where('status', $importStatus);
                });
            }
        }
        
        // Apply sorting
        if ($sortField === 'last_run_at') {
            // Special handling for last_run_at since it comes from relationship
            $query->leftJoin('import_runs as latest_runs', function($join) {
                $join->on('feed_website.id', '=', 'latest_runs.feed_website_id')
                     ->whereRaw('latest_runs.id = (SELECT MAX(id) FROM import_runs WHERE import_runs.feed_website_id = feed_website.id)');
            })->select('feed_website.*')->orderBy('latest_runs.created_at', $sortDirection);
        } else {
            $query->orderBy($sortField, $sortDirection);
        }
        
        $connections = $query->paginate($perPage);
        
        // Append query parameters to pagination links
        $connections->appends($request->query());

        $data['connections'] = $connections;
        $data['search'] = trim($search ?? '');
        $data['status'] = $request->get('status', '');
        $data['import_status'] = trim($request->get('import_status', ''));
        $data['sort'] = $sortField;
        $data['direction'] = $sortDirection;
        $data['per_page'] = $perPage;
        // Determine if any actual filters are applied
        $data['has_filters'] = !empty(trim($search ?? '')) || 
                              ($request->has('status') && $request->get('status') !== '') || 
                              !empty(trim($request->get('import_status', '')));
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

        // Add brand field for WooCommerce connections
        if ($website->platform === 'woocommerce') {
            $data['destination_fields']['brand'] = 'Brand';
        }

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
        // Debug: Log the raw request data to understand what's being submitted
        Log::info('Step3 Form Submission Debug', [
            'all_input' => $request->all(),
            'category_mappings' => $request->input('category_mappings', []),
            'field_mappings' => $request->input('field_mappings', [])
        ]);

        $validated = $request->validate([
            'field_mappings' => 'required|array',
            'category_source_field' => 'nullable|string', // Make nullable for flexibility
            'category_delimiter' => 'nullable|string',
            'category_mappings' => 'nullable|array', // Make nullable since some might be empty
            'category_mappings.*.source' => 'nullable|string', // Allow nullable for unmapped rows
            'category_mappings.*.dest' => 'nullable|string', // Allow nullable and string (will convert to int later)
            'category_mappings.*.tags' => 'nullable|string',
        ]);

        // Clean up category mappings - remove empty mappings and convert dest to int
        if (!empty($validated['category_mappings'])) {
            $validated['category_mappings'] = array_filter($validated['category_mappings'], function($mapping) {
                // Only keep mappings that have both source and dest
                return !empty($mapping['source']) && !empty($mapping['dest']);
            });
            
            // Convert dest to integer
            foreach ($validated['category_mappings'] as &$mapping) {
                if (isset($mapping['dest'])) {
                    $mapping['dest'] = (int) $mapping['dest'];
                }
            }
        }

        // Debug: Log the cleaned data
        Log::info('Step3 Cleaned Data', [
            'validated' => $validated,
            'category_mappings_count' => count($validated['category_mappings'] ?? [])
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
            'update_settings.stale_action' => 'required|string|in:delete',
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
        $connection = FeedWebsite::with('feed')->findOrFail($id);
        $website = $connection->website;

        // Check if the source feed is active
        if (!$connection->feed->is_active) {
            $message = "<strong>Cannot Start Import:</strong> The source feed '{$connection->feed->name}' is currently disabled. Please enable the feed first.";
            \Alert::error($message)->flash();
            return redirect()->route('connection.index');
        }

        // Check if the connection itself is active
        if (!$connection->is_active) {
            $message = "<strong>Cannot Start Import:</strong> The connection '{$connection->name}' is currently disabled. Please enable the connection first.";
            \Alert::error($message)->flash();
            return redirect()->route('connection.index');
        }

        // The job's WithoutOverlapping middleware uses a lock based on the website ID.
        // We manually check for this lock *before* dispatching the job.
        $lockKey = 'start-import-run:' . $website->id;
        $lock = Cache::lock($lockKey, 10);

        // Try to acquire the lock. If we can't, it means an import is already running for this website.
        if (!$lock->get()) {
            // Could not acquire the lock, so an import is already in progress for this website.
            $message = "<strong>Import in Progress:</strong> An import for website '{$website->name}' is already running. Please wait for it to complete before starting a new one.";
            \Alert::warning($message)->flash();
            return redirect()->route('connection.index');
        }

        // If we get here, we acquired the lock, meaning no other import for this website is running.
        // We can now safely dispatch the job. The job's middleware will use the same lock.
        try {
            StartImportRunJob::dispatch($connection);
            \Alert::success("Import for '{$connection->name}' queued successfully.")->flash();
        } finally {
            // It's crucial to release the lock so the job can acquire it when it runs.
            $lock->release();
        }
        
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
        // Store full connection data in the session for editing
        session(['connection_wizard_data' => [
            'is_edit' => true,
            'id' => $connection->id,
            'feed_id' => $connection->feed_id,
            'website_id' => $connection->website_id,
            'name' => $connection->name,
            'filters' => $connection->filtering_rules ?? [],
            'field_mappings' => $connection->field_mappings ?? [],
            'category_source_field' => $connection->category_source_field,
            'category_delimiter' => $connection->category_delimiter,
            'category_mappings' => $connection->category_mappings ?? [],
            'attribute_mappings' => $connection->attribute_mappings ?? [],
            'schedule' => $connection->schedule,
            'update_settings' => [
                'skip_new' => $connection->update_settings['skip_new'] ?? false,
                'update_existing' => $connection->update_settings['update_existing'] ?? false,
                'update_logic' => $connection->update_settings['update_logic'] ?? 'all',
                'partial_update_fields' => $connection->update_settings['partial_update_fields'] ?? [],
                'stale_action' => $connection->update_settings['stale_action'] ?? 'delete',
                'stale_days' => $connection->update_settings['stale_days'] ?? 30,
            ],
            'is_active' => $connection->is_active,
            'feed_name' => $connection->feed->name,
            'website_name' => $connection->website->name,
        ]]);
        
        \Alert::info("Editing connection '{$connection->name}'. Navigate through the steps to update configuration.")->flash();
        
        // Jump directly to the final step since all data is pre-loaded
        return redirect()->route('connection.create.step4');
    }
    
    /**
     * Export connections data to CSV
     */
    public function export(Request $request)
    {
        $query = FeedWebsite::with(['feed', 'website', 'latestImportRun']);
        
        // Apply same filters as index
        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('feed', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('website', function($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%");
                  });
            });
        }
        
        if ($request->has('status') && $request->get('status') !== '') {
            $query->where('is_active', $request->get('status'));
        }
        
        if ($importStatus = $request->get('import_status')) {
            $query->whereHas('latestImportRun', function($q) use ($importStatus) {
                $q->where('status', $importStatus);
            });
        }
        
        $connections = $query->orderBy('id', 'desc')->get();
        
        $filename = 'connections_export_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];
        
        $callback = function() use ($connections) {
            $file = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($file, [
                'ID',
                'Connection Name', 
                'Source Feed',
                'Feed Status',
                'Connection Status',
                'Effective Status',
                'Destination Website',
                'Last Run',
                'Last Run Status',
                'Created At',
                'Schedule'
            ]);
            
            foreach ($connections as $connection) {
                $effectiveStatus = $connection->isEffectivelyActive() ? 'Active' : $connection->getEffectiveStatusText();
                
                fputcsv($file, [
                    $connection->id,
                    $connection->name,
                    $connection->feed->name ?? 'N/A',
                    $connection->feed->is_active ? 'Active' : 'Disabled',
                    $connection->is_active ? 'Active' : 'Paused',
                    $effectiveStatus,
                    $connection->website->name ?? 'N/A',
                    $connection->latestImportRun?->created_at?->format('Y-m-d H:i:s') ?? 'Never',
                    $connection->latestImportRun?->status ?? 'No Runs',
                    $connection->created_at->format('Y-m-d H:i:s'),
                    $connection->schedule ?? 'daily'
                ]);
            }
            
            fclose($file);
        };
        
        return response()->stream($callback, 200, $headers);
    }
}