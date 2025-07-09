@extends(backpack_view('layouts.top_left'))

@section('header')
<div class="container-fluid">
    <h2>
        @if (isset($wizardData['is_edit']) && $wizardData['is_edit'])
        <span class="text-capitalize">Edit Connection</span>
        @else
        <span class="text-capitalize">Create New Connection</span>
        @endif
        <small>Step 4: Settings, Schedule & Run.</small>
    </h2>
</div>
@endsection

@section('content')
<form method="POST" action="{{ route('connection.store.step4') }}">
    @csrf
    <div class="row">
        <div class="col-md-8">
            {{-- Connection Name --}}
            <div class="card">
                <div class="card-header"><i class="la la-tag"></i> Connection Name</div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="name">Connection Name</label>
                        <input type="text" class="form-control" id="name" name="name" value="{{ $wizardData['name'] ?? '' }}" required>
                        <small class="form-text text-muted">A descriptive name for this connection</small>
                    </div>
                </div>
            </div>

            {{-- Update Settings --}}
            <div class="card">
                <div class="card-header"><i class="la la-cogs"></i> Update Settings</div>
                <div class="card-body">
                    <h5>When an import runs...</h5>
                    <div class="form-check">
                        <input class="form-check-input" type="hidden" name="update_settings[skip_new]" value="0">
                        <input class="form-check-input" type="checkbox" id="skip_new" name="update_settings[skip_new]" value="1" 
                            {{ isset($wizardData['update_settings']['skip_new']) && $wizardData['update_settings']['skip_new'] ? 'checked' : '' }}>
                        <label class="form-check-label" for="skip_new">Do not create new products</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="hidden" name="update_settings[update_existing]" value="0">
                        <input class="form-check-input" type="checkbox" id="update_existing" name="update_settings[update_existing]" value="1" 
                            {{ isset($wizardData['update_settings']['update_existing']) ? ($wizardData['update_settings']['update_existing'] ? 'checked' : '') : 'checked' }}>
                        <label class="form-check-label" for="update_existing">Update existing products</label>
                    </div>

                    <div id="update-logic-options">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="update_settings[update_logic]" id="update_all" value="all" 
                                {{ !isset($wizardData['update_settings']['update_logic']) || $wizardData['update_settings']['update_logic'] == 'all' ? 'checked' : '' }}>
                            <label class="form-check-label" for="update_all">Update all fields on existing products</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="update_settings[update_logic]" id="update_partial" value="partial" 
                                {{ isset($wizardData['update_settings']['update_logic']) && $wizardData['update_settings']['update_logic'] == 'partial' ? 'checked' : '' }}>
                            <label class="form-check-label" for="update_partial">Only update these specific fields:</label>
                        </div>
                        @php
                            $partialFields = isset($wizardData['update_settings']['partial_update_fields']) ? 
                                implode(',', (array)$wizardData['update_settings']['partial_update_fields']) : '';
                            $isPartial = isset($wizardData['update_settings']['update_logic']) && $wizardData['update_settings']['update_logic'] == 'partial';
                        @endphp
                        <input type="text" name="update_settings[partial_update_fields]" id="partial_fields" class="form-control form-control-sm mt-2" 
                            placeholder="e.g., price, stock_quantity" 
                            value="{{ $partialFields }}" 
                            {{ $isPartial ? '' : 'disabled' }}>
                        <small class="form-text text-muted">A comma-separated list of field names.</small>
                    </div>

                    <hr>
                    <h5>For products that disappear from the feed...</h5>
                    <div class="row">
                        <div class="form-group col-md-6">
                            <label for="stale_action">Action</label>
                            <select name="update_settings[stale_action]" id="stale_action" class="form-control">
                                <option value="set_stock_zero" {{ isset($wizardData['update_settings']['stale_action']) && $wizardData['update_settings']['stale_action'] == 'set_stock_zero' ? 'selected' : '' }}>Set stock to 0</option>
                                <option value="delete" {{ isset($wizardData['update_settings']['stale_action']) && $wizardData['update_settings']['stale_action'] == 'delete' ? 'selected' : '' }}>Delete Product</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="stale_days">...after how many days?</label>
                            <input type="number" name="update_settings[stale_days]" id="stale_days" class="form-control" 
                                value="{{ $wizardData['update_settings']['stale_days'] ?? 30 }}" min="1">
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
                            <option value="daily" {{ isset($wizardData['schedule']) && $wizardData['schedule'] == 'daily' ? 'selected' : '' }}>Daily</option>
                            <option value="weekly" {{ isset($wizardData['schedule']) && $wizardData['schedule'] == 'weekly' ? 'selected' : '' }}>Weekly</option>
                            <option value="monthly" {{ isset($wizardData['schedule']) && $wizardData['schedule'] == 'monthly' ? 'selected' : '' }}>On the 1st of the month</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Activation --}}
            <div class="card">
                <div class="card-header"><i class="la la-power-off"></i> Activation</div>
                <div class="card-body">
                    <div class="form-group">
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                {{-- Default to active for new, otherwise use saved value --}}
                                {{ (isset($wizardData['is_edit']) && !$wizardData['is_edit']) || (isset($wizardData['is_active']) && $wizardData['is_active']) ? 'checked' : '' }}>
                            <label class="form-check-label" for="is_active">
                                <strong>Active?</strong>
                            </label>
                            <small class="form-text text-muted">Inactive connections will not run on a schedule.</small>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <div class="col-md-4">
            {{-- Summary --}}
            <div class="card">
                <div class="card-header"><i class="la la-list-alt"></i> Summary</div>
                <div class="card-body">
                    <p><strong>Feed:</strong><br> {{ $wizardData['feed_name'] ?? 'Not set' }}</p>
                    <p><strong>Website:</strong><br> {{ $wizardData['website_name'] ?? 'Not set' }}</p>
                    
                    <p class="mb-1"><strong>Filters:</strong></p>
                    @if (!empty($wizardData['filters']))
                        <ul>
                        @foreach ($wizardData['filters'] as $filter)
                            <li><small><code>{{ $filter['field'] }}</code> {{ $filter['operator'] }} <code>{{ $filter['value'] }}</code></small></li>
                        @endforeach
                        </ul>
                    @else
                        <p><small>No filters configured.</small></p>
                    @endif

                    <p class="mb-1"><strong>Field Mappings:</strong></p>
                    @if (!empty($wizardData['field_mappings']))
                        <ul>
                        @foreach ($wizardData['field_mappings'] as $source => $dest)
                            @if ($dest)
                                <li><small><code>{{ $source }}</code> &rarr; <code>{{ $dest }}</code></small></li>
                            @endif
                        @endforeach
                        </ul>
                    @else
                        <p><small>No fields mapped.</small></p>
                    @endif

                    <p class="mb-1"><strong>Category Mapping:</strong></p>
                    @if (!empty($wizardData['category_source_field']))
                        <p class="mb-1"><small>From source field <code>{{ $wizardData['category_source_field'] }}</code> with delimiter <code>{{ $wizardData['category_delimiter'] ?? 'none' }}</code>.</small></p>
                        @if (!empty($wizardData['category_mappings']))
                            <ul>
                            @foreach ($wizardData['category_mappings'] as $source => $dest)
                                @if ($dest && isset($dest['name']))
                                <li><small><code>{{ $source }}</code> &rarr; <code>{{ $dest['name'] }}</code></small></li>
                                @endif
                            @endforeach
                            </ul>
                        @else
                            <p><small>No specific categories mapped.</small></p>
                        @endif
                    @else
                        <p><small>Category mapping not configured.</small></p>
                    @endif
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