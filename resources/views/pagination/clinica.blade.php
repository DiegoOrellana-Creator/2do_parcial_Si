{{-- Paginación con HTML y CSS convencional (la vista por defecto de Laravel usa Tailwind) --}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Paginación">
        <ul class="pagination__list">
            @if ($paginator->onFirstPage())
                <li><span class="pagination__link is-disabled" aria-disabled="true">Anterior</span></li>
            @else
                <li><a class="pagination__link" href="{{ $paginator->previousPageUrl() }}" rel="prev">Anterior</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span class="pagination__link is-disabled" aria-disabled="true">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li><span class="pagination__link is-current" aria-current="page">{{ $page }}</span></li>
                        @else
                            <li><a class="pagination__link" href="{{ $url }}" aria-label="Página {{ $page }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a class="pagination__link" href="{{ $paginator->nextPageUrl() }}" rel="next">Siguiente</a></li>
            @else
                <li><span class="pagination__link is-disabled" aria-disabled="true">Siguiente</span></li>
            @endif
        </ul>
    </nav>
@endif
