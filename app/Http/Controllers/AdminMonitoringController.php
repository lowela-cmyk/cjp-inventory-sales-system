<?php

namespace App\Http\Controllers;

use App\Services\AlertBellService;
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
        // The same role → alert types map drives the pages, Mark Read and the header bell.
        // null = every type (Admin); a role without an Alerts page may mark nothing.
        $role = (string) $request->user()->role;
        $allowedTypes = array_key_exists($role, AlertBellService::ROLE_ALERTS) ? AlertBellService::ROLE_ALERTS[$role]['types'] : [];
        $query = DB::table('alerts')->where('id', $alert);
        if (is_array($allowedTypes)) {
            $query->whereIn('type', $allowedTypes);
        }
        abort_unless($query->exists(), 404);

        DB::table('alert_reads')->upsert([[
            'alert_id' => $alert,
            'user_id' => $request->user()->id,
            'read_at' => now(),
        ]], ['alert_id', 'user_id'], ['read_at']);

        return back()->with('status', 'Alert marked as read.');
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
                'title' => $row->alert_code.' - '.$row->title,
                'message' => $row->message,
                'time' => $this->formatDateTime($row->created_at),
                'meta' => trim($this->label($row->type).' / '.($row->reference_type ?: '').($row->reference_id ? ' #'.$row->reference_id : '')),
                'status' => $this->label($row->status),
                'read' => $row->read_at !== null,
                'read_state' => $row->read_at ? 'Read' : 'Unread',
                'action_url' => $this->alertActionUrl($row, $role),
            ]);
    }

    private function alertActionUrl(object $alert, ?string $role): ?string
    {
        return match ($role) {
            'admin' => $alert->reference_type === 'haul' ? route('admin.fuel-lifting') : route('admin.inventory'),
            'inventory_officer' => route('inventory-officer.inventory'),
            'dispatch_officer' => route('dispatch.fuel-lifting'),
            'sales_officer' => route('sales-officer.sales'),
            default => $alert->action_url,
        };
    }

    /**
     * @param  array<int, string>|null  $types  alert types the role sees (null = all)
     */
    private function alertsPage(Request $request, string $view, ?array $types): View
    {
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
            'read' => ['nullable', Rule::in(['read', 'unread'])],
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
