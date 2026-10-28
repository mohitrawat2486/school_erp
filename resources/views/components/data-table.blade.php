@props(['records','title','icon'=>'sections','stickyActions'=>true])
<section class="data-panel" aria-label="{{ $title }}">
    <div class="data-panel-heading"><div class="data-panel-title"><span class="data-panel-icon"><x-icon :name="$icon" /></span><h2>{{ $title }}</h2><span class="record-count">{{ number_format($records->total()) }}</span></div><span class="data-panel-hint">{{ $records->total() === 1 ? '1 record' : number_format($records->total()).' records' }}</span></div>
    <div class="data-table-region overflow-x-auto {{ $stickyActions ? 'has-sticky-actions' : '' }}" role="region" aria-label="{{ $title }} table" tabindex="0">{{ $slot }}</div>
    <div class="data-panel-footer">
        <p class="result-summary">@if($records->total()) Showing <strong>{{ number_format($records->firstItem()) }}–{{ number_format($records->lastItem()) }}</strong> of <strong>{{ number_format($records->total()) }}</strong> records @else No records to display @endif</p>
        @if($records->hasPages())
            @php($firstPage = max(1, $records->currentPage() - 1))
            @php($lastPage = min($records->lastPage(), $records->currentPage() + 1))
            <nav class="list-pagination" aria-label="{{ $title }} pagination">
                @if($records->onFirstPage())
                    <span class="pagination-link is-disabled" aria-disabled="true" aria-label="Previous page"><x-icon name="chevron-left" /><span class="pagination-word">Previous</span></span>
                @else
                    <a class="pagination-link" href="{{ $records->previousPageUrl() }}" rel="prev" aria-label="Previous page"><x-icon name="chevron-left" /><span class="pagination-word">Previous</span></a>
                @endif
                @if($firstPage > 1)
                    <a class="pagination-link" href="{{ $records->url(1) }}" aria-label="Page 1">1</a>
                    @if($firstPage > 2)<span class="pagination-gap" aria-hidden="true">…</span>@endif
                @endif
                @foreach(range($firstPage, $lastPage) as $page)
                    @if($page === $records->currentPage())
                        <span class="pagination-link is-current" aria-current="page" aria-label="Page {{ $page }}">{{ $page }}</span>
                    @else
                        <a class="pagination-link" href="{{ $records->url($page) }}" aria-label="Page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
                @if($lastPage < $records->lastPage())
                    @if($lastPage < $records->lastPage() - 1)<span class="pagination-gap" aria-hidden="true">…</span>@endif
                    <a class="pagination-link" href="{{ $records->url($records->lastPage()) }}" aria-label="Page {{ $records->lastPage() }}">{{ $records->lastPage() }}</a>
                @endif
                @if($records->hasMorePages())
                    <a class="pagination-link" href="{{ $records->nextPageUrl() }}" rel="next" aria-label="Next page"><span class="pagination-word">Next</span><x-icon name="chevron-right" /></a>
                @else
                    <span class="pagination-link is-disabled" aria-disabled="true" aria-label="Next page"><span class="pagination-word">Next</span><x-icon name="chevron-right" /></span>
                @endif
            </nav>
        @endif
    </div>
</section>
