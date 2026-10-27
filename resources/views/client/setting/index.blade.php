@extends('employee.layouts.app')
@section('employee_content')


<div class="container-xxl flex-grow-1 container-p-y">
    <h4>Settings</h4>
    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul>
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if(Auth::user()->google_id)
                        <form action="{{ route('google.disconnect') }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-danger">
                                Disconnect Google
                            </button>
                        </form>
                    @else
                        <a href="{{ route('google.connect') }}" class="btn btn-primary">
                            Connect Google
                        </a>
                    @endif

                </div>
            </div>
        </div>
    </div>
</div>

@endsection