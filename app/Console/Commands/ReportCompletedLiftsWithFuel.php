<?php

namespace App\Console\Commands;

use App\Services\LiftAllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Part 19 (November 3, 2026): read-only report of lifts marked Completed while fuel is still on the truck (lifts
 * completed before the "truck must be empty" rule). It changes nothing; each line ends with the proposed fix.
 */
class ReportCompletedLiftsWithFuel extends Command
{
    protected $signature = 'lifts:completed-with-fuel';

    protected $description = 'List Completed lifts that still have undelivered or unallocated fuel on the truck (read-only)';

    public function handle(LiftAllocationService $liftAllocations): int
    {
        $hauls = DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->whereNull('hauls.deleted_at')
            ->where('hauls.status', 'completed')
            ->orderBy('hauls.id')
            ->get(['hauls.id', 'hauls.haul_code', 'hauls.quantity_liters', 'hauls.status_updated_at', 'purchases.purchase_code', 'trucks.truck_code']);

        $load = $liftAllocations->loadSummaryByHaul($hauls->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $rows = $hauls
            ->map(fn (object $haul): array => [
                $haul->haul_code,
                $haul->purchase_code,
                $haul->truck_code ?: '—',
                number_format((float) $haul->quantity_liters, 2),
                number_format((float) ($load[(int) $haul->id]['delivered'] ?? 0), 2),
                number_format((float) ($load[(int) $haul->id]['remaining'] ?? 0), 2),
                (string) ($haul->status_updated_at ?? '—'),
            ])
            ->filter(fn (array $row): bool => (float) str_replace(',', '', $row[5]) > 0.005)
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No Completed lift has fuel left on its truck.');

            return self::SUCCESS;
        }

        $this->table(['Lift', 'Purchase', 'Truck', 'Lifted (L)', 'Delivered (L)', 'On Truck (L)', 'Completed At'], $rows->all());
        $this->warn($rows->count().' Completed lift(s) still have fuel on the truck. Nothing was changed.');
        $this->line('Proposed reconciliation (needs approval, run on a QA copy first): for each lift, record the fuel that is');
        $this->line('physically still on the truck as delivered to its customer order or unloaded to a garage tank, or, if it is');
        $this->line('really gone, record the loss as an adjustment. Lifts stay Completed; no history is deleted.');

        return self::SUCCESS;
    }
}
