@extends(backpack_view('layouts.top_left'))


@section('content')
  {{-- First Row: Core Entity Stats --}}
  <div class="row">
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white bg-primary">
        <div class="card-body">
          <div class="text-value">{{ $connectionCount ?? 0 }}</div>
          <div>Total Connections</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white bg-success">
        <div class="card-body">
          <div class="text-value">{{ $activeConnectionCount ?? 0 }}</div>
          <div>Active Connections</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white bg-info">
        <div class="card-body">
          <div class="text-value">{{ $websiteCount ?? 0 }}</div>
          <div>Websites</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white bg-warning">
        <div class="card-body">
          <div class="text-value">{{ $feedCount ?? 0 }}</div>
          <div>Feeds</div>
        </div>
      </div>
    </div>
  </div>

  {{-- Second Row: Queue & Performance Stats --}}
  <div class="row">
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white" style="background-color: #6f42c1;">
        <div class="card-body">
          <div class="text-value">{{ $pendingJobs ?? 'N/A' }}</div>
          <div>Jobs in Queue</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white bg-danger">
        <div class="card-body">
          <div class="text-value">{{ $failedJobs ?? 0 }}</div>
          <div>Failed Jobs</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white" style="background-color: #17a2b8;">
        <div class="card-body">
          <div class="text-value">{{ $successRatio ?? 100 }}%</div>
          <div>Success Ratio (7d)</div>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-lg-3">
      <div class="card border-0 text-white bg-secondary">
        <div class="card-body">
          <div class="text-value">{{ $averageDuration ?? 'N/A' }}</div>
          <div>Avg. Duration</div>
        </div>
      </div>
    </div>
  </div>

  {{-- Recent Import Runs Table --}}
  <div class="row">
    <div class="col-md-12">
      <div class="card">
        <div class="card-header">Recent Import Runs</div>
        <div class="card-body">
          <table class="table table-striped table-hover">
            <thead>
              <tr>
                <th>ID</th>
                <th>Feed</th>
                <th>Website</th>
                <th>Status</th>
                <th>Records</th>
                <th>Errors</th>
                <th>Started</th>
                <th>Duration</th>
              </tr>
            </thead>
            <tbody>
              @forelse ($recentImportRuns as $run)
                @php
                  $duration = $run->created_at && $run->updated_at ? $run->created_at->diff($run->updated_at)->format('%H:%I:%S') : 'N/A';
                  $statusClass = match($run->status) {
                      'completed' => 'badge bg-success',
                      'completed_with_errors' => 'badge bg-warning',
                      'failed' => 'badge bg-danger',
                      'processing' => 'badge bg-info',
                      default => 'badge bg-secondary',
                  };
                @endphp
                <tr>
                  <td>{{ $run->id }}</td>
                  <td>{{ $run->feedWebsite->feed->name ?? 'N/A' }}</td>
                  <td>{{ $run->feedWebsite->website->name ?? 'N/A' }}</td>
                  <td><span class="{{ $statusClass }}">{{ Str::title(str_replace('_', ' ', $run->status)) }}</span></td>
                  <td>
                    C: {{ $run->created_records }} <br>
                    U: {{ $run->updated_records }}
                  </td>
                  <td>{{ is_array($run->error_records) ? count($run->error_records) : 0 }}</td>
                  <td>{{ $run->created_at->diffForHumans() }}</td>
                  <td>{{ $duration }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="8" class="text-center">No recent import runs found.</td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
@endsection