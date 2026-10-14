<?php

namespace Tests\Feature\Audit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * AUD-H03 (HIGH): several toolbars show "Date / Status / Depot / Location / Company / Fuel Type (All)"
 * as plain unnamed submit buttons. They look like filters but only reload the page with the search text.
 */
#[Group('audit')]
class H03PlaceholderFilterButtonsTest extends AuditTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function pages(): array
    {
        return [
            'admin sales' => ['admin', 'admin.sales'],
            'admin fuel lifting' => ['admin', 'admin.fuel-lifting'],
            'admin alerts' => ['admin', 'admin.alerts'],
            'admin ledger' => ['admin', 'admin.ledger'],
            'inventory officer ledger' => ['inventory', 'inventory-officer.ledger'],
            'sales officer sales' => ['sales', 'sales-officer.sales'],
        ];
    }

    #[DataProvider('pages')]
    public function test_toolbar_filters_are_real_inputs_not_unnamed_submit_buttons(string $actor, string $route): void
    {
        $a = $this->actors();
        $html = $this->actingAs($a[$actor])->get(route($route))->assertOk()->getContent();

        preg_match_all('/<button class="btn btn-primary" type="submit">(Date|Status|Depot|Location|Company|Fuel Type \(All\))<\/button>/', $html, $placeholders);

        $this->assertSame([], $placeholders[1], 'Placeholder filter buttons that do not filter: '.implode(', ', array_unique($placeholders[1])));
    }
}
