@if ($paginator->hasPages())
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">

    <div class="text-muted font-size-13">
        Page <strong>{{ $paginator->currentPage() }}</strong>
    </div>

    <ul class="pagination pagination-rounded mb-0">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <li class="page-item disabled">
                <span class="page-link"><i class="bx bx-chevron-left"></i> Prev</span>
            </li>
        @else
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">
                    <i class="bx bx-chevron-left"></i> Prev
                </a>
            </li>
        @endif

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <li class="page-item">
                <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next">
                    Next <i class="bx bx-chevron-right"></i>
                </a>
            </li>
        @else
            <li class="page-item disabled">
                <span class="page-link">Next <i class="bx bx-chevron-right"></i></span>
            </li>
        @endif

    </ul>
</div>
@endif
