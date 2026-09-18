@php($steps = $onboarding->steps())
@if(! $onboarding->isComplete())
<div class="card" style="margin-bottom:18px;border-left:3px solid var(--primary, #4c7dff)">
  <div class="card-header">
    <h3>Getting started</h3>
    <span class="pill">{{ $onboarding->completedCount() }} of {{ count($steps) }} done</span>
  </div>
  <p class="small muted" style="margin:0 0 12px">Finish these and you are live everywhere. Each one is ticked automatically.</p>
  @foreach($steps as $step)
    <div style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border)">
      <div style="font-size:18px;line-height:1.2">{{ $step['done'] ? '✅' : '⬜' }}</div>
      <div style="flex:1">
        <div style="font-weight:600{{ $step['done'] ? ';opacity:.55' : '' }}">{{ $step['title'] }}</div>
        <div class="small muted">{{ $step['detail'] }}</div>
      </div>
      @if(! $step['done'] && $step['route'])
        <a class="btn btn-sm btn-primary" href="{{ route($step['route']) }}">{{ $step['label'] }}</a>
      @endif
    </div>
  @endforeach
</div>
@endif
