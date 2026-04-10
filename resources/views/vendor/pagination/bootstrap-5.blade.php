@if ($paginator->hasPages())
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">

    {{-- Left: record count --}}
    <div class="text-muted font-size-13">
        Showing
        <strong>{{ $paginator->firstItem() }}</strong>
        to
        <strong>{{ $paginator->lastItem() }}</strong>
        of
        <strong>{{ $paginator->total() }}</strong>
        results
    </div>

    {{-- Right: page controls --}}
    <ul class="pagination pagination-rounded mb-0">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <li class="page-item disabled">
                <span class="page-link"><i class="bx bx-chevron-left"></i></span>
            </li>
        @else
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')">
                    <i class="bx bx-chevron-left"></i>
                </a>
            </li>
        @endif

        {{-- Page Numbers --}}
        @foreach ($elements as $element)
            {{-- "Three Dots" Separator --}}
            @if (is_string($element))
                <li class="page-item disabled">
                    <span class="page-link">{{ $element }}</span>
                </li>
            @endif

            {{-- Array Of Links --}}
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <li class="page-item active">
                            <span class="page-link">{{ $page }}</span>
                        </li>
                    @else
                        <li class="page-item">
                            <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                        </li>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')">
                    <i class="bx bx-chevron-right"></i>
                </a>
            </li>
        @else
            <li class="page-item disabled">
                <span class="page-link"><i class="bx bx-chevron-right"></i></span>
            </li>
        @endif

    </ul>
</div>
@endif
