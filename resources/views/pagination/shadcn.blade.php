{{--
    Page links in shadcn's Pagination style: ghost buttons, the current page outlined, an ellipsis
    for the pages in between, Previous and Next with chevrons. Set as the default pagination view
    (AppServiceProvider), so every paginated list uses it. Icons are drawn inline: a list that
    pages in place (data-smooth-list) swaps these links in after the page has loaded.
--}}
@if ($paginator->hasPages())
@php
    $svg = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    $button = 'inline-flex items-center justify-center gap-1 h-9 rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40';
    $ghost = $button . ' text-slate-700 hover:bg-slate-100 hover:text-ink';
    $disabled = $button . ' text-slate-400 opacity-50 pointer-events-none';
@endphp
<nav role="navigation" aria-label="Pagination" class="flex flex-col-reverse sm:flex-row items-center justify-between gap-3">
    <p class="text-sm text-slate-500">
        Showing <span class="font-medium text-ink">{{ $paginator->firstItem() }}</span>–<span class="font-medium text-ink">{{ $paginator->lastItem() }}</span>
        of <span class="font-medium text-ink">{{ $paginator->total() }}</span>
    </p>

    <ul class="flex flex-row items-center gap-1">
        <li>
            @if ($paginator->onFirstPage())
                <span class="{{ $disabled }} pl-2.5 pr-3" aria-disabled="true">
                    <svg {!! $svg !!} class="w-4 h-4"><path d="m15 18-6-6 6-6"/></svg> Previous
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $ghost }} pl-2.5 pr-3" aria-label="Go to the previous page">
                    <svg {!! $svg !!} class="w-4 h-4"><path d="m15 18-6-6 6-6"/></svg> Previous
                </a>
            @endif
        </li>

        {{-- Phones: where you are, instead of every page number --}}
        <li class="sm:hidden px-2 text-sm text-slate-500">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</li>

        @foreach ($elements as $element)
            @if (is_string($element))
                <li class="hidden sm:block">
                    <span class="flex h-9 w-9 items-center justify-center text-slate-400" aria-hidden="true">
                        <svg {!! $svg !!} class="w-4 h-4"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                    </span>
                </li>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    <li class="hidden sm:block">
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="{{ $button }} min-w-9 px-3 border border-slate-200 bg-white text-ink shadow-sm">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="{{ $ghost }} min-w-9 px-3" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                        @endif
                    </li>
                @endforeach
            @endif
        @endforeach

        <li>
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $ghost }} pl-3 pr-2.5" aria-label="Go to the next page">
                    Next <svg {!! $svg !!} class="w-4 h-4"><path d="m9 18 6-6-6-6"/></svg>
                </a>
            @else
                <span class="{{ $disabled }} pl-3 pr-2.5" aria-disabled="true">
                    Next <svg {!! $svg !!} class="w-4 h-4"><path d="m9 18 6-6-6-6"/></svg>
                </span>
            @endif
        </li>
    </ul>
</nav>
@endif
