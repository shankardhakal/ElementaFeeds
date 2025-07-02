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
    <a href="{{ backpack_url('connection/create') }}" class="btn btn-primary mb-3"><i class="la la-plus"></i> Create New Connection</a>

    <div class="card">
      <div class="card-header"><i class="la la-link"></i> All Connections</div>

      <div class="card-body p-0">
        <table class="table table-striped table-hover responsive-table mb-0">
          <thead>
            <tr>
              <th>Connection Name</th>
              <th>Source Feed</th>
              <th>Destination Website</th>
              <th>Status</th>
              <th>Last Run</th>
              <th>Last Run Status</th>
              <th class="text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {{-- This loop iterates over each connection. The $connection variable only exists inside here. --}}
            @forelse ($connections as $connection)
              <tr>
                <td>{{ $connection->name }}</td>
                <td>{{ $connection->feed->name ?? 'N/A' }}</td>
                <td>{{ $connection->website->name ?? 'N/A' }}</td>
                <td>
                  @if ($connection->is_active)
                    <span class="badge badge-success">Active</span>
                  @else
                    <span class="badge badge-secondary">Paused</span>
                  @endif
                </td>
                <td>{{ $connection->last_run_at ? $connection->last_run_at->format('Y-m-d H:i') : 'Never' }}</td>
                <td>
                  @if ($connection->latestImportRun)
                    @php
                      $status = $connection->latestImportRun->status;
                      $badgeClass = 'secondary';
                      if ($status === 'completed') $badgeClass = 'success';
                      if ($status === 'failed') $badgeClass = 'danger';
                      if ($status === 'processing') $badgeClass = 'info';
                    @endphp
                    <span class="badge badge-{{ $badgeClass }}">{{ ucfirst($status) }}</span>
                  @else
                    <span class="badge badge-light">No Runs</span>
                  @endif
                </td>

                {{-- All action buttons must be inside the loop to access the $connection variable --}}
                <td class="text-right">
                    <a href="#" class="btn btn-sm btn-link" title="Manage/Edit"><i class="la la-edit"></i></a>
                    
                    <form action="{{ route('connection.run', $connection->id) }}" method="POST" style="display:inline;">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-link" title="Run Now">
                            <i class="la la-play-circle"></i>
                        </button>
                    </form>

                    <a href="#" class="btn btn-sm btn-link" title="Clone"><i class="la la-copy"></i></a>
                    <a href="#" class="btn btn-sm btn-link" title="Delete"><i class="la la-trash"></i></a>
                </td>
              </tr>

            {{-- This @empty block runs ONLY if there are no connections. --}}
            @empty
              <tr>
                <td colspan="7" class="text-center p-3">
                  No connections have been created yet. 
                  <a href="{{ backpack_url('connection/create') }}">Create the first one!</a>
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      {{-- This section renders the pagination links if there are multiple pages --}}
      <div class="card-footer">
          {{ $connections->links() }}
      </div>
      
    </div>
  </div>
</div>
@endsection