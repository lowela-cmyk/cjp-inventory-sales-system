@props(['pages' => [], 'outOf' => false])

{{-- Part 17: a long detail list split into section cards shown one at a time, with "< 1 of 3 >" arrows (app.js).
     $pages: list of ['title' => ..., 'fields' => [label => value]]; a value may be an HtmlString (links). --}}
@php $count = count($pages); @endphp
<div {{ $attributes->class(['detail-pages']) }} data-detail-pages>
    @foreach ($pages as $page)
        <section class="detail-page" data-detail-page @if (! $loop->first) hidden @endif>
            <h3 class="detail-page-title">{{ $page['title'] }}</h3>
            <div class="detail-page-card detail-grid">
                @foreach ($page['fields'] as $label => $value)
                    <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                @endforeach
            </div>
        </section>
    @endforeach
    @if ($count > 1)
        <div class="detail-pager">
            <button class="detail-pager-arrow" type="button" data-detail-page-step="-1" aria-label="Previous section" disabled>&lsaquo;</button>
            <span data-detail-page-label>1 {{ $outOf ? 'out of' : 'of' }} {{ $count }}</span>
            <button class="detail-pager-arrow" type="button" data-detail-page-step="1" aria-label="Next section">&rsaquo;</button>
        </div>
    @endif
</div>
