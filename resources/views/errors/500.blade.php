@extends('layouts.auth')
@section('title', 'Something went wrong')
@section('content')
<h2>Something went wrong.</h2>
<p class="muted">Our team has been notified. Please try again in a moment.</p>
<p><strong>Reference ID:</strong> <span class="mono">{{ $reference ?? 'ERR-UNKNOWN' }}</span></p>
<a class="btn btn-outline" href="{{ url('/') }}">Home</a> <a class="btn btn-primary" href="{{ url()->previous() }}">Go back</a>
@endsection
