@extends(backpack_view('blank'))

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="la la-info-circle"></i> Cleanup Run Details
                </h3>
                <div class="card-header-actions">
                    <a href="{{ route('backpack.feed-deletion.index') }}" class="btn btn-secondary">
                        <i class="la la-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
            <div class="card-body">
                
                {{-- Overview Cards --}}
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5 class="card-title">Products Found</h5>
                                <h3 class="text-right">{{ number_format($cleanupRun->products_found) }}</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title">Products Processed</h5>
                                <h3 class="text-right">{{ number_format($cleanupRun->products_processed) }}</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-danger">
                            <div class="card-body">
                                <h5 class="card-title">Products Failed</h5>
                                <h3 class="text-right">{{ number_format($cleanupRun->products_failed) }}</h3>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-white bg-info">
                            <div class="card-body">
                                <h5 class="card-title">Success Rate</h5>
                                <h3 class="text-right">
                                    @if($cleanupRun->products_found > 0)
                                        {{ round(($cleanupRun->products_processed / $cleanupRun->products_found) * 100, 1) }}%
                                    @else
                                        N/A
                                    @endif
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Progress Bar --}}
                @if($cleanupRun->products_found > 0)
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">Progress</h5>
                                @php
                                    $progressPercent = ($cleanupRun->products_processed / $cleanupRun->products_found) * 100;
                                    $failedPercent = ($cleanupRun->products_failed / $cleanupRun->products_found) * 100;
                                @endphp
                                <div class="progress mb-2" style="height: 25px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: {{ $progressPercent }}%" 
                                         aria-valuenow="{{ $progressPercent }}" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        {{ round($progressPercent, 1) }}% Success
                                    </div>
                                    <div class="progress-bar bg-danger" role="progressbar" 
                                         style="width: {{ $failedPercent }}%" 
                                         aria-valuenow="{{ $failedPercent }}" 
                                         aria-valuemin="0" 
                                         aria-valuemax="100">
                                        {{ round($failedPercent, 1) }}% Failed
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>{{ number_format($cleanupRun->products_processed) }} processed</span>
                                    <span>{{ number_format($cleanupRun->products_failed) }} failed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Details Table --}}
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Connection Details</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>Connection:</strong></td>
                                        <td>{{ $cleanupRun->connection_name }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Feed:</strong></td>
                                        <td>{{ $cleanupRun->feed_name }}</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Website:</strong></td>
                                        <td>
                                            {{ $cleanupRun->website_name }}
                                            @if($cleanupRun->website_url)
                                                <br>
                                                <small class="text-muted">{{ $cleanupRun->website_url }}</small>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Type:</strong></td>
                                        <td>
                                            @if($cleanupRun->type === 'manual_deletion')
                                                <span class="badge badge-warning">Manual Deletion</span>
                                            @elseif($cleanupRun->type === 'stale_cleanup')
                                                <span class="badge badge-info">Stale Cleanup</span>
                                            @else
                                                <span class="badge badge-secondary">{{ $cleanupRun->type }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Dry Run:</strong></td>
                                        <td>
                                            @if($cleanupRun->dry_run)
                                                <span class="badge badge-info">Yes</span>
                                            @else
                                                <span class="badge badge-secondary">No</span>
                                            @endif
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Timing & Status</h5>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless">
                                    <tr>
                                        <td><strong>Status:</strong></td>
                                        <td>
                                            @php
                                                $statusMap = [
                                                    'pending' => ['class' => 'bg-secondary', 'text' => 'Pending'],
                                                    'running' => ['class' => 'bg-info', 'text' => 'Running'],
                                                    'completed' => ['class' => 'bg-success', 'text' => 'Completed'],
                                                    'failed' => ['class' => 'bg-danger', 'text' => 'Failed'],
                                                    'cancelled' => ['class' => 'bg-warning', 'text' => 'Cancelled'],
                                                ];
                                                $status = $statusMap[$cleanupRun->status] ?? ['class' => 'bg-light', 'text' => 'Unknown'];
                                            @endphp
                                            <span class="badge {{ $status['class'] }}">{{ $status['text'] }}</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Started:</strong></td>
                                        <td>
                                            @if($cleanupRun->started_at)
                                                {{ \Carbon\Carbon::parse($cleanupRun->started_at)->format('Y-m-d H:i:s') }}
                                                <br>
                                                <small class="text-muted">{{ \Carbon\Carbon::parse($cleanupRun->started_at)->diffForHumans() }}</small>
                                            @else
                                                <span class="text-muted">Not started</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Completed:</strong></td>
                                        <td>
                                            @if($cleanupRun->completed_at)
                                                {{ \Carbon\Carbon::parse($cleanupRun->completed_at)->format('Y-m-d H:i:s') }}
                                                <br>
                                                <small class="text-muted">{{ \Carbon\Carbon::parse($cleanupRun->completed_at)->diffForHumans() }}</small>
                                            @else
                                                <span class="text-muted">Not completed</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Duration:</strong></td>
                                        <td>
                                            @if($cleanupRun->started_at && $cleanupRun->completed_at)
                                                @php
                                                    $started = \Carbon\Carbon::parse($cleanupRun->started_at);
                                                    $completed = \Carbon\Carbon::parse($cleanupRun->completed_at);
                                                    $duration = $started->diffForHumans($completed, true);
                                                @endphp
                                                {{ $duration }}
                                            @elseif($cleanupRun->started_at)
                                                <span class="text-info">{{ \Carbon\Carbon::parse($cleanupRun->started_at)->diffForHumans() }} (running)</span>
                                            @else
                                                <span class="text-muted">N/A</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><strong>Created:</strong></td>
                                        <td>
                                            {{ \Carbon\Carbon::parse($cleanupRun->created_at)->format('Y-m-d H:i:s') }}
                                            <br>
                                            <small class="text-muted">{{ \Carbon\Carbon::parse($cleanupRun->created_at)->diffForHumans() }}</small>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Error Summary --}}
                @if($cleanupRun->error_summary)
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card border-danger">
                            <div class="card-header bg-danger text-white">
                                <h5 class="card-title">Error Summary</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-danger">
                                    {{ $cleanupRun->error_summary }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Actions --}}
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title">Actions</h5>
                            </div>
                            <div class="card-body">
                                @if($cleanupRun->status === 'running')
                                    <form method="POST" action="{{ route('backpack.feed-deletion.cancel', $cleanupRun->id) }}" 
                                          style="display: inline;"
                                          onsubmit="return confirm('Are you sure you want to cancel this cleanup? It will stop after the current batch.')">
                                        @csrf
                                        <button type="submit" class="btn btn-warning">
                                            <i class="la la-stop"></i> Cancel Cleanup
                                        </button>
                                    </form>
                                @endif
                                
                                @if(in_array($cleanupRun->status, ['completed', 'failed', 'cancelled']))
                                    <a href="{{ route('backpack.feed-deletion.index') }}" class="btn btn-success">
                                        <i class="la la-plus"></i> Start New Cleanup
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('after_scripts')
<script>
    // Auto-refresh if cleanup is running
    @if($cleanupRun->status === 'running')
    setTimeout(function() {
        location.reload();
    }, 10000); // Refresh every 10 seconds for running cleanups
    @endif
</script>
@endsection
