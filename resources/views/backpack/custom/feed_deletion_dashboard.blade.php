@extends(backpack_view('blank'))

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="la la-trash"></i> {{ $title }}
                </h3>
            </div>
            <div class="card-body">
                
                {{-- Statistics Cards --}}
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col">
                                        <h5 class="card-title">Total Runs</h5>
                                        <h3 class="text-right">{{ $stats['total_runs'] }}</h3>
                                    </div>
                                    <div class="col-auto">
                                        <i class="la la-list-alt fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col">
                                        <h5 class="card-title">Completed</h5>
                                        <h3 class="text-right">{{ $stats['completed_runs'] }}</h3>
                                    </div>
                                    <div class="col-auto">
                                        <i class="la la-check-circle fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col">
                                        <h5 class="card-title">Running</h5>
                                        <h3 class="text-right">{{ $stats['running_runs'] }}</h3>
                                    </div>
                                    <div class="col-auto">
                                        <i class="la la-spinner fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col">
                                        <h5 class="card-title">Products Processed</h5>
                                        <h3 class="text-right">{{ number_format($stats['total_products_processed']) }}</h3>
                                    </div>
                                    <div class="col-auto">
                                        <i class="la la-cube fa-2x"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Filters --}}
                <div class="row mb-3">
                    <div class="col-md-12">
                        <form method="GET" class="form-inline">
                            <div class="form-group mr-3">
                                <label for="search" class="sr-only">Search</label>
                                <input type="text" name="search" id="search" class="form-control" 
                                       placeholder="Search connections..." value="{{ request('search') }}">
                            </div>
                            <div class="form-group mr-3">
                                <select name="type" class="form-control">
                                    <option value="all" {{ request('type') === 'all' ? 'selected' : '' }}>All Types</option>
                                    <option value="manual_deletion" {{ request('type') === 'manual_deletion' ? 'selected' : '' }}>Manual Deletion</option>
                                    <option value="stale_cleanup" {{ request('type') === 'stale_cleanup' ? 'selected' : '' }}>Stale Cleanup</option>
                                </select>
                            </div>
                            <div class="form-group mr-3">
                                <select name="status" class="form-control">
                                    <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>All Status</option>
                                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                                    <option value="running" {{ request('status') === 'running' ? 'selected' : '' }}>Running</option>
                                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                                    <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
                                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="la la-search"></i> Filter
                            </button>
                            <a href="{{ route('backpack.feed-deletion.index') }}" class="btn btn-secondary ml-2">
                                <i class="la la-refresh"></i> Reset
                            </a>
                        </form>
                    </div>
                </div>

                {{-- Connection Table --}}
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Connection</th>
                                <th>Feed → Website</th>
                                <th>Products</th>
                                <th>Last Cleanup</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($connections as $connection)
                            <tr>
                                <td>
                                    <strong>{{ $connection->name }}</strong>
                                    <br>
                                    <small class="text-muted">ID: {{ $connection->id }}</small>
                                </td>
                                <td>
                                    <strong>{{ $connection->feed->name ?? 'N/A' }}</strong>
                                    <br>
                                    <small class="text-muted">{{ $connection->website->name ?? 'N/A' }}</small>
                                </td>
                                <td>
                                    @if($connection->cleanupRuns->isNotEmpty())
                                        @php $lastRun = $connection->cleanupRuns->first(); @endphp
                                        <span class="badge badge-info">{{ $lastRun->products_found ?? 0 }}</span>
                                        @if($lastRun->products_processed > 0)
                                            <br><small class="text-muted">{{ $lastRun->products_processed }} processed</small>
                                        @endif
                                    @else
                                        <span class="text-muted">Unknown</span>
                                    @endif
                                </td>
                                <td>
                                    @if($connection->cleanupRuns->isNotEmpty())
                                        @php $lastRun = $connection->cleanupRuns->first(); @endphp
                                        {{ $lastRun->created_at->format('Y-m-d H:i') }}
                                        <br>
                                        <small class="text-muted">{{ $lastRun->created_at->diffForHumans() }}</small>
                                    @else
                                        <span class="text-muted">Never</span>
                                    @endif
                                </td>
                                <td>
                                    @if($connection->cleanupRuns->isNotEmpty())
                                        @php $lastRun = $connection->cleanupRuns->first(); @endphp
                                        @php
                                            $statusMap = [
                                                'pending' => ['class' => 'bg-secondary', 'text' => 'Pending'],
                                                'running' => ['class' => 'bg-info', 'text' => 'Running'],
                                                'completed' => ['class' => 'bg-success', 'text' => 'Completed'],
                                                'failed' => ['class' => 'bg-danger', 'text' => 'Failed'],
                                                'cancelled' => ['class' => 'bg-warning', 'text' => 'Cancelled'],
                                            ];
                                            $status = $statusMap[$lastRun->status] ?? ['class' => 'bg-light', 'text' => 'Unknown'];
                                        @endphp
                                        <span class="badge {{ $status['class'] }}">{{ $status['text'] }}</span>
                                    @else
                                        <span class="badge bg-light">No Runs</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="btn-group">
                                        {{-- Dry Run Button --}}
                                        <form method="POST" action="{{ route('backpack.feed-deletion.cleanup', $connection->id) }}" style="display: inline;">
                                            @csrf
                                            <input type="hidden" name="dry_run" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" 
                                                    title="Dry Run - Preview what would be deleted">
                                                <i class="la la-vial"></i> Dry Run
                                            </button>
                                        </form>
                                        
                                        {{-- Cleanup Button --}}
                                        <form method="POST" action="{{ route('backpack.feed-deletion.cleanup', $connection->id) }}" 
                                              style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete all products for this connection? This action cannot be undone.')">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-danger" 
                                                    title="Delete all products for this connection">
                                                <i class="la la-trash"></i> Delete All
                                            </button>
                                        </form>
                                        
                                        {{-- Cancel Button (only for running cleanups) --}}
                                        @if($connection->cleanupRuns->isNotEmpty() && $connection->cleanupRuns->first()->status === 'running')
                                            <form method="POST" action="{{ route('backpack.feed-deletion.cancel', $connection->cleanupRuns->first()->id) }}" 
                                                  style="display: inline;">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-warning" 
                                                        title="Cancel running cleanup">
                                                    <i class="la la-stop"></i> Cancel
                                                </button>
                                            </form>
                                        @endif
                                        
                                        {{-- View Details Button --}}
                                        @if($connection->cleanupRuns->isNotEmpty())
                                            <a href="{{ route('backpack.feed-deletion.show', $connection->cleanupRuns->first()->id) }}" 
                                               class="btn btn-sm btn-outline-info" title="View cleanup details">
                                                <i class="la la-eye"></i> Details
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <p class="text-muted">No connections found.</p>
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                {{ $connections->appends(request()->query())->links() }}
            </div>
        </div>
    </div>
</div>
@endsection

@section('after_scripts')
<script>
    // Auto-refresh running cleanups every 30 seconds
    setInterval(function() {
        const runningBadges = document.querySelectorAll('.badge.bg-info');
        if (runningBadges.length > 0) {
            // Only refresh if there are running cleanups
            location.reload();
        }
    }, 30000);
</script>
@endsection
