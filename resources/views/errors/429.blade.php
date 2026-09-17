@extends('layouts.auth')
@section('title', 'Too many requests')
@section('content')
<h2>429 – Too many requests</h2><p class="muted">Too many requests. Please slow down.</p>
<a class="btn btn-outline" href="{{ url('/') }}">Home</a> <a class="btn btn-primary" href="{{ url('/admin') }}">Dashboard</a>
@endsection
