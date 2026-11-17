<nav class="cjp-pagination" aria-label="Table pagination" data-table-pagination data-page-name="{{ $paginator->getPageName() }}" data-last-page="{{ $paginator->lastPage() }}" data-total="{{ $paginator->total() }}">
    <p>Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }} records. Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}.</p>
    <form method="GET" action="{{ $paginator->path() }}" data-page-size-form>
        @foreach (request()->query() as $key => $value)
            @if ($key !== 'per_page' && $key !== 'page' && ! str_ends_with($key, '_page') && is_scalar($value))
                <input type="hidden" name="{{ $key }}" value="{{ $value }}">
            @endif
        @endforeach
        <label>Records per page <select name="per_page" aria-label="Records per page" onchange="this.form.requestSubmit()">
            @foreach (\App\Support\TablePagination::SIZES as $size)
                <option value="{{ $size }}" @selected($paginator->perPage() === $size)>{{ $size }}</option>
            @endforeach
        </select></label>
    </form>
    <div class="cjp-page-links">
        @if ($paginator->onFirstPage()) <span aria-disabled="true">Previous</span>
        @else <a href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a> @endif
        @foreach ($elements as $element)
            @if (is_string($element)) <span>{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page === $paginator->currentPage()) <span aria-current="page">{{ $page }}</span>
                    @else <a href="{{ $url }}" aria-label="Page {{ $page }}">{{ $page }}</a> @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages()) <a href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @else <span aria-disabled="true">Next</span> @endif
    </div>
</nav>
