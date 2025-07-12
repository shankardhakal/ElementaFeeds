@extends(backpack_view('layouts.top_left'))

@php
  // This is used by the layout to dynamically set the page's breadcrumbs
  $breadcrumbs = [
    trans('backpack::crud.admin') => backpack_url('dashboard'),
    'Manage Connections' => false,
  ];
@endphp

@section('header')
  <div class="container-fluid">
    <h2>
      <span class="text-capitalize">Manage Connections</span>
      <small>The central hub for all feed syndication.</small>
    </h2>
  </div>
@endsection

@section('content')
<div class="row">
  <div class="col-md-12">
    <div class="d-flex justify-content-between mb-3">
      <a href="{{ backpack_url('connection/create') }}" class="btn btn-primary"><i class="la la-plus"></i> Create New Connection</a>
      <div>
        {{-- Export Button --}}
        <a href="{{ route('connection.export', request()->query()) }}" class="btn btn-success">
          <i class="la la-download"></i> Export CSV
        </a>
      </div>
    </div>

    {{-- Search and Filter Form --}}
    <div class="card mb-3">
      <div class="card-body">
        <form method="GET" action="{{ route('connection.index') }}" class="row g-3">
          <div class="col-md-3">
            <div class="form-group">
              <label for="search">Search Connections</label>
              <input type="text" name="search" id="search" class="form-control" 
                     placeholder="Search by name, feed, or website..." 
                     value="{{ $search }}">
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="status">Status</label>
              <select name="status" id="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="1" {{ $status === '1' ? 'selected' : '' }}>Active</option>
                <option value="0" {{ $status === '0' ? 'selected' : '' }}>Paused</option>
              </select>
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="import_status">Import Status</label>
              <select name="import_status" id="import_status" class="form-control">
                <option value="">All Statuses</option>
                <option value="completed" {{ $import_status === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="failed" {{ $import_status === 'failed' ? 'selected' : '' }}>Failed</option>
                <option value="processing" {{ $import_status === 'processing' ? 'selected' : '' }}>Processing</option>
              </select>
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-group">
              <label for="per_page">Per Page</label>
              <select name="per_page" id="per_page" class="form-control">
                <option value="10" {{ $per_page == 10 ? 'selected' : '' }}>10</option>
                <option value="25" {{ $per_page == 25 ? 'selected' : '' }}>25</option>
                <option value="50" {{ $per_page == 50 ? 'selected' : '' }}>50</option>
                <option value="100" {{ $per_page == 100 ? 'selected' : '' }}>100</option>
              </select>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-group">
              <label>&nbsp;</label>
              <div>
                <button type="submit" class="btn btn-primary">
                  <i class="la la-search"></i> Filter
                </button>
                <a href="{{ route('connection.index') }}" class="btn btn-secondary">
                  <i class="la la-times"></i> Clear
                </a>
              </div>
            </div>
          </div>
          
          {{-- Hidden fields to preserve sorting --}}
          <input type="hidden" name="sort" value="{{ $sort }}">
          <input type="hidden" name="direction" value="{{ $direction }}">
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <i class="la la-link"></i> All Connections
        @if($has_filters)
          <small class="text-muted">(Filtered Results)</small>
        @endif
      </div>

      <div class="card-body p-0">
        <table class="table table-striped table-hover responsive-table mb-0">
          <thead>
            <tr>
              <th>
                <a href="{{ route('connection.index', array_merge(request()->query(), ['sort' => 'id', 'direction' => $sort === 'id' && $direction === 'asc' ? 'desc' : 'asc'])) }}" 
                   class="text-decoration-none text-dark">
                  ID 
                  @if($sort === 'id')
                    <i class="la la-sort-{{ $direction === 'asc' ? 'up' : 'down' }}"></i>
                  @else
                    <i class="la la-sort text-muted"></i>
                  @endif
                </a>
              </th>
              <th>
                <a href="{{ route('connection.index', array_merge(request()->query(), ['sort' => 'name', 'direction' => $sort === 'name' && $direction === 'asc' ? 'desc' : 'asc'])) }}" 
                   class="text-decoration-none text-dark">
                  Connection Name 
                  @if($sort === 'name')
                    <i class="la la-sort-{{ $direction === 'asc' ? 'up' : 'down' }}"></i>
                  @else
                    <i class="la la-sort text-muted"></i>
                  @endif
                </a>
              </th>
              <th>Source Feed</th>
              <th>Destination Website</th>
              <th>
                <a href="{{ route('connection.index', array_merge(request()->query(), ['sort' => 'is_active', 'direction' => $sort === 'is_active' && $direction === 'asc' ? 'desc' : 'asc'])) }}" 
                   class="text-decoration-none text-dark">
                  Status 
                  @if($sort === 'is_active')
                    <i class="la la-sort-{{ $direction === 'asc' ? 'up' : 'down' }}"></i>
                  @else
                    <i class="la la-sort text-muted"></i>
                  @endif
                </a>
              </th>
              <th>
                <a href="{{ route('connection.index', array_merge(request()->query(), ['sort' => 'last_run_at', 'direction' => $sort === 'last_run_at' && $direction === 'asc' ? 'desc' : 'asc'])) }}" 
                   class="text-decoration-none text-dark">
                  Last Run 
                  @if($sort === 'last_run_at')
                    <i class="la la-sort-{{ $direction === 'asc' ? 'up' : 'down' }}"></i>
                  @else
                    <i class="la la-sort text-muted"></i>
                  @endif
                </a>
              </th>
              <th>Last Run Status</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {{-- This loop iterates over each connection. The $connection variable only exists inside here. --}}
            @forelse ($connections as $connection)
              <tr>
                <td><strong>{{ $connection->id }}</strong></td>
                <td>{{ $connection->name }}</td>
                <td>{{ $connection->feed->name ?? 'N/A' }}</td>
                <td>{{ $connection->website->name ?? 'N/A' }}</td>
                <td>
                    @php
                        $connectionStatusMap = [
                            'active' => ['class' => 'bg-success', 'text' => 'Active'],
                            'connection_paused' => ['class' => 'bg-secondary', 'text' => 'Connection Paused'],
                            'feed_disabled' => ['class' => 'bg-danger', 'text' => 'Feed Disabled'],
                        ];
                        
                        // Determine the effective status
                        if (!$connection->feed->is_active) {
                            $effectiveStatus = 'feed_disabled';
                        } elseif (!$connection->is_active) {
                            $effectiveStatus = 'connection_paused';
                        } else {
                            $effectiveStatus = 'active';
                        }
                    @endphp
                    <x-status_badge 
                        :status="$effectiveStatus" 
                        :statusMap="$connectionStatusMap" 
                    />
                </td>
                <td>{{ $connection->latestImportRun?->created_at->format('Y-m-d H:i') ?? 'Never' }}</td>
                <td>
                    @if ($connection->latestImportRun)
                        @php
                            $runStatusMap = [
                                'completed' => ['class' => 'bg-success', 'text' => 'Completed'],
                                'failed' => ['class' => 'bg-danger', 'text' => 'Failed'],
                                'processing' => ['class' => 'bg-info', 'text' => 'Processing'],
                            ];
                        @endphp
                        <x-status_badge 
                            :status="$connection->latestImportRun->status"
                            :statusMap="$runStatusMap"
                        />
                    @else
                        <x-status_badge status="no_runs" :statusMap="['no_runs' => ['class' => 'bg-light', 'text' => 'No Runs']]" />
                    @endif
                </td>

                {{-- All action buttons must be inside the loop to access the $connection variable --}}
                <td class="text-right">
                    <a href="{{ route('connection.edit', $connection->id) }}" class="btn btn-sm btn-link" title="Edit"><i class="la la-edit"></i></a>
                    
                    @php 
                        $running = $connection->latestImportRun?->status === 'processing';
                        $canRun = $connection->is_active && $connection->feed->is_active && !$running;
                        $buttonTitle = $running ? 'Import Running' : (!$connection->feed->is_active ? 'Feed Disabled' : (!$connection->is_active ? 'Connection Paused' : 'Run Now'));
                    @endphp
                    <form action="{{ route('connection.run', $connection->id) }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-link" title="{{ $buttonTitle }}" @if(!$canRun) disabled @endif>
                            <i class="la la-play-circle"></i>
                        </button>
                    </form>
  
                    <form action="{{ route('connection.clone', $connection->id) }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-link" title="Clone">
                            <i class="la la-copy"></i>
                        </button>
                    </form>
                    <form action="{{ route('connection.destroy', $connection->id) }}" method="POST" style="display:inline;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-link text-danger" title="Delete">
                            <i class="la la-trash"></i>
                        </button>
                    </form>
                </td>
              </tr>

            {{-- This @empty block runs ONLY if there are no connections. --}}
            @empty
              <tr>
                <td colspan="8" class="text-center p-3">
                  @if($has_filters)
                    No connections match your search criteria. 
                    <a href="{{ route('connection.index') }}">Clear filters</a> to see all connections.
                  @else
                    No connections have been created yet. 
                    <a href="{{ backpack_url('connection/create') }}">Create the first one!</a>
                  @endif
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      {{-- Enhanced pagination footer --}}
      <div class="card-footer">
          @if($connections->hasPages())
            <div class="d-flex justify-content-between align-items-center flex-wrap">
              <div class="mb-2 mb-md-0">
                <small class="text-muted">
                  Showing {{ $connections->firstItem() }} to {{ $connections->lastItem() }} of {{ $connections->total() }} connections
                  @if($has_filters)
                    (filtered)
                  @endif
                </small>
              </div>
              <div class="d-flex align-items-center">
                <div class="me-3">
                  <small class="text-muted">Page {{ $connections->currentPage() }} of {{ $connections->lastPage() }}</small>
                </div>
                <div>
                  {{ $connections->links() }}
                </div>
              </div>
            </div>
          @else
            <div class="text-center">
              <small class="text-muted">
                {{ $connections->total() }} connection{{ $connections->total() !== 1 ? 's' : '' }} total
                @if($has_filters)
                  (filtered)
                @endif
              </small>
            </div>
          @endif
      </div>
      
    </div>
  </div>
</div>
@endsection

@section('after_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit form when select filters change
    const statusSelect = document.getElementById('status');
    const importStatusSelect = document.getElementById('import_status');
    const perPageSelect = document.getElementById('per_page');
    const form = statusSelect.closest('form');
    
    [statusSelect, importStatusSelect, perPageSelect].forEach(select => {
        select.addEventListener('change', function() {
            form.submit();
        });
    });
    
    // Debounced search functionality
    const searchInput = document.getElementById('search');
    let searchTimeout;
    
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            // Only auto-submit if search has content or was cleared
            if (this.value.length >= 3 || this.value.length === 0) {
                form.submit();
            }
        }, 500); // 500ms delay
    });
    
    // Add loading state to export button
    const exportBtn = document.querySelector('a[href*="export"]');
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="la la-spinner la-spin"></i> Exporting...';
            this.classList.add('disabled');
            
            // Re-enable after 3 seconds (export should be quick)
            setTimeout(() => {
                this.innerHTML = originalText;
                this.classList.remove('disabled');
            }, 3000);
        });
    }
});
</script>
@endsection