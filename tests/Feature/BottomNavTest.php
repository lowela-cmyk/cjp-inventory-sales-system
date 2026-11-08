<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Bottom nav for phones / tablets / touch screens (UI only). The sidebar and the bottom bar come from the same
 * config/navigation.php, so they list the same pages; at most five slots, "More" only for roles with more than five.
 */
class BottomNavTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
        if ($role === 'driver') {
            DB::table('driver_profiles')->insert(['user_id' => $user->id, 'driver_code' => 'DRV-'.$user->id, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'Missing '.$start);

        return substr($html, $from, strpos($html, $end, $from) - $from);
    }

    /** @return list<string> */
    private function barLabels(string $html): array
    {
        preg_match_all('/<span class="bottom-nav-label">([^<]+)<\/span>/', $this->between($html, 'data-bottom-nav', '</nav>'), $m);

        return $m[1];
    }

    public function test_each_role_gets_its_bar_items_from_the_shared_config(): void
    {
        $cases = [
            'admin' => [route('admin.dashboard'), ['Dashboard', 'Inventory', 'Sales', 'Lifting', 'More']],
            'inventory_officer' => [route('inventory-officer.inventory'), ['Inventory', 'Ledger', 'Alerts']],
            'sales_officer' => [route('sales-officer.sales'), ['Sales', 'Alerts']],
            'dispatch_officer' => [route('dispatch.fuel-lifting'), ['Lifting', 'Ledger', 'Alerts']],
            'driver' => [route('driver.fuel-lifting'), ['Lifting']],
        ];

        foreach ($cases as $role => [$url, $labels]) {
            $html = $this->actingAs($this->user($role))->get($url)->assertOk()->getContent();
            $this->assertSame($labels, $this->barLabels($html), $role);
            $this->assertLessThanOrEqual(5, count($this->barLabels($html)), $role);
            $this->assertSame($role === 'admin', str_contains($html, 'data-more-sheet'), $role.': More only when more than five pages');
        }
    }

    public function test_admin_more_sheet_holds_the_other_pages_profile_and_sign_out_with_the_alerts_badge_on_more(): void
    {
        $admin = $this->user('admin');
        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $sheet = $this->between($html, 'data-more-sheet', '</section>');

        preg_match_all('/<a class="more-link[^"]*" href="([^"]+)"/', $sheet, $links);
        $this->assertSame([route('admin.ledger'), route('admin.alerts'), route('admin.user-management')], $links[1]);
        $this->assertStringContainsString($admin->name, $sheet);
        $this->assertStringContainsString('data-confirm="simple" data-confirm-title="Sign out?"', $sheet);
        // Alerts is inside More, so More carries the unread badge (mirrored from the header bell).
        $more = $this->between($html, 'data-more-toggle', '</button>');
        $this->assertStringContainsString('data-alert-mirror', $more);
    }

    public function test_the_current_page_is_highlighted_on_the_bar_or_on_more(): void
    {
        $admin = $this->user('admin');

        $sales = $this->actingAs($admin)->get(route('admin.sales.customers'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a class="bottom-nav-item is-active" href="'.preg_quote(route('admin.sales'), '/').'" aria-current="page">/', $sales);

        $ledger = $this->actingAs($admin)->get(route('admin.ledger'))->assertOk()->getContent();
        $this->assertStringContainsString('<button class="bottom-nav-item is-active" type="button" aria-haspopup="dialog"', $ledger);
        $this->assertMatchesRegularExpression('/<a class="more-link is-active" href="'.preg_quote(route('admin.ledger'), '/').'" aria-current="page">/', $ledger);
    }

    public function test_alerts_on_the_bar_carries_the_badge_and_sign_out_is_in_the_avatar_menu(): void
    {
        $html = $this->actingAs($this->user('inventory_officer'))->get(route('inventory-officer.inventory'))->assertOk()->getContent();
        $bar = $this->between($html, 'data-bottom-nav', '</nav>');
        $alerts = $this->between($bar, 'href="'.route('inventory-officer.alerts').'"', '</a>');
        $this->assertStringContainsString('data-alert-mirror', $alerts);

        $header = $this->between($html, '<header class="admin-header">', '</header>');
        $this->assertStringContainsString('class="header-signout"', $header);
        $this->assertStringContainsString('data-confirm="simple" data-confirm-title="Sign out?"', $header);
    }

    public function test_the_sidebar_still_lists_every_page_in_order_and_the_layout_allows_safe_areas(): void
    {
        $html = $this->actingAs($this->user('admin'))->get(route('admin.dashboard'))->assertOk()->getContent();
        $sidebar = $this->between($html, '<nav class="side-nav"', '</nav>');
        preg_match_all('/<a class="side-link[^"]*" href="([^"]+)"/', $sidebar, $links);

        $this->assertSame(array_map(fn (array $item): string => route($item['route']), config('navigation.admin.items')), $links[1]);
        $this->assertStringContainsString('viewport-fit=cover', $html);
    }

    public function test_the_bar_breakpoint_covers_phones_tablets_and_touch_screens_up_to_1366px(): void
    {
        $css = File::get(resource_path('css/responsive.css'));

        $this->assertStringContainsString('@media (max-width: 1023px), (pointer: coarse) and (max-width: 1366px)', $css);
        $this->assertStringContainsString('padding-bottom: calc(var(--bottom-nav-h) + env(safe-area-inset-bottom));', $css);
        $this->assertStringContainsString('html.is-keyboard-open .bottom-nav', $css);
    }
}
