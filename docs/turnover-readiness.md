# Turnover Readiness Checklist

Use this checklist before declaring CJP Southern Star OPC ready for turnover. Keep the checks read-only unless the step explicitly asks for staging setup.

## Environment

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL` points to the real deployed URL.
- No API keys, SMTP passwords, or database passwords are stored in committed files.
- AI provider keys have been rotated after any local exposure.
- Password reset email succeeds against the production mail provider.

## Master Data

- Active fuel types are limited to F1, Unleaded, Premium, and Diesel.
- Each active fuel type has exactly two active garage tanks.
- Real depots, trucks, drivers, driver profiles, and customers exist.
- Placeholder values such as `N/A` are removed from turnover data where real business data is required.

## End-to-End Workflow

- Purchase creation works with server-calculated totals.
- Dispatch can assign an eligible driver and hauling truck.
- Driver can progress only assigned lifts.
- Withdrawal receipt upload is private and viewable only through guarded receipt routes.
- Stock-in creates exactly one inventory movement and cannot exceed the allocation.
- Sales create server-calculated sale items and cannot use browser-submitted totals.
- Stock-out cannot exceed sale quantity or garage availability.
- Payments cannot overpay a sale or duplicate the same physical payment.
- Receivable status matches payment state.

## Reports and Analytics

- Dashboard stock totals match inventory movements.
- Sales totals match sale items, not browser-provided values.
- Payments collected match payment records.
- Outstanding receivables match sale totals minus payments.
- Cancelled records are excluded from active operational totals.
- AI insight failures show safe fallback messages.

## Final Gate

The system is ready only after:

- `vendor/bin/phpunit --do-not-cache-result` passes.
- `php artisan cjp:workflow-smoke-test` passes with rollback enabled.
- A staging smoke test completes purchase to payment end-to-end.
- `php artisan cjp:readiness-audit` has no hard failures; any warnings must be explicitly accepted or resolved before production turnover.
- No critical or high security, financial, inventory, or workflow issues remain.
