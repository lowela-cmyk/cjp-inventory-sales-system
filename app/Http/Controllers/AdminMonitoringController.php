<?php

namespace App\Http\Controllers;

use App\Services\InventoryLedgerService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminMonitoringController extends Controller
{
    public function ledger(Request $request, InventoryLedgerService $ledgerService): View
    {
        $search = $this->validatedSearch($request);
        $rows = $ledgerService->rows($search);

        return view('admin.ledger', [
            'search' => $search,
            'ledger' => $rows['ledger'],
            'transactions' => $rows['transactions'],
            'latestBalances' => $rows['latestBalances'],
        ]);
    }

    public function alerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, null, (int) $request->user()->id, (string) $request->user()->role);

        return view('admin.alerts', compact('search', 'alerts'));
    }

    public function inventoryOfficerAlerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, ['inventory', 'purchase', 'haul', 'discrepancy'], (int) $request->user()->id, (string) $request->user()->role);

        return view('inventory-officer.alerts', compact('search', 'alerts'));
    }

    public function salesOfficerAlerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, ['payment', 'receivable'], (int) $request->user()->id, (string) $request->user()->role);

        return view('sales-officer.alerts', compact('search', 'alerts'));
    }

    public function dispatchAlerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, ['haul', 'delivery'], (int) $request->user()->id, (string) $request->user()->role);

        return view('dispatch.alerts', compact('search', 'alerts'));
    }


    public function markAlertRead(Request $request, int $alert): RedirectResponse
    {
        $allowedTypes = match ($request->user()->role) {
            'inventory_officer' => ['inventory', 'purchase', 'haul', 'discrepancy'],
            'dispatch_officer' => ['haul', 'delivery'],
            'sales_officer' => ['payment', 'receivable'],
            'admin' => null,
            default => [],
        };
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
     */
    private function alertRows(?string $search, ?array $types = null, ?int $userId = null, ?string $role = null)
    {
        return DB::table('alerts')
            ->leftJoin('alert_reads', function ($join) use ($userId): void {
                $join->on('alert_reads.alert_id', '=', 'alerts.id')
                    ->where('alert_reads.user_id', '=', $userId ?? 0);
            })
            ->when($types, fn (Builder $query, array $types): Builder => $query->whereIn('type', $types))
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
