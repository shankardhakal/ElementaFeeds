@extends(backpack_view('layouts.top_left'))
@section('header')
    <div class="container-fluid">
        <h2>
            <span class="text-capitalize">Create New Connection</span>
            <small>Step 1: Source, Destination & Name.</small>
        </h2>
    </div>
@endsection

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <i class="la la-feather"></i> Choose Source & Destination
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('connection.store.step1') }}">
                    @csrf

                    {{-- Source Feed Dropdown --}}
                    <div class="form-group">
                        <label for="feed_id">1. Select Source Feed</label>
                        <select class="form-control @error('feed_id') is-invalid @enderror" id="feed_id" name="feed_id" required>
                            <option value="" disabled {{ old('feed_id', $wizardData['feed_id'] ?? '') ? '' : 'selected' }}>-- Please select a feed --</option>
                            @foreach ($networks as $network)
                                @if($network->feeds->count() > 0)
                                <optgroup label="{{ $network->name }}">
                                    @foreach ($network->feeds as $feed)
                                        <option value="{{ $feed->id }}" {{ old('feed_id', $wizardData['feed_id'] ?? '') == $feed->id ? 'selected' : '' }}>
                                            {{ $feed->name }} ({{ $feed->language }})
                                        </option>
                                    @endforeach
                                </optgroup>
                                @endif
                            @endforeach
                        </select>
                        <small class="form-text text-muted">This is the feed you want to import products from.</small>
                        @error('feed_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <hr>

                    {{-- Destination Website Dropdown --}}
                    <div class="form-group">
                        <label for="website_id">2. Select Destination Website</label>
                        <select class="form-control @error('website_id') is-invalid @enderror" id="website_id" name="website_id" required>
                            <option value="" disabled {{ old('website_id', $wizardData['website_id'] ?? '') ? '' : 'selected' }}>-- Please select a website --</option>
                            @foreach ($websites as $website)
                                <option value="{{ $website->id }}" {{ old('website_id', $wizardData['website_id'] ?? '') == $website->id ? 'selected' : '' }}>
                                    {{ $website->name }} (Platform: {{ $website->platform }})
                                </option>
                            @endforeach
                        </select>
                        <small class="form-text text-muted">This is the website where products will be created.</small>
                        @error('website_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <hr>

                    {{-- Connection Name Input --}}
                    <div class="form-group">
                        <label for="name">3. Give this Connection a Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $wizardData['name'] ?? '') }}" required placeholder="e.g., HobbyHall Pets to Swedish Pet Store">
                        <small class="form-text text-muted">This name is for your reference on the main dashboard.</small>
                         @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">
                            Next: Preview & Filter <i class="la la-arrow-right"></i>
                        </button>
                        <a href="{{ backpack_url('connection') }}" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection