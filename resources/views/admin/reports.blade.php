@component('layouts.admin', ['title' => 'Reports Tab', 'subtitle' => 'Reports and AI Insights', 'active' => 'reports'])
    <h2 class="section-title">Reports Tab</h2>
    <section class="report-panel">
        <div class="report-intro">
            <strong>Click "Generate" to Produce Insights from Current Data</strong>
            <button class="btn btn-primary" type="button">Generate</button>
        </div>
        <div class="report-sheet">
            Report output preview area.
        </div>
    </section>
@endcomponent
