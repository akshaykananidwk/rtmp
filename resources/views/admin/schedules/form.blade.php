@extends('layouts.admin')
@section('title', $schedule->exists ? 'Edit Schedule' : 'Schedule Stream')
@section('content')
@php($local = $schedule->scheduled_at?->timezone($schedule->timezone))
<div class="card" style="max-width:760px">
<form method="post" action="{{ $schedule->exists ? route('admin.schedules.update', $schedule) : route('admin.schedules.store') }}" enctype="multipart/form-data">@csrf @if($schedule->exists)@method('PUT')@endif
  <div class="field"><label>Title</label><input type="text" name="title" value="{{ old('title', $schedule->title) }}" required maxlength="150" placeholder="Dwarka Live Event"></div>
  <div class="field"><label>Description</label><textarea name="description">{{ old('description', $schedule->description) }}</textarea></div>
  <div class="form-row">
    <div class="field"><label>Date</label><input type="date" name="date" value="{{ old('date', $local?->format('Y-m-d')) }}" required></div>
    <div class="field"><label>Time</label><input type="time" name="time" value="{{ old('time', $local?->format('H:i')) }}" required></div>
    <div class="field"><label>Timezone</label><select name="timezone">@foreach($timezones as $tz)<option value="{{ $tz }}" {{ old('timezone', $schedule->timezone) === $tz ? 'selected' : '' }}>{{ $tz }}</option>@endforeach</select></div>
  </div>
  <div class="field"><label>Stream key (source)</label><select name="stream_endpoint_id" required>@foreach($endpoints as $e)<option value="{{ $e->id }}" {{ old('stream_endpoint_id', $schedule->stream_endpoint_id) === $e->id ? 'selected' : '' }}>{{ $e->name }}</option>@endforeach</select></div>
  <div class="field"><label>Destinations</label>@foreach($destinations as $d)<label class="check" style="margin-bottom:6px"><input type="checkbox" name="destination_ids[]" value="{{ $d->id }}" {{ in_array($d->id, old('destination_ids', $selected)) ? 'checked' : '' }}> {{ $d->name }} <span class="pill">{{ $d->platform }}</span></label>@endforeach</div>
  <div class="form-row">
    <div class="field"><label class="check"><input type="checkbox" name="auto_start" value="1" {{ old('auto_start', $schedule->auto_start) ? 'checked' : '' }}> Auto start distribution when due (source must be publishing)</label></div>
    <div class="field"><label class="check"><input type="checkbox" name="recording_enabled" value="1" {{ old('recording_enabled', $schedule->recording_enabled) ? 'checked' : '' }}> Recording enabled</label></div>
  </div>
  <div class="form-row">
    <div class="field"><label class="check"><input type="checkbox" name="auto_stop" value="1" {{ old('auto_stop', $schedule->auto_stop) ? 'checked' : '' }}> Auto stop at</label></div>
    <div class="field"><label>Auto stop time</label><input type="time" name="auto_stop_time" value="{{ old('auto_stop_time', $schedule->auto_stop_at?->timezone($schedule->timezone)->format('H:i')) }}"></div>
  </div>
  <div class="form-row"><div class="field"><label>Thumbnail (jpg/png/webp, max 4MB)</label><input type="file" name="thumbnail" accept="image/png,image/jpeg,image/webp"></div><div class="field"><label>Notes</label><textarea name="notes">{{ old('notes', $schedule->notes) }}</textarea></div></div>
  <button class="btn btn-primary">{{ $schedule->exists ? 'Save' : 'Schedule' }}</button> <a class="btn btn-outline" href="{{ route('admin.schedules.index') }}">Cancel</a>
</form></div>
@endsection
