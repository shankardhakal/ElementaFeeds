@extends(backpack_view('blank'))

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-12">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Feed Data Deletion</h3>
        </div>
        <div class="card-body">
          <div class="alert alert-warning" role="alert">
            <strong>Warning:</strong> This will permanently delete all products imported from the selected feed across all connected websites. This action cannot be undone.
          </div>
          
          <form method="POST" action="{{ route('admin.feed-deletion.cleanup') }}">
            @csrf
            <div class="form-group">
              <label for="feed_id">Select Feed:</label>
              <select name="feed_id" id="feed_id" class="form-control" required>
                <option value="">-- Select a feed --</option>
                @foreach ($feeds as $feed)
                  <option value="{{ $feed->id }}">{{ $feed->name }}</option>
                @endforeach
              </select>
            </div>
            <button type="submit" class="btn btn-danger mt-3" onclick="return confirm('Are you sure you want to delete all products from this feed? This action cannot be undone.')">
              <i class="fa fa-trash"></i> Delete Feed Data
            </button>
          </form>

          @if(session('results'))
            <div class="card mt-4">
              <div class="card-header">
                <h5 class="card-title">Cleanup Results</h5>
              </div>
              <div class="card-body">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Website</th>
                      <th>Status</th>
                      <th>Message</th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach(session('results') as $result)
                      <tr>
                        <td>{{ $result['website'] }}</td>
                        <td>
                          @if($result['status'] === 'dispatched')
                            <span class="badge badge-success">{{ $result['status'] }}</span>
                          @else
                            <span class="badge badge-danger">{{ $result['status'] }}</span>
                          @endif
                        </td>
                        <td>{{ $result['message'] }}</td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
