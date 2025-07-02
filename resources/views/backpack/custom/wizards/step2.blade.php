@extends(backpack_view('layouts.top_left'))

@section('header')
<div class="container-fluid">
    <h2>
        <span class="text-capitalize">Create New Connection</span>
        <small>Step 2: Preview & Filter.</small>
    </h2>
</div>
@endsection

@section('content')
<div class="row">
    <div class="col-md-12">
        {{-- Data Preview Section --}}
        <div class="card">
            <div class="card-header">
                <i class="la la-table"></i> Feed Data Preview (First 100 Records)
            </div>
            <div class="card-body">
                @if(isset($wizardData['sample_failed']) && $wizardData['sample_failed'])
                    {{-- This is the new, more detailed error display --}}
                    <div class="alert alert-danger">
                        <strong>Could not download or parse the feed sample. Please check the feed URL and format.</strong>
                        <hr>
                        <p class="mb-0"><strong>Technical Details:</strong></p>
                        <code>{{ $wizardData['sample_error'] ?? 'An unknown error occurred.' }}</code>
                    </div>
                @elseif(isset($wizardData['sample_records']) && count($wizardData['sample_records']) > 0)
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    @foreach($wizardData['sample_headers'] as $header)
                                        <th>{{ $header }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($wizardData['sample_records'] as $record)
                                    <tr>
                                        @foreach($record as $value)
                                            <td>{{ Str::limit($value, 50) }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="alert alert-info">Generating feed preview... If this takes a long time, the feed may be slow or invalid.</div>
                @endif
            </div>
        </div>

        {{-- Filtering Rules Section --}}
        <div class="card">
            <div class="card-header"><i class="la la-filter"></i> Filtering Rules</div>
            <div class="card-body">
                <form method="POST" action="{{ route('connection.store.step2') }}">
                    @csrf
                    <p>Only import products from the feed if they match these rules. Leave blank to import all products.</p>
                    <div id="filter-container">
                        {{-- Filter rows will be added here by JavaScript --}}
                    </div>
                    <button type="button" id="add-filter" class="btn btn-sm btn-secondary"><i class="la la-plus"></i> Add Filter Rule</button>
                    <hr>
                    <div class="mt-2">
                        <a href="{{ route('connection.create') }}" class="btn btn-secondary"><i class="la la-arrow-left"></i> Back to Step 1</a>
                        <button type="submit" class="btn btn-primary">Next: Mapping Editor <i class="la la-arrow-right"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('filter-container');
    const addFilterBtn = document.getElementById('add-filter');
    const headers = @json($wizardData['sample_headers'] ?? []);
    let filterIndex = 0;

    addFilterBtn.addEventListener('click', function () {
        const filterRow = document.createElement('div');
        filterRow.classList.add('row', 'mb-2', 'align-items-center');
        filterRow.innerHTML = `
            <div class="col-md-4">
                <select name="filters[${filterIndex}][field]" class="form-control form-control-sm">
                    <option value="">-- Select Field --</option>
                    ${headers.map(h => `<option value="${h}">${h}</option>`).join('')}
                </select>
            </div>
            <div class="col-md-3">
                <select name="filters[${filterIndex}][operator]" class="form-control form-control-sm">
                    <option value="equals">equals</option>
                    <option value="not_equals">not equals</option>
                    <option value="contains">contains</option>
                    <option value="not_contains">does not contain</option>
                    <option value="greater_than">is greater than</option>
                    <option value="less_than">is less than</option>
                    <option value="is_empty">is empty</option>
                    <option value="is_not_empty">is not empty</option>
                </select>
            </div>
            <div class="col-md-4">
                <input type="text" name="filters[${filterIndex}][value]" class="form-control form-control-sm" placeholder="Value">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-danger remove-filter"><i class="la la-trash"></i></button>
            </div>
        `;
        container.appendChild(filterRow);
        filterIndex++;
    });

    container.addEventListener('click', function (e) {
        if (e.target.closest('.remove-filter')) {
            e.target.closest('.row').remove();
        }
    });
});
</script>
@endsection