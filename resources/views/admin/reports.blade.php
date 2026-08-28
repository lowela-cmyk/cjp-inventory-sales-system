@component('layouts.admin', ['title' => 'Reports Tab', 'active' => 'reports'])
    <h2 class="section-title">Reports and A.I Insights</h2>
    <div class="report-intro" style="border-bottom:0;padding:0 0 12px">
        <button class="btn btn-primary" type="button">Generate</button>
        <button class="btn btn-secondary" type="button">Export</button>
    </div>
    <section class="report-panel">
        <div class="report-intro">
            <strong>Click "Generate" to Produce insights from current data.</strong>
        </div>
        <div class="report-sheet"></div>
    </section>
@endcomponent
