<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CustomerPaymentStatus
{
    /** Apply the same financial rules as the customer badges, before counting/paginating. */
    public static function filter(Builder $query, string $status): void
    {
        $payments = ActivePayments::query()->selectRaw('sale_id, SUM(amount) as paid')->groupBy('sale_id');
        $items = DB::table('sale_items')->selectRaw('sale_id, SUM(line_total) as total')->groupBy('sale_id');
        $sales = DB::table('sales')->leftJoinSub($items, 'cs_items', 'cs_items.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'cs_paid', 'cs_paid.sale_id', '=', 'sales.id')
            ->whereColumn('sales.customer_id', 'customers.id')->whereNull('sales.deleted_at');
        $pending = clone $sales;
        $pending->where(function (Builder $q): void {
            $q->where(function (Builder $q): void {
                $q->where('sales.status', '!=', 'cancelled')->where(function (Builder $q): void {
                    $q->whereRaw('COALESCE(cs_items.total, 0) > COALESCE(cs_paid.paid, 0)')
                        ->orWhereExists(fn (Builder $r) => $r->selectRaw('1')->from('receivables')->whereColumn('receivables.sale_id', 'sales.id')->where('receivables.status', 'overdue'));
                });
            })->orWhere(function (Builder $q): void {
                $refund = Schema::hasTable('sale_refunds') ? '(SELECT COALESCE(SUM(amount), 0) FROM sale_refunds WHERE sale_id = sales.id)' : '0';
                $q->where('sales.status', 'cancelled')->whereRaw('COALESCE(cs_paid.paid, 0) > '.$refund);
            });
        });
        $query->where(function (Builder $q) use ($sales, $pending, $status): void {
            if ($status === 'pending') {
                $q->whereExists($pending)->orWhere(fn (Builder $q) => $q->whereNotExists($sales)->where('customers.payment_status', '!=', 'clear'));
            } else {
                $q->whereNotExists($pending)->where(fn (Builder $q) => $q->whereExists($sales)->orWhere('customers.payment_status', 'clear'));
            }
        });
    }
}
