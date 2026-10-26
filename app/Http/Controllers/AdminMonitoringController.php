<?php

namespace App\Http\Controllers;

use App\Services\AlertBellService;
use App\Services\AlertSyncService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminMonitoringController extends Controller
{
    private const ALERT_TYPES = ['inventory', 'purchase', 'haul', 'delivery', 'payment', 'receivable', 'discrepancy'];

    public function alerts(Request $request): View
    {
        return $this->alertsPage($request, 'admin.alerts', null);
    }

    public function inventoryOfficerAlerts(Request $request): View
    {
        return $this->alertsPage($request, 'inventory-officer.alerts', AlertBellService::ROLE_ALERTS['inventory_officer']['types']);
    }

    public function salesOfficerAlerts(Request $request): View
    {
        return $this->alertsPage($request, 'sales-officer.alerts', AlertBellService::ROLE_ALERTS['sales_officer']['types']);
    }

    public function dispatchAlerts(Request $request): View
    {
        return $this->alertsPage($request, 'dispatch.alerts', AlertBellService::ROLE_ALERTS['dispatch_officer']['types']);
    }

    public function markAlertRead(Request $request, int $alert): RedirectResponse
    {
        $this->visibleAlert($request, $alert);
        $this->markRead($request, $alert);

        return back()->with('status', 'Alert marked as read.');
    }

    /**
     * Part 12: View opens the alert's record in its module and tab, searched so the row is on the first page, and
     * highlighted (?highlight=CODE, see app.js) until the user clicks it. Viewing marks the alert read. A record that
     * was deleted since shows a toast on the Alerts page instead of an error.
     */
    public function viewAlert(Request $request, int $alert): RedirectResponse
    {
        $row = $this->visibleAlert($request, $alert);
        $this->markRead($request, $alert);

        $target = $this->alertTarget($row, (string) $request->user()->role);
        if (! $target) {
            return redirect()
                ->route(AlertBellService::ROLE_ALERTS[(string) $request->user()->role]['route'])
                ->with('status', 'This record is no longer available.')
                ->with('toast_type', 'warning')
                ->with('toast_title', 'Record not found');
        }

        [$route, $code] = $target;

        return redirect()->route($route, $code ? ['search' => $code, 'highlight' => $code] : []);
    }

    /** The alert, when the user's role may see it (the same role → types map drives the pages and the bell). */
    private function visibleAlert(Request $request, int $alert): object
    {
        // null = every type (Admin); a role without an Alerts page may see nothing.
        $role = (string) $request->user()->role;
        $allowedTypes = array_key_exists($role, AlertBellService::ROLE_ALERTS) ? AlertBellService::ROLE_ALERTS[$role]['types'] : [];
        $row = DB::table('alerts')->where('id', $alert)
            ->when(is_array($allowedTypes), fn (Builder $query): Builder => $query->whereIn('type', $allowedTypes))
            ->first(['id', 'type', 'reference_type', 'reference_id']);
        abort_unless($row !== null, 404);

        return $row;
    }

    private function markRead(Request $request, int $alert): void
    {
        DB::table('alert_reads')->upsert([[
            'alert_id' => $alert,
            'user_id' => $request->user()->id,
            'read_at' => now(),
        ]], ['alert_id', 'user_id'], ['read_at']);
    }

    /**
     * Where an alert's record is listed for this role: [route name, record code to search and highlight], or null when
     * the record no longer exists. Lifts open on Fuel Lifting (Schedule tab while active, Hauled tab once done); a role
     * without Fuel Lifting sees the lift's purchase. Purchases open on Inventory > Purchases, sales on Receivables.
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function alertTarget(object $alert, string $role): ?array
    {
        $prefix = match ($role) {
            'admin' => 'admin',
            'inventory_officer' => 'inventory-officer',
            'sales_officer' => 'sales-officer',
            'dispatch_officer' => 'dispatch',
            default => null,
        };
        if (! $prefix || ! $alert->reference_id) {
            return null;
        }

        $purchase = fn (int $purchaseId): ?array => ($code = DB::table('purchases')->where('id', $purchaseId)->whereNull('deleted_at')->value('purchase_code'))
            ? [in_array($role, ['admin', 'inventory_officer'], true) ? $prefix.'.inventory' : null, $code]
            : null;
        $sale = fn (?int $saleId): ?array => $saleId && ($code = DB::table('sales')->where('id', $saleId)->whereNull('deleted_at')->value('sale_code'))
            ? [in_array($role, ['admin', 'sales_officer'], true) ? $prefix.'.sales' : null, $code]
            : null;

        $target = match ($alert->reference_type) {
            'haul' => (function () use ($alert, $prefix, $role, $purchase): ?array {
                $haul = DB::table('hauls')
                    ->leftJoin('lifting_schedules', 'lifting_schedules.id', '=', 'hauls.lifting_schedule_id')
                    ->where('hauls.id', $alert->reference_id)
                    ->whereNull('hauls.deleted_at')
                    ->first(['hauls.purchase_id', 'hauls.haul_code', 'hauls.status', 'lifting_schedules.schedule_code']);
                if (! $haul) {
                    return null;
                }
                if (! in_array($role, ['admin', 'dispatch_officer'], true)) {
                    return $purchase((int) $haul->purchase_id);
                }

                return [$prefix.(in_array($haul->status, ['completed', 'cancelled'], true) ? '.fuel-lifting.hauled' : '.fuel-lifting'), $haul->schedule_code ?: $haul->haul_code];
            })(),
            'purchase' => $purchase((int) $alert->reference_id),
            // An order alert takes Dispatch to the Schedule tab (to put the order on a lift).
            'sale', 'receivable' => $role === 'dispatch_officer'
                ? (DB::table('sales')->where('id', $alert->reference_id)->whereNull('deleted_at')->exists() ? ['dispatch.fuel-lifting', null] : null)
                : $sale((int) $alert->reference_id),
            'payment' => $sale((int) DB::table('payments')->where('id', $alert->reference_id)->value('sale_id')),
            default => null,
        };

        return $target && $target[0] ? $target : null;
    }

    /**
     * @param  array<int, string>|null  $types
     * @param  array{read?: ?string, severity?: ?string, type?: ?string}  $filters
     */
    private function alertRows(?string $search, ?array $types = null, ?int $userId = null, ?string $role = null, array $filters = [])
    {
        return DB::table('alerts')
            ->leftJoin('alert_reads', function ($join) use ($userId): void {
                $join->on('alert_reads.alert_id', '=', 'alerts.id')
                    ->where('alert_reads.user_id', '=', $userId ?? 0);
            })
            ->when($types, fn (Builder $query, array $types): Builder => $query->whereIn('type', $types))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('alerts.type', $type))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity): Builder => $query->where('alerts.severity', $severity))
            // Part 12: resolved alerts leave the list; the Status filter "Resolved" shows them.
            ->when(($filters['read'] ?? null) === 'resolved', fn (Builder $query): Builder => $query->where('alerts.status', 'resolved'),
                fn (Builder $query): Builder => $query->where('alerts.status', '!=', 'resolved'))
            ->when(($filters['read'] ?? null) === 'read', fn (Builder $query): Builder => $query->whereNotNull('alert_reads.read_at'))
            ->when(($filters['read'] ?? null) === 'unread', fn (Builder $query): Builder => $query->whereNull('alert_reads.read_at'))
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'alerts.alert_code',
                'alerts.type',
                'alerts.severity',
                'alerts.title',
                'alerts.message',
                'alerts.reference_type',
                'alerts.status',
            ]))
            ->orderByDesc('alerts.created_at')
            ->orderByDesc('alerts.id')
            ->get(['alerts.*', 'alert_reads.read_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'class' => $row->severity === 'critical' ? 'alert-critical' : 'alert-warning',
                'type' => $row->severity === 'critical' ? 'critical' : 'warning',
                // Part 12: the alert ID stays searchable but is not shown; neither is the type / reference label.
                'title' => $row->title,
                'message' => $row->message,
                'time' => $this->formatDateTime($row->created_at),
                'status' => $this->label($row->status),
                'read' => $row->read_at !== null,
                'read_state' => $row->status === 'resolved' ? 'Resolved' : ($row->read_at ? 'Read' : 'Unread'),
                'resolved' => $row->status === 'resolved',
                'action_url' => $role && isset(AlertBellService::ROLE_ALERTS[$role])
                    ? route(str_replace('.alerts', '.alerts.view', AlertBellService::ROLE_ALERTS[$role]['route']), $row->id)
                    : null,
            ]);
    }

    /**
     * @param  array<int, string>|null  $types  alert types the role sees (null = all)
     */
    private function alertsPage(Request $request, string $view, ?array $types): View
    {
        app(AlertSyncService::class)->sync();
        $search = $this->validatedSearch($request);
        $filters = $this->alertFilters($request, $types);

        return view($view, [
            'search' => $search,
            'alerts' => $this->alertRows($search, $types, (int) $request->user()->id, (string) $request->user()->role, $filters),
            'filters' => $filters,
            'typeOptions' => collect($types ?? self::ALERT_TYPES)->mapWithKeys(fn (string $type): array => [$type => $this->label($type)])->all(),
        ]);
    }

    /**
     * Status (read/unread), severity and type filters for an alerts page.
     *
     * @param  array<int, string>|null  $types
     * @return array{read: ?string, severity: ?string, type: ?string}
     */
    private function alertFilters(Request $request, ?array $types): array
    {
        $data = $request->validate([
            'read' => ['nullable', Rule::in(['read', 'unread', 'resolved'])],
            'severity' => ['nullable', Rule::in(['info', 'warning', 'critical'])],
            'type' => ['nullable', Rule::in($types ?? self::ALERT_TYPES)],
        ]);

        return [
            'read' => $data['read'] ?? null,
            'severity' => $data['severity'] ?? null,
            'type' => $data['type'] ?? null,
        ];
    }

    private function validatedSearch(Request $request): ?string
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        return $search === '' ? null : $search;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function search(Builder $query, string $term, array $columns): Builder
    {
        return $query->where(function (Builder $query) use ($term, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? date('n/j/Y h:i A', strtotime((string) $date)) : 'N/A';
    }
}
