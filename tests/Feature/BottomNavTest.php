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
            // Admin: all seven pages on the bar, no "More" (Account Requests is a tab under Users).
            'admin' => [route('admin.dashboard'), ['Home', 'Inventory', 'Ledger', 'Lifting', 'Sales', 'Alerts', 'Users']],
            // Other roles are unchanged.
            'inventory_officer' => [route('inventory-officer.inventory'), ['Inventory', 'Ledger', 'Alerts']],
            'sales_officer' => [route('sales-officer.sales'), ['Sales', 'Alerts']],
            'dispatch_officer' => [route('dispatch.fuel-lifting'), ['Lifting', 'Ledger', 'Alerts']],
            'driver' => [route('driver.fuel-lifting'), ['Lifting']],
        ];

        foreach ($cases as $role => [$url, $labels]) {
            $html = $this->actingAs($this->user($role))->get($url)->assertOk()->getContent();
            $this->assertSame($labels, $this->barLabels($html), $role);
            $this->assertStringNotContainsString('data-more-sheet', $html, $role.': no "More" sheet');
            $this->assertStringNotContainsString('data-more-toggle', $html, $role.': no "More" button');
            // Only the seven-slot Admin bar uses the compact, scrollable layout.
            $this->assertSame($role === 'admin', str_contains($html, '<nav class="bottom-nav is-wide"'), $role);
        }
    }

    public function test_admin_bar_links_every_page_and_carries_the_alerts_badge_and_the_pending_requests_dot_on_users(): void
    {
        $admin = $this->user('admin');
        // A pending sign-up after the admin last opened User Management puts the red dot on Users.
        $this->actingAs($admin)->get(route('admin.user-management', ['tab' => 'account-requests']))->assertOk();
        $this->app->forgetScopedInstances(); // the per-request unseen-modules cache; real requests never share it
        $this->travel(1)->minutes();
        $this->user('sales_officer')->forceFill(['approval_status' => 'pending'])->save();

        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();
        $bar = $this->between($html, 'data-bottom-nav', '</nav>');
        preg_match_all('/<a class="bottom-nav-item[^"]*" href="([^"]+)"/', $bar, $links);

        $this->assertSame([
            route('admin.dashboard'), route('admin.inventory'), route('admin.ledger'), route('admin.fuel-lifting'),
            route('admin.sales'), route('admin.alerts'), route('admin.user-management'),
        ], $links[1]);
        $this->assertStringNotContainsString('Requests', $bar);
        $this->assertStringContainsString('data-alert-mirror', $this->between($bar, 'href="'.route('admin.alerts').'"', '</a>'));
        $this->assertStringContainsString('data-bottom-dot="user-management"', $this->between($bar, 'href="'.route('admin.user-management').'"', '</a>'));
        // Sign Out stays reachable without "More": the avatar menu.
        $this->assertStringContainsString('class="header-signout"', $this->between($html, '<header class="admin-header">', '</header>'));
    }

    public function test_the_current_page_is_highlighted_and_users_covers_the_account_requests_tab(): void
    {
        $admin = $this->user('admin');
        $active = fn (string $html): array => preg_match_all('/<a class="bottom-nav-item is-active" href="([^"]+)" aria-current="page"/', $this->between($html, 'data-bottom-nav', '</nav>'), $m) ? $m[1] : [];

        $this->assertSame([route('admin.sales')], $active($this->actingAs($admin)->get(route('admin.sales.customers'))->assertOk()->getContent()));
        $this->assertSame([route('admin.ledger')], $active($this->actingAs($admin)->get(route('admin.ledger'))->assertOk()->getContent()));
        $this->assertSame([route('admin.user-management')], $active($this->actingAs($admin)->get(route('admin.user-management', ['tab' => 'drivers']))->assertOk()->getContent()));
        $this->assertSame([route('admin.user-management')], $active($this->actingAs($admin)->get(route('admin.user-management', ['tab' => 'account-requests']))->assertOk()->getContent()));
        $this->assertSame([route('admin.user-management')], $active($this->actingAs($admin)->get(route('admin.user-management'))->assertOk()->getContent()));
    }

    public function test_the_more_sheet_still_works_when_a_role_has_more_pages_than_slots(): void
    {
        config(['navigation.admin.max_slots' => 5]);
        $admin = $this->user('admin');
        $html = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertSame(['Home', 'Inventory', 'Ledger', 'Lifting', 'More'], $this->barLabels($html));
        $sheet = $this->between($html, 'data-more-sheet', '</section>');
        preg_match_all('/<a class="more-link[^"]*" href="([^"]+)"/', $sheet, $links);
        $this->assertSame([route('admin.sales'), route('admin.alerts'), route('admin.user-management')], $links[1]);
        $this->assertStringNotContainsString('Account Requests', $sheet);
        $this->assertStringContainsString('data-confirm="simple" data-confirm-title="Sign out?"', $sheet);
        $this->assertStringContainsString('data-alert-mirror', $this->between($html, 'data-more-toggle', '</button>'));
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

    public function test_the_sidebar_lists_its_pages_in_order_without_account_requests_and_the_layout_allows_safe_areas(): void
    {
        $html = $this->actingAs($this->user('admin'))->get(route('admin.dashboard'))->assertOk()->getContent();
        $sidebar = $this->between($html, '<nav class="side-nav"', '</nav>');
        preg_match_all('/<a class="side-link[^"]*" href="([^"]+)"/', $sidebar, $links);

        // Every page, in order, same as the bar; Account Requests is a User Management tab, not its own entry.
        $this->assertSame(array_map(fn (array $item): string => route($item['route']), config('navigation.admin.items')), $links[1]);
        $this->assertCount(7, $links[1]);
        $this->assertStringNotContainsString('Account Requests', $sidebar);
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
