<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * System Analysis compliance, Phase 4: Dashboard, Reports and Driver never scroll sideways at 768/1366px.
 * The browser sweep is the real check; this guards the CSS rules it depends on.
 */
class CompliancePhase4Test extends TestCase
{
    public function test_grid_tracks_can_shrink_below_their_content(): void
    {
        $styles = File::get(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.chart-panel,\s*\.dashboard-grid > \* \{\s*min-width: 0;/', $styles);
        $this->assertMatchesRegularExpression('/\.chart-panel canvas \{\s*max-width: 100%;/', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(min(100%, 290px), 1fr));', $styles);
        $this->assertStringContainsString('grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr));', $styles);
        $this->assertMatchesRegularExpression('/@media \(max-width: 1180px\) \{\s*\.dashboard-grid \{\s*grid-template-columns: minmax\(0, 1fr\);/', $styles);
        $this->assertMatchesRegularExpression('/@media \(max-width: 1023px\) \{\s*\.driver-overview-card \{\s*grid-template-columns: minmax\(0, 1fr\);/', $styles);
    }

    public function test_firefox_manual_checklist_covers_the_main_flows(): void
    {
        $checklist = File::get(base_path('docs/FIREFOX_CHECKLIST.md'));

        foreach (['Log in', 'Record a purchase', 'two purchases', 'Lifted', 'withdrawal receipt', 'Record a sale', 'payment', 'Delivered', 'View Transactions', 'hover'] as $step) {
            $this->assertStringContainsStringIgnoringCase($step, $checklist);
        }
    }
}
