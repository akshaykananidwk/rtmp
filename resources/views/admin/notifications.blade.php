@extends('layouts.admin')
@section('title', 'Notifications')
@section('content')
<div class="card"><div class="card-header"><h3>Notifications</h3><form method="post" action="{{ route('admin.notifications.read') }}">@csrf<button class="btn btn-sm btn-outline">Mark all read</button></form></div>
@forelse($notifications as $n)<div class="dest-row" style="{{ $n->read_at ? 'opacity:.7' : '' }}"><div><div class="name">@include('components.badge', ['status' => $n->data['level'] ?? 'info']) {{ $n->data['title'] ?? '' }}</div><div class="meta">{{ $n->data['message'] ?? '' }} · {{ $n->created_at->diffForHumans() }}</div></div>@if(! empty($n->data['url']))<a class="btn btn-sm btn-outline" href="{{ $n->data['url'] }}">Open</a>@endif</div>@empty<div class="empty">No notifications.</div>@endforelse
{{ $notifications->links('components.pagination') }}</div>
@endsection
