@component('layouts.admin', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>
    <div class="alert-stack">
        <div class="alert-bar alert-critical"><div class="alert-icon">!</div><div><div class="alert-title">ALT-000001 - Stock critically low</div><div>Premium inventory is below operating threshold for scheduled deliveries.</div></div><div class="alert-time">8/27/2026 08:10 AM</div></div>
        <div class="alert-bar alert-critical"><div class="alert-icon">!</div><div><div class="alert-title">ALT-000002 - Payment overdue</div><div>SLS-000003 remains unpaid and requires office follow-up.</div></div><div class="alert-time">8/27/2026 09:15 AM</div></div>
        <div class="alert-bar alert-warning"><div class="alert-icon">!</div><div><div class="alert-title">ALT-000003 - Pending lifting activity</div><div>LFT-000002 is scheduled but has not been marked hauled.</div></div><div class="alert-time">8/27/2026 10:40 AM</div></div>
        <div class="alert-bar alert-warning"><div class="alert-icon">!</div><div><div class="alert-title">ALT-000004 - Low diesel inventory</div><div>Diesel stock is nearing the reorder level.</div></div><div class="alert-time">8/27/2026 11:05 AM</div></div>
    </div>
@endcomponent
