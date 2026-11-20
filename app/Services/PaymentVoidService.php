<?php

namespace App\Services;

use App\Support\ActivePayments;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Voids a recorded payment without deleting or changing it: the row keeps its amount, date and code, gains who
 * voided it, when and why, and stops counting toward every total. The sale's status and receivable are then
 * reconciled from the remaining payments, and the change is written to the sale's change log.
 */
class PaymentVoidService
{
    public function __construct(private readonly SaleConfirmationService $confirmation) {}

    /**
     * @return array{sale_status: string, receivable_status: string}
     */
    public function void(int $paymentId, int $userId, string $reason, ?int $expectedSaleId = null): array
    {
        if (! ActivePayments::voidSupported()) {
            throw ValidationException::withMessages(['void_reason' => 'Payment voiding is not installed yet. Apply the pending database migration first.']);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => 'Enter why this payment is being voided.']);
        }

        return DB::transaction(function () use ($paymentId, $userId, $reason, $expectedSaleId): array {
            $payment = DB::table('payments')->where('id', $paymentId)->lockForUpdate()->first();
            if (! $payment || ($expectedSaleId !== null && (int) $payment->sale_id !== $expectedSaleId)) {
                abort(404);
            }
            if ($payment->voided_at !== null) {
                throw ValidationException::withMessages(['void_reason' => 'Payment '.$payment->payment_code.' is already voided.']);
            }

            $sale = DB::table('sales')->where('id', $payment->sale_id)->whereNull('deleted_at')->lockForUpdate()->first(['id', 'sale_code', 'status']);
            if (! $sale) {
                abort(404);
            }
            // Refunds on a cancelled sale come out of its payments; voiding must not leave more refunded than paid.
            if ($sale->status === 'cancelled' && DB::getSchemaBuilder()->hasTable('sale_refunds')) {
                $remaining = (float) ActivePayments::query()->where('sale_id', $sale->id)->where('id', '!=', $payment->id)->sum('amount');
                $refunded = (float) DB::table('sale_refunds')->where('sale_id', $sale->id)->sum('amount');
                if (round($refunded, 2) > round($remaining, 2)) {
                    throw ValidationException::withMessages(['void_reason' => 'This payment has been refunded, so it cannot be voided.']);
                }
            }

            $now = now();
            DB::table('payments')->where('id', $payment->id)->update([
                'voided_at' => $now, 'voided_by' => $userId, 'void_reason' => mb_substr($reason, 0, 500), 'updated_at' => $now,
            ]);
            if ($payment->payment_schedule_id) {
                $this->refreshSchedule((int) $payment->payment_schedule_id);
            }
            $statuses = $this->confirmation->reconcile((int) $sale->id);

            DB::table('sale_change_logs')->insert([
                'sale_id' => $sale->id, 'sale_code' => $sale->sale_code, 'field' => 'payments.'.$payment->payment_code.'.voided',
                'old_value' => number_format((float) $payment->amount, 2, '.', '').' recorded',
                'new_value' => 'voided: '.mb_substr($reason, 0, 400), 'changed_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);

            return $statuses;
        });
    }

    private function refreshSchedule(int $scheduleId): void
    {
        $schedule = DB::table('payment_schedules')->where('id', $scheduleId)->lockForUpdate()->first(['id', 'due_date', 'amount_due']);
        if (! $schedule) {
            return;
        }
        $paid = round((float) ActivePayments::query()->where('payment_schedule_id', $scheduleId)->sum('amount'), 2);
        $status = $paid >= round((float) $schedule->amount_due, 2) ? 'paid'
            : ($paid > 0 ? 'partially_paid' : 'unpaid');
        DB::table('payment_schedules')->where('id', $scheduleId)->update(['status' => $status, 'updated_at' => now()]);
    }
}
