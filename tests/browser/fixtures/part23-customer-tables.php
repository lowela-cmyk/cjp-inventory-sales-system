<?php
// Synthetic browser fixtures only. First load a mysqldump of cjp_test_mariadb into
// the separate cjp_customer_tables_qa_20261117 database. Never use production data.
if (getenv('DB_DATABASE') !== 'cjp_customer_tables_qa_20261117') throw new RuntimeException('Explicit QA database required');
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
if (DB::connection()->getDatabaseName() !== 'cjp_customer_tables_qa_20261117') throw new RuntimeException('QA database required');
DB::transaction(function () {
    foreach (['admin','sales_officer','inventory_officer'] as $role) {
        DB::table('users')->insert(['name'=>'Customer QA '.$role,'email'=>'customer-qa-'.$role.'@example.test','password'=>Hash::make('Customer-QA-Only!'),'role'=>$role,'status'=>'active','approval_status'=>'approved','created_at'=>now(),'updated_at'=>now()]);
    }
    $admin=DB::table('users')->where('email','customer-qa-admin@example.test')->value('id');
    $depot=DB::table('depots')->insertGetId(['depot_code'=>'CUSTOMER-QA-DEP','name'=>'Customer QA Depot','status'=>'active']);
    $fuel=DB::table('fuel_types')->where('code','DSL')->value('id');
    if (!$fuel) throw new RuntimeException('Missing official Diesel fixture');
    for($i=0;$i<24;$i++) {
        $n=sprintf('%02d',$i);
        $customer=DB::table('customers')->insertGetId(['customer_code'=>'CUSTOMER-QA-'.$n,'name'=>$i%2?'Existing QA Contact '.$n:'','company_name'=>'Customer QA Company '.$n,'phone'=>'09171234567','location'=>'Lipa','status'=>'active','payment_status'=>'pending','created_at'=>now(),'updated_at'=>now()]);
        $purchase=DB::table('purchases')->insertGetId(['purchase_code'=>'CUSTOMER-QA-PUR-'.$n,'depot_id'=>$depot,'purchase_date'=>'2026-11-01','payment_mode'=>'cash','payment_status'=>'paid','status'=>'hauled','created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()]);
        $line=DB::table('purchase_items')->insertGetId(['purchase_id'=>$purchase,'fuel_type_id'=>$fuel,'quantity_ordered_liters'=>1000,'unit_cost'=>50,'line_total'=>50000,'quantity_hauled_liters'=>1000,'status'=>'lifted','created_at'=>now(),'updated_at'=>now()]);
        $sale=DB::table('sales')->insertGetId(['sale_code'=>'CUSTOMER-QA-SALE-'.$n,'sales_order_number'=>'QA-SO-'.$n,'delivery_type'=>'standard','customer_id'=>$customer,'sale_date'=>'2026-11-02','payment_method'=>'cash_on_delivery','payment_terms'=>'installment','status'=>'partially_paid','delivery_status'=>'delivered','delivered_at'=>'2026-11-03 10:00:00','created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()]);
        $item=DB::table('sale_items')->insertGetId(['sale_id'=>$sale,'fuel_type_id'=>$fuel,'purchase_item_id'=>$line,'quantity_liters'=>100,'unit_price'=>60,'line_total'=>6000,'fulfilled_quantity_liters'=>100,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('payments')->insert(['payment_code'=>'CUSTOMER-QA-PAY-'.$n,'sale_id'=>$sale,'payment_date'=>'2026-11-03','amount'=>1000,'method'=>'cash_on_delivery','received_by'=>$admin,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('receivables')->insert(['sale_id'=>$sale,'due_date'=>'2026-12-01','status'=>'partial','created_at'=>now(),'updated_at'=>now()]);
        DB::table('stock_outs')->insert(['stock_out_code'=>'CUSTOMER-QA-STO-'.$n,'sale_id'=>$sale,'sale_item_id'=>$item,'customer_id'=>$customer,'fuel_type_id'=>$fuel,'source_type'=>'depot','depot_id'=>$depot,'purchase_item_id'=>$line,'quantity_liters'=>100,'unit_cost'=>50,'stock_out_at'=>'2026-11-03 10:00:00','status'=>'released','created_by'=>$admin,'created_at'=>now(),'updated_at'=>now()]);
    }
});
echo "Synthetic QA fixtures created in ".DB::connection()->getDatabaseName()."\n";
