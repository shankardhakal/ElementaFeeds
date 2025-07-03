@extends(backpack_view('layouts.top_left'))

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><strong>Queue Worker Settings</strong></div>
            <div class="card-body">
                <form action="{{ route('backpack.setting.update') }}" method="POST">
                    @csrf
                    <p class="text-muted">These settings control the performance and resource usage of the background job processor. Changes will be applied immediately.</p>
                    
                    <div class="form-group">
                        <label for="QUEUE_WORKER_COUNT">Worker Count</label>
                        <input type="number" name="QUEUE_WORKER_COUNT" class="form-control" value="{{ $settings['QUEUE_WORKER_COUNT'] }}">
                        <small>Number of worker processes to run. (Default: 2)</small>
                    </div>

                    <div class="form-group">
                        <label for="QUEUE_WORKER_MEMORY">Memory Limit (MB)</label>
                        <input type="number" name="QUEUE_WORKER_MEMORY" class="form-control" value="{{ $settings['QUEUE_WORKER_MEMORY'] }}">
                        <small>Max memory a worker can use before restarting. (Default: 128)</small>
                    </div>

                    <div class="form-group">
                        <label for="QUEUE_WORKER_MAX_JOBS">Max Jobs Per Worker</label>
                        <input type="number" name="QUEUE_WORKER_MAX_JOBS" class="form-control" value="{{ $settings['QUEUE_WORKER_MAX_JOBS'] }}">
                        <small>Max jobs a worker will process before restarting. (Default: 1000)</small>
                    </div>
                    
                    <hr>
                    <h4>Advanced Settings</h4>

                    <div class="form-group">
                        <label for="QUEUE_WORKER_TIMEOUT">Job Timeout (seconds)</label>
                        <input type="number" name="QUEUE_WORKER_TIMEOUT" class="form-control" value="{{ $settings['QUEUE_WORKER_TIMEOUT'] }}">
                        <small>How long a single job can run before it fails. (Default: 60)</small>
                    </div>

                    <div class="form-group">
                        <label for="QUEUE_WORKER_SLEEP">Sleep Time (seconds)</label>
                        <input type="number" name="QUEUE_WORKER_SLEEP" class="form-control" value="{{ $settings['QUEUE_WORKER_SLEEP'] }}">
                        <small>How long to wait before checking for new jobs if the queue is empty. (Default: 3)</small>
                    </div>

                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">Save and Apply Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection