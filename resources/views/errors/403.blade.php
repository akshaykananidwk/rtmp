@extends('layouts.auth')
@section('title', 'Forbidden')
@section('content')
<h2>403 – Forbidden</h2><p class="muted">You do not have permission to access this page.</p>
<a class="btn btn-outline" href="{{ url('/') }}">Home</a> <a class="btn btn-primary" href="{{ url('/admin') }}">Dashboard</a>
@endsection
