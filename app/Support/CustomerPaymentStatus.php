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

        $open = (clone $sales)->where('sales.status', '!=', 'cancelled')
            ->whereRaw('ROUND(COALESCE(cs_items.total, 0), 2) > ROUND(COALESCE(cs_paid.paid, 0), 2)');
        $openWithPayment = (clone $open)->whereRaw('ROUND(COALESCE(cs_paid.paid, 0), 2) > 0');
        $refund = Schema::hasTable('sale_refunds') ? '(SELECT COALESCE(SUM(amount), 0) FROM sale_refunds WHERE sale_id = sales.id)' : '0';
        $unrefunded = (clone $sales)->where('sales.status', 'cancelled')->whereRaw('COALESCE(cs_paid.paid, 0) > '.$refund);

        $query->where(function (Builder $q) use ($sales, $open, $openWithPayment, $unrefunded, $status): void {
            if ($status === 'paid') {
                $q->where(function (Builder $q) use ($sales, $open, $unrefunded): void {
                    $q->whereExists($sales)->whereNotExists($open)->whereNotExists($unrefunded);
                })->orWhere(fn (Builder $q) => $q->whereNotExists($sales)->whereIn('customers.payment_status', ['paid', 'clear']));
            } elseif ($status === 'partially_paid') {
                $q->whereExists($openWithPayment)
                    ->orWhere(fn (Builder $q) => $q->whereNotExists($sales)->whereIn('customers.payment_status', ['partially_paid', 'partial']));
            } else {
                $q->where(function (Builder $q) use ($open, $openWithPayment, $unrefunded): void {
                    $q->whereExists($open)->whereNotExists($openWithPayment);
                })->orWhere(function (Builder $q) use ($open, $unrefunded): void {
                    $q->whereNotExists($open)->whereExists($unrefunded);
                })->orWhere(fn (Builder $q) => $q->whereNotExists($sales)->whereIn('customers.payment_status', ['unpaid', 'pending']));
            }
        });
    }
}
