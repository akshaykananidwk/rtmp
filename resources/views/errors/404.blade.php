@extends('layouts.auth')
@section('title', 'Not found')
@section('content')
<h2>404 – Not found</h2><p class="muted">The page you are looking for does not exist.</p>
<a class="btn btn-outline" href="{{ url('/') }}">Home</a> <a class="btn btn-primary" href="{{ url('/admin') }}">Dashboard</a>
@endsection
