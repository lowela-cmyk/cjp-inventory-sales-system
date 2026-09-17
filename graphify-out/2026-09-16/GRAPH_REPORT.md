# Graph Report - cjp_inventory_sales  (2026-09-16)

## Corpus Check
- 163 files · ~120,265 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1352 nodes · 3288 edges · 147 communities (93 shown, 54 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 4 edges (avg confidence: 0.85)
- Token cost: 5,638 input · 7,412 output

## Community Hubs (Navigation)
- AI Data Preparation Service
- Dashboard Summary Service
- Inventory Officer Purchase Controller
- Admin Monitoring Controller
- Sales Officer Customer Controller
- Admin User Management Controller
- Based Access Control Test
- Admin Business Insight Controller 2
- Admin User Management Test
- Test Case
- Admin Sales Report Controller
- Admin Dashboard Service
- Dispatch Delivery Controller
- Package Module
- Admin Business Insight Controller
- Purchase Receipt Upload Test
- Dashboard Summary Cards Test
- Inventory Ledger Service
- Workflow Smoke Test Service
- Officer Stock In Test
- Officer Stock Out Test
- Role Access Testing Test
- Officer Payment Recording Test
- Officer Sales Management Test
- Database Integrity Test
- Integration Testing Test
- Auth Controller
- AI Feature Testing Test
- AI Service Configuration Test
- Complete Workflow Integration Test
- Form Validation Test
- Functional Testing Test
- Garage Tank Service 2
- Composer Module
- Data Preparation Service Test
- Analytics Testing Test
- Business Insights Test
- Driver Delivery Controller
- Turnover Readiness Test
- Lifting Status Management Test
- Inventory Variance Explanations Test
- Officer Customer Management Test
- Stock Out Release Service
- Composer Module 2
- Haul Truck Assignment Test
- Officer Purchase Management Test
- Revenue Insights Test
- Officer Receivables Tracking Test
- Password Reset Code Mail
- Garage Tank Service
- App Module
- Admin Sales Reports Test
- Inventory Ledger Test
- Dispatch Lifting Status Controller
- User Factory
- Frontend Bug Fixes Test
- Sales Trend Summaries Test
- App Service Provider
- Composer Module 3
- Composer Module 4
- Haul Truck Assignment Controller
- Composer Module 5
- Create Users Table
- Create Hauling Tables
- Driver Lifting Status Controller
- Admin Monitoring Test
- AI Service
- Composer Module 6
- Logging Module
- Database Seeder
- System Audit Report
- And Inventory Access Test
- Sales Officer Customer Controller 3
- Composer Module 7
- Composer Module 8
- Receipts And Garage Tanks
- Example Test
- Composer Module 9
- Composer Module 10
- Cjp Logo
- Example Test 2
- Fuel Lifting Blade 3
- ERD Module
- Robots Module
- Readme Module

## God Nodes (most connected - your core abstractions)
1. `User` - 203 edges
2. `DashboardSummaryService` - 75 edges
3. `TestCase` - 74 edges
4. `SalesOfficerCustomerController` - 68 edges
5. `InventoryOfficerPurchaseController` - 58 edges
6. `AIDataPreparationService` - 44 edges
7. `AdminMonitoringController` - 40 edges
8. `RoleBasedAccessControlTest` - 38 edges
9. `AIService` - 26 edges
10. `AdminSalesReportController` - 25 edges

## Surprising Connections (you probably didn't know these)
- `AdminInventoryVarianceExplanationController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AdminInventoryVarianceExplanationController.php → app/Http/Controllers/Controller.php
- `AdminMonitoringController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AdminMonitoringController.php → app/Http/Controllers/Controller.php
- `AdminSalesReportController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AdminSalesReportController.php → app/Http/Controllers/Controller.php
- `AdminUserManagementController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AdminUserManagementController.php → app/Http/Controllers/Controller.php
- `AuthController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/AuthController.php → app/Http/Controllers/Controller.php

## Import Cycles
- None detected.

## Communities (147 total, 54 thin omitted)

### Community 0 - "AI Data Preparation Service"
Cohesion: 0.05
Nodes (7): AdminInventoryVarianceExplanationController, AIDataPreparationService, AIService, BusinessInsightService, InventoryVarianceExplanationService, RevenueInsightService, SalesTrendSummaryService

### Community 1 - "Dashboard Summary Service"
Cohesion: 0.08
Nodes (9): EnsureUserHasRole, DashboardSummaryService, CarbonImmutable, Closure, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware, Illuminate\Support\Facades\Auth (+1 more)

### Community 5 - "Admin User Management Controller"
Cohesion: 0.18
Nodes (4): AdminUserManagementController, AuthController, Illuminate\Http\RedirectResponse, Illuminate\Http\Request

### Community 7 - "Admin Business Insight Controller 2"
Cohesion: 0.16
Nodes (8): Carbon\CarbonImmutable, Illuminate\Database\Query\Builder, Illuminate\Http\Response, Illuminate\Support\Facades\DB, Illuminate\Support\Facades\Storage, Illuminate\Support\Facades\Validator, Illuminate\Validation\Rule, Illuminate\Validation\Rules\Password

### Community 8 - "Admin User Management Test"
Cohesion: 0.13
Nodes (3): User, Illuminate\Foundation\Auth\User, AdminUserManagementTest

### Community 9 - "Test Case"
Cohesion: 0.19
Nodes (6): Carbon\Carbon, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase, Illuminate\Support\Facades\Config, Illuminate\Support\Facades\Http, TestCase

### Community 13 - "Package Module"
Cohesion: 0.09
Nodes (21): axios, chart.js, concurrently, laravel-vite-plugin, devDependencies, axios, chart.js, concurrently (+13 more)

### Community 14 - "Admin Business Insight Controller"
Cohesion: 0.14
Nodes (6): AdminBusinessInsightController, AdminDashboardController, AdminRevenueInsightController, AdminSalesTrendSummaryController, Controller, InventoryOfficerLedgerController

### Community 15 - "Purchase Receipt Upload Test"
Cohesion: 0.20
Nodes (5): Illuminate\Http\UploadedFile, UploadedFile, PurchaseReceiptStatusTest, UploadedFile, PurchaseReceiptUploadTest

### Community 27 - "Auth Controller"
Cohesion: 0.16
Nodes (9): Illuminate\Auth\Access\AuthorizationException, Illuminate\Support\Carbon, Illuminate\Support\Facades\Hash, Illuminate\Support\Facades\Mail, Illuminate\Validation\ValidationException, PHPUnit\Framework\Attributes\DataProvider, RuntimeException, Symfony\Component\Mailer\Exception\TransportException (+1 more)

### Community 33 - "Garage Tank Service 2"
Cohesion: 0.14
Nodes (3): Illuminate\Database\QueryException, Illuminate\Support\Str, Pdo\Mysql

### Community 34 - "Composer Module"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 39 - "Turnover Readiness Test"
Cohesion: 0.17
Nodes (5): Illuminate\Foundation\Inspiring, Illuminate\Support\Facades\Artisan, Illuminate\Support\Facades\Route, DeliveryWorkflowRemovalTest, TurnoverReadinessTest

### Community 44 - "Composer Module 2"
Cohesion: 0.18
Nodes (10): autoload-dev, psr-4, description, license, minimum-stability, name, prefer-stable, Tests\\ (+2 more)

### Community 49 - "Password Reset Code Mail"
Cohesion: 0.27
Nodes (5): PasswordResetCodeMail, Illuminate\Bus\Queueable, Illuminate\Mail\Mailable, Illuminate\Queue\SerializesModels, self

### Community 51 - "App Module"
Cohesion: 0.27
Nodes (5): closeModal(), exportVisibleTable(), openModal(), sortVisibleTable(), visibleTableFor()

### Community 55 - "User Factory"
Cohesion: 0.28
Nodes (5): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Notifications\Notifiable, static

### Community 58 - "App Service Provider"
Cohesion: 0.29
Nodes (4): AppServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 59 - "Composer Module 3"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pint, laravel/sail, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 60 - "Composer Module 4"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 62 - "Composer Module 5"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 67 - "AI Service"
Cohesion: 0.50
Nodes (3): Illuminate\Http\Client\ConnectionException, Illuminate\Support\Facades\Cache, Illuminate\Support\Facades\Log

### Community 68 - "Composer Module 6"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 69 - "Logging Module"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 70 - "Database Seeder"
Cohesion: 0.60
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 71 - "System Audit Report"
Cohesion: 0.40
Nodes (5): Live Operational Data Gap, Not Ready Verdict, CJP Southern Star OPC System and Audit Report, Final Turnover Gate, Turnover Readiness Checklist

### Community 74 - "Composer Module 7"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 75 - "Composer Module 8"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 78 - "Composer Module 9"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 79 - "Composer Module 10"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

### Community 90 - "Cjp Logo"
Cohesion: 0.67
Nodes (3): CJP Brand Logo, CJP Monogram, Green Star Symbol

## Knowledge Gaps
- **66 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+61 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **54 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Admin User Management Test` to `AI Data Preparation Service`, `Admin Monitoring Controller`, `Admin User Management Controller`, `Based Access Control Test`, `Admin Business Insight Controller 2`, `Test Case`, `Purchase Receipt Upload Test`, `Dashboard Summary Cards Test`, `Officer Stock In Test`, `Officer Stock Out Test`, `Role Access Testing Test`, `Officer Payment Recording Test`, `Officer Sales Management Test`, `Database Integrity Test`, `Integration Testing Test`, `Auth Controller`, `AI Feature Testing Test`, `AI Service Configuration Test`, `Complete Workflow Integration Test`, `Form Validation Test`, `Functional Testing Test`, `Garage Tank Service 2`, `Data Preparation Service Test`, `Analytics Testing Test`, `Business Insights Test`, `Turnover Readiness Test`, `Lifting Status Management Test`, `Inventory Variance Explanations Test`, `Officer Customer Management Test`, `Haul Truck Assignment Test`, `Officer Purchase Management Test`, `Revenue Insights Test`, `Officer Receivables Tracking Test`, `Password Reset Code Mail`, `Admin Sales Reports Test`, `Inventory Ledger Test`, `User Factory`, `Frontend Bug Fixes Test`, `Sales Trend Summaries Test`, `Admin Monitoring Test`, `AI Service`, `And Inventory Access Test`?**
  _High betweenness centrality (0.236) - this node is a cross-community bridge._
- **Why does `DashboardSummaryService` connect `Dashboard Summary Service` to `AI Data Preparation Service`, `Inventory Officer Purchase Controller`, `Admin Business Insight Controller 2`, `Test Case`, `Admin Dashboard Service`, `Admin Business Insight Controller`, `Sales Officer Customer Controller 2`, `Auth Controller`?**
  _High betweenness centrality (0.111) - this node is a cross-community bridge._
- **Why does `TestCase` connect `Test Case` to `Based Access Control Test`, `Admin Business Insight Controller 2`, `Admin User Management Test`, `Purchase Receipt Upload Test`, `Dashboard Summary Cards Test`, `Officer Stock In Test`, `Officer Stock Out Test`, `Role Access Testing Test`, `Officer Payment Recording Test`, `Officer Sales Management Test`, `Database Integrity Test`, `Integration Testing Test`, `Auth Controller`, `AI Feature Testing Test`, `AI Service Configuration Test`, `Complete Workflow Integration Test`, `Form Validation Test`, `Functional Testing Test`, `Garage Tank Service 2`, `Data Preparation Service Test`, `Analytics Testing Test`, `Business Insights Test`, `Turnover Readiness Test`, `Lifting Status Management Test`, `Inventory Variance Explanations Test`, `Officer Customer Management Test`, `Haul Truck Assignment Test`, `Officer Purchase Management Test`, `Revenue Insights Test`, `Officer Receivables Tracking Test`, `Admin Sales Reports Test`, `Inventory Ledger Test`, `Frontend Bug Fixes Test`, `Sales Trend Summaries Test`, `Admin Monitoring Test`, `AI Service`, `And Inventory Access Test`, `Example Test 2`?**
  _High betweenness centrality (0.082) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _66 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `AI Data Preparation Service` be split into smaller, more focused modules?**
  _Cohesion score 0.05030864197530864 - nodes in this community are weakly interconnected._
- **Should `Dashboard Summary Service` be split into smaller, more focused modules?**
  _Cohesion score 0.08018648018648018 - nodes in this community are weakly interconnected._
- **Should `Inventory Officer Purchase Controller` be split into smaller, more focused modules?**
  _Cohesion score 0.09085213032581453 - nodes in this community are weakly interconnected._