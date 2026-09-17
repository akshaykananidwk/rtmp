@extends('layouts.installer')
@section('content')
<h2>System requirements</h2>
<table><tbody>@foreach($result['checks'] as $c)<tr><td>{{ $c['name'] }}</td><td class="small muted">{{ $c['detail'] }}</td><td style="text-align:right">@if($c['pass'] && empty($c['warn']))<span class="badge badge-pass">PASS</span>@elseif($c['pass'])<span class="badge badge-warn">WARN</span>@else<span class="badge badge-fail">FAIL</span>@endif</td></tr>@endforeach</tbody></table>
<div style="margin-top:18px">@if($result['ok'])<a class="btn btn-primary" href="{{ route('install.database') }}">Continue →</a>@else<div class="alert alert-error">Fix the failed requirements, then <a href="{{ route('install.requirements') }}">re-check</a>.</div>@endif</div>
@endsection
