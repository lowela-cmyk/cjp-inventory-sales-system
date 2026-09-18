# Graph Report - cjp_inventory_sales  (2026-09-18)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1530 nodes · 3608 edges · 150 communities (101 shown, 49 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 35 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `f3da18d6`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- SalesOfficerCustomerController
- User
- AIDataPreparationService
- DashboardSummaryService
- PurchaseService
- InventoryOfficerPurchaseController
- OperationalDataRepairService
- AdminMonitoringController
- RoleBasedAccessControlTest
- DispatchDeliveryController
- Illuminate\Http\Request
- Illuminate\Foundation\Testing\RefreshDatabase
- AdminSalesReportController
- Illuminate\Database\Query\Builder
- .rule
- SalesOfficerSalesManagementTest
- Illuminate\Http\RedirectResponse
- devDependencies
- PurchaseReceiptUploadTest
- DashboardSummaryCardsTest
- Illuminate\Support\Str
- InventoryLedgerService
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- InventoryOfficerStockOutTest
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- AIServiceConfigurationTest.php
- DatabaseIntegrityTest
- IntegrationTestingTest
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- Phase2GuardrailsAndIdempotencyTest
- TestCase
- DispatchLiftingStatusManagementTest
- scripts
- InventoryVarianceExplanationsTest
- SalesOfficerCustomerManagementTest
- Carbon\Carbon
- composer.json
- TurnoverReadinessTest
- app.js
- InventoryOfficerPurchaseManagementTest
- Phase4SecurityHardeningTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- DispatchLiftingStatusController
- RoleBasedAccessControlTest.php
- AdminSalesReportsTest
- InventoryLedgerTest
- GarageTankService
- DriverLiftingStatusController
- AppServiceProvider.php
- require-dev
- setup
- Phase5PerformanceTest
- .update
- config
- Illuminate\Database\Schema\Blueprint
- Illuminate\Database\Migrations\Migration
- ConnectedLiftingWorkflowTest
- FuelCatalogRegressionTest
- Phase3OperationalAcceptanceTest
- psr-4
- logging.php
- Not Ready Verdict
- MasterDataAndInventoryAccessTest
- require
- 2026_09_06_000001_add_withdrawal_receipts_and_garage_tanks.php
- ExampleTest
- extra
- post-create-project-cmd
- autoload-dev
- CJP Brand Logo
- admin.partials.lift-table
- Current Stock-Out Fulfillment Model
- CarbonImmutable
- Closure
- Robots Policy
- Laravel Framework

## God Nodes (most connected - your core abstractions)
1. `DashboardSummaryService` - 74 edges
2. `SalesOfficerCustomerController` - 70 edges
3. `User` - 69 edges
4. `InventoryOfficerPurchaseController` - 57 edges
5. `AdminMonitoringController` - 44 edges
6. `AIDataPreparationService` - 43 edges
7. `RoleBasedAccessControlTest` - 38 edges
8. `PurchaseService` - 33 edges
9. `SalesOfficerSalesManagementTest` - 27 edges
10. `AdminSalesReportController` - 26 edges

## Surprising Connections (you probably didn't know these)
- `SalesOfficerCustomerController` --references--> `IdempotencyService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/IdempotencyService.php
- `SalesOfficerCustomerController` --references--> `SaleConfirmationService`  [EXTRACTED]
  app/Http/Controllers/SalesOfficerCustomerController.php → app/Services/SaleConfirmationService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AdminMonitoringTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/AdminMonitoringTest.php → tests/TestCase.php
- `AdminUserManagementTest` --inherits--> `TestCase`  [EXTRACTED]
  tests/Feature/AdminUserManagementTest.php → tests/TestCase.php

## Import Cycles
- None detected.

## Communities (150 total, 49 thin omitted)

### Community 0 - "SalesOfficerCustomerController"
Cohesion: 0.06
Nodes (4): Closure, SalesOfficerCustomerController, AdminDashboardService, Illuminate\Support\Collection

### Community 1 - "User"
Cohesion: 0.05
Nodes (10): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminMonitoringTest, AdminUserManagementTest, BusinessInsightsTest, FrontendBugFixesTest (+2 more)

### Community 2 - "AIDataPreparationService"
Cohesion: 0.06
Nodes (6): AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 3 - "DashboardSummaryService"
Cohesion: 0.10
Nodes (3): DashboardSummaryService, CarbonImmutable, Closure

### Community 4 - "PurchaseService"
Cohesion: 0.07
Nodes (5): PurchaseService, PurchaseWorkflowService, Closure, StockInService, WorkflowAlertService

### Community 5 - "InventoryOfficerPurchaseController"
Cohesion: 0.08
Nodes (3): InventoryOfficerPurchaseController, Closure, Illuminate\Pagination\LengthAwarePaginator

### Community 6 - "OperationalDataRepairService"
Cohesion: 0.09
Nodes (10): IdempotencyService, Closure, OperationalDataRepairService, GarageTankService, SaleConfirmationService, Closure, StockOutReleaseService, DatabaseSeeder (+2 more)

### Community 9 - "DispatchDeliveryController"
Cohesion: 0.12
Nodes (3): DispatchDeliveryController, DriverDeliveryController, CarbonImmutable

### Community 10 - "Illuminate\Http\Request"
Cohesion: 0.10
Nodes (13): AdminBusinessInsightController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller, InventoryOfficerLedgerController, EnsureUserHasRole, SecurityHeaders, Illuminate\Foundation\Application (+5 more)

### Community 11 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.24
Nodes (4): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Schema, Illuminate\Support\Facades\Storage

### Community 12 - "AdminSalesReportController"
Cohesion: 0.18
Nodes (3): AdminSalesReportController, Illuminate\Contracts\Database\Query\Builder, Illuminate\Http\Response

### Community 13 - "Illuminate\Database\Query\Builder"
Cohesion: 0.15
Nodes (10): AdminDashboardController, AdminInventoryVarianceExplanationController, ApprovedFuelType, Carbon\CarbonImmutable, Controller, Illuminate\Auth\Access\AuthorizationException, Illuminate\Database\Query\Builder, Illuminate\Support\Facades\Validator (+2 more)

### Community 16 - "Illuminate\Http\RedirectResponse"
Cohesion: 0.18
Nodes (5): AuthController, Illuminate\Http\RedirectResponse, Illuminate\Support\Carbon, Illuminate\Validation\Rules\Password, Illuminate\View\View

### Community 17 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 18 - "PurchaseReceiptUploadTest"
Cohesion: 0.20
Nodes (5): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptStatusTest, UploadedFile, PurchaseReceiptUploadTest

### Community 20 - "Illuminate\Support\Str"
Cohesion: 0.13
Nodes (4): Illuminate\Database\QueryException, Illuminate\Support\Str, Illuminate\Validation\ValidationException, Pdo\Mysql

### Community 27 - "AIServiceConfigurationTest.php"
Cohesion: 0.14
Nodes (9): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Log, RuntimeException, static (+1 more)

### Community 38 - "TestCase"
Cohesion: 0.18
Nodes (4): Illuminate\Foundation\Testing\TestCase, DeliveryWorkflowRemovalTest, ExampleTest, TestCase

### Community 40 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 43 - "Carbon\Carbon"
Cohesion: 0.31
Nodes (4): Carbon\Carbon, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\Http

### Community 44 - "composer.json"
Cohesion: 0.18
Nodes (10): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type (+2 more)

### Community 45 - "TurnoverReadinessTest"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, TurnoverReadinessTest

### Community 46 - "app.js"
Cohesion: 0.24
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 52 - "RoleBasedAccessControlTest.php"
Cohesion: 0.27
Nodes (7): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, Illuminate\Support\Facades\Mail, PHPUnit\Framework\Attributes\DataProvider, Symfony\Component\Mailer\Exception\TransportException

### Community 57 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 58 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 59 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 62 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 68 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 69 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 70 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 72 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 75 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 76 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 77 - "autoload-dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 91 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **66 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+61 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **49 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Work-memory lessons

**Preferred sources** — corroborated by past sessions; start here.
- `InventoryLedgerService` (3× useful, score=2.999043094)
- `InventoryOfficerPurchaseController` (3× useful, score=2.927148084)
- `modal.blade.php` (2× useful, score=1.999707168)
- `DispatchDeliveryController` (2× useful, score=1.999264433)
- `WorkflowSmokeTestService` (2× useful, score=1.927703889)

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `ConnectedLiftingWorkflowTest`, `AIDataPreparationService`, `AIDataPreparationServiceTest`, `TestCase`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Carbon\Carbon`, `Illuminate\Database\Query\Builder`, `RevenueInsightsTest`, `InventoryLedgerTest`, `AIServiceConfigurationTest.php`?**
  _High betweenness centrality (0.072) - this node is a cross-community bridge._
- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `SalesOfficerCustomerController`, `AIDataPreparationService`, `TestCase`, `Carbon\Carbon`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Illuminate\Database\Query\Builder`, `Illuminate\Http\RedirectResponse`?**
  _High betweenness centrality (0.067) - this node is a cross-community bridge._
- **Why does `InventoryLedgerService` connect `InventoryLedgerService` to `Illuminate\Http\RedirectResponse`, `Illuminate\Http\Request`, `Illuminate\Foundation\Testing\RefreshDatabase`?**
  _High betweenness centrality (0.046) - this node is a cross-community bridge._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _66 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `SalesOfficerCustomerController` be split into smaller, more focused modules?**
  _Cohesion score 0.0558641975308642 - nodes in this community are weakly interconnected._
- **Should `User` be split into smaller, more focused modules?**
  _Cohesion score 0.05365686944634313 - nodes in this community are weakly interconnected._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._