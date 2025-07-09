@extends(backpack_view('layouts.top_left'))

@section('header')
<div class="container-fluid">
    <h2>
        <span class="text-capitalize">Create New Connection</span>
        <small>Step 3: The Mapping Editor.</small>
    </h2>
</div>
@endsection

@section('content')
<form method="POST" action="{{ route('connection.store.step3') }}">
    @csrf
    <div class="row">
        {{-- Left Column: Destination Fields --}}
        <div class="col-md-8">
            {{-- Standard Fields Card --}}
            <div class="card">
                <div class="card-header"><i class="la la-bullseye"></i> Main Product Fields ({{ ucfirst($website->platform) }})</div>
                <div class="card-body">
                    @php
                        $sourceHeaders = $wizardData['sample_headers'] ?? [];
                    @endphp

                    @foreach($destination_fields as $field_key => $field_label)
                        <div class="form-group mb-2">
                            <label for="{{ $field_key }}" class="mb-0"><small>{{ $field_label }}</small></label>
                            <select name="field_mappings[{{ $field_key }}]" class="form-control form-control-sm">
                                <option value="">-- Do not map --</option>
                                @foreach($sourceHeaders as $header)
                                    <option value="{{ e($header) }}" {{ isset($wizardData['field_mappings'][$field_key]) && $wizardData['field_mappings'][$field_key] == $header ? 'selected' : '' }}>{{ e($header) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Advanced Category Mapping Section --}}
            <div class="card">
                <div class="card-header"><i class="la la-sitemap"></i> Advanced Category Mapping</div>
                <div class="card-body">
                    <p class="text-muted"><small>Tell the system how to find and separate your source categories. This will generate a list for you to map.</small></p>
                    <div class="row">
                        <div class="form-group col-md-5">
                            <label for="category_source_field">1. Category Source Field</label>
                            <select id="category_source_field" name="category_source_field" class="form-control">
                                <option value="">-- Select Feed Column --</option>
                                @foreach($sourceHeaders as $header)
                                    <option value="{{ $header }}" {{ isset($wizardData['category_source_field']) && $wizardData['category_source_field'] == $header ? 'selected' : '' }}>{{ $header }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group col-md-5">
                            <label for="category_delimiter">2. Delimiter</label>
                            <select id="category_delimiter" name="category_delimiter" class="form-control">
                                <option value=">" {{ isset($wizardData['category_delimiter']) && $wizardData['category_delimiter'] == '>' ? 'selected' : '' }}>Greater Than ( > )</option>
                                <option value="-" {{ isset($wizardData['category_delimiter']) && $wizardData['category_delimiter'] == '-' ? 'selected' : '' }}>Hyphen ( - )</option>
                                <option value="/" {{ isset($wizardData['category_delimiter']) && $wizardData['category_delimiter'] == '/' ? 'selected' : '' }}>Forward Slash ( / )</option>
                                <option value="|" {{ isset($wizardData['category_delimiter']) && $wizardData['category_delimiter'] == '|' ? 'selected' : '' }}>Pipe ( | )</option>
                            </select>
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                             <button type="button" id="parse-categories-btn" class="btn btn-primary btn-block">Parse</button>
                        </div>
                    </div>
                    <hr>
                    <div id="category-mapping-ui" style="display: none;">
                        <div class="row font-weight-bold mb-2">
                            <div class="col-md-5"><label><small>Source Category (from Feed)</small></label></div>
                            <div class="col-md-5"><label><small>Destination Category (from {{ $website->name }})</small></label></div>
                        </div>
                        <div id="category-mapping-container">
                            {{-- Mapping rows will be generated here by JavaScript --}}
                        </div>
                    </div>
                    <div id="category-parser-loader" style="display: none;">
                        <p><i class="la la-spinner la-spin"></i> Parsing categories from feed sample...</p>
                    </div>
                </div>
            </div>

            {{-- Attribute Mapping (WooCommerce Only) --}}
            @if($website->platform === 'woocommerce' && !empty($destination_attributes))
            <div class="card">
                <div class="card-header"><i class="la la-tags"></i> Attribute Mapping</div>
                <div class="card-body">
                    <p class="text-muted"><small>Map columns from your feed directly to your WooCommerce product attributes.</small></p>
                     <div class="row font-weight-bold mb-2">
                        <div class="col-md-5"><label><small>FROM this source column...</small></label></div>
                        <div class="col-md-5"><label><small>TO this destination attribute...</small></label></div>
                    </div>
                    <div id="attribute-mapping-container"></div>
                    <button type="button" id="add-attribute-map" class="btn btn-sm btn-secondary mt-2"><i class="la la-plus"></i> Add Attribute Mapping</button>
                </div>
            </div>
            @endif

        </div>

        {{-- Right Column: Source Fields --}}
        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><i class="la la-list"></i> Available Source Feed Fields</div>
                <div class="list-group list-group-flush" style="max-height: 800px; overflow-y: auto;">
                    @forelse($sourceHeaders as $header)
                        <div class="list-group-item py-2">{{ $header }}</div>
                    @empty
                        <div class="list-group-item">No source headers found.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <a href="{{ route('connection.create.step2') }}" class="btn btn-secondary"><i class="la la-arrow-left"></i> Back to Step 2</a>
                    <button type="submit" class="btn btn-primary">Next: Settings & Schedule <i class="la la-arrow-right"></i></button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- ADVANCED CATEGORY PARSER SCRIPT ---
    const parseBtn = document.getElementById('parse-categories-btn');
    const mappingUi = document.getElementById('category-mapping-ui');
    const mappingContainer = document.getElementById('category-mapping-container');
    const loader = document.getElementById('category-parser-loader');
    const destCategories = @json($destination_categories ?? []);
    const existingMappings = @json($wizardData['category_mappings'] ?? []);
    
    // Function to add a category mapping row
    function addCategoryMappingRow(sourceCat, destCatId, index) {
        const row = document.createElement('div');
        row.classList.add('form-group', 'row', 'align-items-center', 'mb-2');
        row.innerHTML = `
            <div class="col-md-5">
                <input type="text" name="category_mappings[${index}][source]" class="form-control form-control-sm" value="${sourceCat}" readonly>
            </div>
            <div class="col-md-5">
                <select name="category_mappings[${index}][dest]" class="form-control form-control-sm">
                    <option value="">-- Select a category --</option>
                    ${destCategories.map(c => `<option value="${c.id}" ${destCatId == c.id ? 'selected' : ''}>${c.name}</option>`).join('')}
                </select>
            </div>
        `;
        mappingContainer.appendChild(row);
    }
    
    // Check if we have existing mappings to display
    if (existingMappings && existingMappings.length > 0 && destCategories && destCategories.length > 0) {
        // Display existing mappings if we have them
        mappingContainer.innerHTML = '';
        let catIndex = 0;
        
        existingMappings.forEach(mapping => {
            if (mapping.source && mapping.hasOwnProperty('dest')) {
                addCategoryMappingRow(mapping.source, mapping.dest, catIndex);
                catIndex++;
            }
        });
        
        // Show the mapping UI
        mappingUi.style.display = 'block';
    }

    if (parseBtn) {
        parseBtn.addEventListener('click', function() {
            const sourceField = document.getElementById('category_source_field').value;
            const delimiter = document.getElementById('category_delimiter').value;

            if (!sourceField) {
                alert('Please select a Category Source Field.');
                return;
            }

            loader.style.display = 'block';
            mappingUi.style.display = 'none';
            mappingContainer.innerHTML = '';

            fetch('{{ route("connection.parse_categories") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                },
                body: JSON.stringify({ source_field: sourceField, delimiter: delimiter })
            })
            .then(response => response.json())
            .then(data => {
                loader.style.display = 'none';
                if (data.categories && data.categories.length > 0) {
                    let catIndex = 0;
                    mappingContainer.innerHTML = ''; // Clear existing mappings
                    
                    data.categories.forEach(sourceCat => {
                        // Find if this source category already has a mapping
                        let existingDest = '';
                        if (existingMappings && existingMappings.length > 0) {
                            const existing = existingMappings.find(m => m.source === sourceCat);
                            if (existing && existing.dest) {
                                existingDest = existing.dest;
                            }
                        }
                        
                        addCategoryMappingRow(sourceCat, existingDest, catIndex);
                        catIndex++;
                    });
                    
                    mappingUi.style.display = 'block';
                } else {
                    alert('No unique categories could be found using the selected field and delimiter.');
                }
            })
            .catch(error => {
                loader.style.display = 'none';
                console.error('Error parsing categories:', error);
                alert('An error occurred while parsing categories. Please check the browser console.');
            });
        });
    }

    // --- ATTRIBUTE MAPPING SCRIPT ---
    const attrContainer = document.getElementById('attribute-mapping-container');
    const addAttrBtn = document.getElementById('add-attribute-map');
    const sourceHeaders = @json($wizardData['sample_headers'] ?? []);
    const destAttributes = @json($destination_attributes ?? []);
    let attrIndex = 0;

    if (addAttrBtn) {
        addAttrBtn.addEventListener('click', function () {
            const row = document.createElement('div');
            row.classList.add('form-group', 'row', 'align-items-center', 'mb-2');
            row.innerHTML = `
                <div class="col-md-5">
                    <select name="attribute_mappings[${attrIndex}][source]" class="form-control form-control-sm">
                        <option value="">-- Select Source Column --</option>
                        ${sourceHeaders.map(h => `<option value="${h}">${h}</option>`).join('')}
                    </select>
                </div>
                <div class="col-md-5">
                    <select name="attribute_mappings[${attrIndex}][dest]" class="form-control form-control-sm">
                        <option value="">-- Select Destination Attribute --</option>
                        ${destAttributes.map(a => `<option value="${a.id}">${a.name}</option>`).join('')}
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-danger remove-map-row"><i class="la la-trash"></i></button>
                </div>
            `;
            attrContainer.appendChild(row);
            attrIndex++;
        });
        
        attrContainer.addEventListener('click', function (e) {
            if (e.target.closest('.remove-map-row')) {
                e.target.closest('.row').remove();
            }
        });
    }
});
</script>
@endsection