@extends(backpack_view('layouts.top_left'))

@section('header')
<div class="container-fluid">
    <h2>
        <span class="text-capitalize">Create New Connection</span>
        <small>Step 4: Settings, Schedule & Run.</small>
    </h2>
</div>
@endsection

@section('content')
<form method="POST" action="{{ route('connection.store.step4') }}">
    @csrf
    <div class="row">
        <div class="col-md-8">
            {{-- Update Settings --}}
            <div class="card">
                <div class="card-header"><i class="la la-cogs"></i> Update Settings</div>
                <div class="card-body">
                    <h5>When an import runs...</h5>
                    <div class="form-check">
                        <input class="form-check-input" type="hidden" name="update_settings[skip_new]" value="0">
                        <input class="form-check-input" type="checkbox" id="skip_new" name="update_settings[skip_new]" value="1">
                        <label class="form-check-label" for="skip_new">Do not create new products</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="hidden" name="update_settings[update_existing]" value="0">
                        <input class="form-check-input" type="checkbox" id="update_existing" name="update_settings[update_existing]" value="1" checked>
                        <label class="form-check-label" for="update_existing">Update existing products</label>
                    </div>

                    <div id="update-logic-options">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="update_settings[update_logic]" id="update_all" value="all" checked>
                            <label class="form-check-label" for="update_all">Update all fields on existing products</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="update_settings[update_logic]" id="update_partial" value="partial">
                            <label class="form-check-label" for="update_partial">Only update these specific fields:</label>
                        </div>
                        <input type="text" name="update_settings[partial_update_fields]" id="partial_fields" class="form-control form-control-sm mt-2" placeholder="e.g., price, stock_quantity" disabled>
                        <small class="form-text text-muted">A comma-separated list of field names.</small>
                    </div>

                    <hr>
                    <h5>For products that disappear from the feed...</h5>
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label for="stale_action">Action</label>
                            <select name="update_settings[stale_action]" id="stale_action" class="form-control">
                                <option value="set_stock_zero">Set stock to 0</option>
                                <option value="delete">Delete Product</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="stale_days">...after how many days?</label>
                            <input type="number" name="update_settings[stale_days]" id="stale_days" class="form-control" value="30" min="1">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Scheduling --}}
            <div class="card">
                <div class="card-header"><i class="la la-clock"></i> Scheduling</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="schedule">How often should this connection run?</label>
                        <select name="schedule" id="schedule" class="form-control">
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">On the 1st of the month</option>
                        </select>
                    </div>
                </div>
            </div>

        </div>
    </div>

    {{-- Final Save Button --}}
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <a href="{{ route('connection.create.step3') }}" class="btn btn-secondary"><i class="la la-arrow-left"></i> Back to Step 3</a>
                    <button type="submit" class="btn btn-lg btn-primary"><i class="la la-save"></i> Save Connection</button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
    // Simple JS to enable/disable the partial fields textbox
    document.addEventListener('DOMContentLoaded', function () {
        const partialRadio = document.getElementById('update_partial');
        const allRadio = document.getElementById('update_all');
        const partialFields = document.getElementById('partial_fields');

        function togglePartialFields() {
            partialFields.disabled = !partialRadio.checked;
        }

        partialRadio.addEventListener('change', togglePartialFields);
        allRadio.addEventListener('change', togglePartialFields);
    });
</script>
@endsection