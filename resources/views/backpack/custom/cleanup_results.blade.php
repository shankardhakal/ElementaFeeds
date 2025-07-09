@extends(backpack_view('layouts.top_left'))

@section('content')
<div class="container">
    <h1>Cleanup Results</h1>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Website</th>
                <th>Status</th>
                <th>Message</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($results as $result)
            <tr>
                <td>{{ $result['website'] }}</td>
                <td>{{ $result['status'] }}</td>
                <td>{{ $result['message'] }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
