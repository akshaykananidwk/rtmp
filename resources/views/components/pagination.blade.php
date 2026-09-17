@if ($paginator->hasPages())
<nav class="pagination">
  @if ($paginator->onFirstPage())<span class="muted">‹</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">‹</a>@endif
  @foreach ($elements as $element)
    @if (is_string($element))<span>{{ $element }}</span>@endif
    @if (is_array($element))@foreach ($element as $page => $url)@if ($page == $paginator->currentPage())<span class="active"><span>{{ $page }}</span></span>@else<a href="{{ $url }}">{{ $page }}</a>@endif @endforeach @endif
  @endforeach
  @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">›</a>@else<span class="muted">›</span>@endif
</nav>
@endif
