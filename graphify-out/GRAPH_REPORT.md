# Graph Report - cjp_inventory_sales  (2026-09-18)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1502 nodes · 3538 edges · 152 communities (99 shown, 53 thin omitted)
- Extraction: 99% EXTRACTED · 1% INFERRED · 0% AMBIGUOUS · INFERRED: 26 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `7aeb007f`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- AIDataPreparationService
- DashboardSummaryService
- SalesOfficerCustomerController
- DispatchDeliveryController
- InventoryOfficerPurchaseController
- AdminMonitoringController
- RoleBasedAccessControlTest
- OperationalDataRepairService
- User
- Illuminate\Support\Facades\DB
- AdminSalesReportController
- AdminUserManagementController
- SalesOfficerSalesManagementTest
- PurchaseService
- devDependencies
- InventoryLedgerService
- Illuminate\Foundation\Testing\RefreshDatabase
- PurchaseReceiptUploadTest
- DashboardSummaryCardsTest
- WorkflowSmokeTestService
- InventoryOfficerStockInTest
- InventoryOfficerStockOutTest
- Carbon\CarbonImmutable
- Carbon\Carbon
- Phase3OperationalAcceptanceTest.php
- RoleAccessTestingTest
- SalesOfficerPaymentRecordingTest
- web.php
- Illuminate\Http\Request
- DatabaseIntegrityTest
- IntegrationTestingTest
- User.php
- AIFeatureTestingTest
- AIServiceConfigurationTest
- CompleteWorkflowIntegrationTest
- FormValidationTest
- FunctionalTestingTest
- Controller
- scripts
- AIDataPreparationServiceTest
- AnalyticsTestingTest
- BusinessInsightsTest
- Phase2GuardrailsAndIdempotencyTest
- Illuminate\Http\RedirectResponse
- DriverDeliveryController
- TestCase
- DispatchLiftingStatusManagementTest
- InventoryVarianceExplanationsTest
- SalesOfficerCustomerManagementTest
- composer.json
- HaulTruckAssignmentTest
- InventoryOfficerPurchaseManagementTest
- Phase4SecurityHardeningTest
- RevenueInsightsTest
- SalesOfficerReceivablesTrackingTest
- DispatchLiftingStatusController
- app.js
- AdminSalesReportsTest
- InventoryLedgerTest
- GarageTankService
- FrontendBugFixesTest
- Phase5PerformanceTest
- SalesTrendSummariesTest
- AppServiceProvider.php
- require-dev
- setup
- config
- Illuminate\Database\Migrations\Migration
- Illuminate\Database\Schema\Blueprint
- FuelCatalogRegressionTest
- DriverLiftingStatusController
- UserFactory.php
- AdminMonitoringTest
- psr-4
- logging.php
- Not Ready Verdict
- require
- post-create-project-cmd
- 2026_09_06_000001_add_withdrawal_receipts_and_garage_tanks.php
- ExampleTest
- extra
- keywords
- CJP Brand Logo
- admin.partials.lift-table
- Current Stock-Out Fulfillment Model
- Robots Policy
- Laravel Framework

## God Nodes (most connected - your core abstractions)
1. `DashboardSummaryService` - 76 edges
2. `SalesOfficerCustomerController` - 71 edges
3. `User` - 67 edges
4. `InventoryOfficerPurchaseController` - 57 edges
5. `AIDataPreparationService` - 43 edges
6. `AdminMonitoringController` - 42 edges
7. `RoleBasedAccessControlTest` - 38 edges
8. `PurchaseService` - 31 edges
9. `AdminSalesReportController` - 27 edges
10. `SalesOfficerSalesManagementTest` - 27 edges

## Surprising Connections (you probably didn't know these)
- `AIDataPreparationService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AIDataPreparationService.php → app/Services/DashboardSummaryService.php
- `AdminDashboardService` --references--> `DashboardSummaryService`  [EXTRACTED]
  app/Services/AdminDashboardService.php → app/Services/DashboardSummaryService.php
- `AdminMonitoringController` --references--> `PurchaseService`  [EXTRACTED]
  app/Http/Controllers/AdminMonitoringController.php → app/Services/PurchaseService.php
- `InventoryOfficerPurchaseController` --references--> `PurchaseService`  [EXTRACTED]
  app/Http/Controllers/InventoryOfficerPurchaseController.php → app/Services/PurchaseService.php
- `PurchaseService` --references--> `IdempotencyService`  [EXTRACTED]
  app/Services/PurchaseService.php → app/Services/IdempotencyService.php

## Import Cycles
- None detected.

## Communities (152 total, 53 thin omitted)

### Community 0 - "AIDataPreparationService"
Cohesion: 0.06
Nodes (6): AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 1 - "DashboardSummaryService"
Cohesion: 0.08
Nodes (11): EnsureUserHasRole, SecurityHeaders, DashboardSummaryService, CarbonImmutable, Closure, Illuminate\Database\Query\Builder, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions (+3 more)

### Community 2 - "SalesOfficerCustomerController"
Cohesion: 0.06
Nodes (4): Closure, SalesOfficerCustomerController, AdminDashboardService, Illuminate\Support\Collection

### Community 3 - "DispatchDeliveryController"
Cohesion: 0.08
Nodes (6): DispatchDeliveryController, CarbonImmutable, IdempotencyService, Closure, Closure, StockInService

### Community 4 - "InventoryOfficerPurchaseController"
Cohesion: 0.08
Nodes (3): InventoryOfficerPurchaseController, Closure, Illuminate\Pagination\LengthAwarePaginator

### Community 7 - "OperationalDataRepairService"
Cohesion: 0.11
Nodes (8): OperationalDataRepairService, GarageTankService, SaleConfirmationService, Closure, StockOutReleaseService, DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 8 - "User"
Cohesion: 0.12
Nodes (5): User, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Notifications\Notifiable, AdminUserManagementTest

### Community 9 - "Illuminate\Support\Facades\DB"
Cohesion: 0.12
Nodes (8): Illuminate\Database\QueryException, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Hash, Illuminate\Support\Str, Illuminate\Validation\ValidationException, Pdo\Mysql, RuntimeException, Throwable

### Community 14 - "devDependencies"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 16 - "Illuminate\Foundation\Testing\RefreshDatabase"
Cohesion: 0.20
Nodes (4): Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Support\Facades\Schema, Illuminate\Support\Facades\Storage, MasterDataAndInventoryAccessTest

### Community 17 - "PurchaseReceiptUploadTest"
Cohesion: 0.20
Nodes (5): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptStatusTest, UploadedFile, PurchaseReceiptUploadTest

### Community 22 - "Carbon\CarbonImmutable"
Cohesion: 0.17
Nodes (8): AdminDashboardController, ApprovedFuelType, Carbon\CarbonImmutable, Illuminate\Auth\Access\AuthorizationException, Illuminate\Http\Response, Illuminate\Support\Facades\Validator, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Exists

### Community 23 - "Carbon\Carbon"
Cohesion: 0.16
Nodes (7): Carbon\Carbon, Illuminate\Http\Client\ConnectionException, Illuminate\Http\Client\Response, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\Http, Illuminate\Support\Facades\Log

### Community 24 - "Phase3OperationalAcceptanceTest.php"
Cohesion: 0.12
Nodes (5): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, Phase3OperationalAcceptanceTest, TurnoverReadinessTest

### Community 27 - "web.php"
Cohesion: 0.16
Nodes (4): AdminBusinessInsightController, AdminRevenueInsightController, Controller, InventoryOfficerLedgerController

### Community 31 - "User.php"
Cohesion: 0.23
Nodes (9): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, Illuminate\Support\Carbon, Illuminate\Support\Facades\Mail, Illuminate\Validation\Rules\Password, PHPUnit\Framework\Attributes\DataProvider (+1 more)

### Community 37 - "Controller"
Cohesion: 0.22
Nodes (4): AdminInventoryVarianceExplanationController, HaulTruckAssignmentController, CarbonImmutable, Controller

### Community 38 - "scripts"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 45 - "TestCase"
Cohesion: 0.18
Nodes (4): Illuminate\Foundation\Testing\TestCase, DeliveryWorkflowRemovalTest, ExampleTest, TestCase

### Community 50 - "composer.json"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 57 - "app.js"
Cohesion: 0.27
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 64 - "AppServiceProvider.php"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 65 - "require-dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 66 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 67 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 72 - "UserFactory.php"
Cohesion: 0.47
Nodes (3): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, static

### Community 74 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 75 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 76 - "Not Ready Verdict"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 77 - "require"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 78 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 81 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 82 - "keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

### Community 95 - "CJP Brand Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **66 isolated node(s):** `private`, `$schema`, `build`, `dev`, `type` (+61 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **53 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `DashboardSummaryService` connect `DashboardSummaryService` to `AIDataPreparationService`, `SalesOfficerCustomerController`, `InventoryOfficerPurchaseController`, `Controller`, `Illuminate\Support\Facades\DB`, `Illuminate\Foundation\Testing\RefreshDatabase`, `Carbon\CarbonImmutable`, `Carbon\Carbon`?**
  _High betweenness centrality (0.124) - this node is a cross-community bridge._
- **Why does `User` connect `User` to `AIDataPreparationService`, `Controller`, `AIDataPreparationServiceTest`, `UserFactory.php`, `AdminMonitoringTest`, `Illuminate\Support\Facades\DB`, `BusinessInsightsTest`, `TestCase`, `SalesTrendSummariesTest`, `HaulTruckAssignmentTest`, `Carbon\CarbonImmutable`, `Carbon\Carbon`, `RevenueInsightsTest`, `FrontendBugFixesTest`, `User.php`?**
  _High betweenness centrality (0.061) - this node is a cross-community bridge._
- **Why does `InventoryOfficerPurchaseController` connect `InventoryOfficerPurchaseController` to `DispatchDeliveryController`, `Controller`, `Illuminate\Support\Facades\DB`, `PurchaseService`, `web.php`, `Illuminate\Http\Request`?**
  _High betweenness centrality (0.050) - this node is a cross-community bridge._
- **What connects `private`, `$schema`, `build` to the rest of the system?**
  _66 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `AIDataPreparationService` be split into smaller, more focused modules?**
  _Cohesion score 0.0554954954954955 - nodes in this community are weakly interconnected._
- **Should `DashboardSummaryService` be split into smaller, more focused modules?**
  _Cohesion score 0.07511737089201878 - nodes in this community are weakly interconnected._
- **Should `SalesOfficerCustomerController` be split into smaller, more focused modules?**
  _Cohesion score 0.06377204884667571 - nodes in this community are weakly interconnected._