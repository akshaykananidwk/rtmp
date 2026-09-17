@extends('layouts.auth')
@section('title', 'Page expired')
@section('content')
<h2>419 – Page expired</h2><p class="muted">Your session expired. Please go back and try again.</p>
<a class="btn btn-outline" href="{{ url('/') }}">Home</a> <a class="btn btn-primary" href="{{ url('/admin') }}">Dashboard</a>
@endsection
