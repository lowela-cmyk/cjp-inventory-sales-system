// Requires a verified --no-reload QA server on port 8124 and temporary final-qa role accounts.
import {chromium} from 'playwright';
import fs from 'node:fs/promises';
const base='http://127.0.0.1:8124';
const browser=await chromium.launch({headless:true});
const results=[];const errors=[];const requests=[];
const page=await browser.newPage({viewport:{width:1440,height:1000}});
page.on('pageerror',e=>errors.push(e.message));
const goto=async path=>{const start=performance.now();const response=await page.goto(base+path,{waitUntil:'domcontentloaded'});requests.push({path,status:response.status(),ms:Math.round(performance.now()-start)});return response;};
const state=async()=>({url:page.url(),active:await page.locator('[data-tab-target].is-active').count()?await page.locator('[data-tab-target].is-active').first().getAttribute('data-tab-target'):null,heading:await page.locator('[data-tab-heading]').count()?await page.locator('[data-tab-heading]').first().textContent():null});
const check=async(name,fn)=>{try{const detail=await fn();results.push({name,...detail});}catch(e){results.push({name,pass:false,error:e.message});}};
const login=async role=>{await page.context().clearCookies();await goto('/login');await page.locator('#username').fill('final-qa-'+role+'@example.test');await page.locator('#password').fill('Final-QA-Only!');await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator('.login-submit').click()]);};
const clickNav=async locator=>{const href=await locator.getAttribute('href');const start=performance.now();await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),locator.click()]);return {href,ms:Math.round(performance.now()-start),...await state()};};
try{
 for(const [role,prefix] of [['admin','admin'],['inventory_officer','inventory-officer']]){
  await login(role);
  const stockPath=`/${prefix}/inventory/stock-in`;const purchasePath=`/${prefix}/inventory`;
  const filtered=stockPath+'?per_page=10&search=PUR&status=available&sort=purchase_date&direction=desc';
  await goto(filtered);
  const panel=page.locator('[data-tab-panel="stock-in"]');
  const firstKeys=await panel.locator('tbody > tr > td:first-child').allTextContents();
  for(const [label,selector] of [['page number 2','a[aria-label="Page 2"]'],['Next','a[rel="next"]'],['Previous','a[rel="prev"]']]){
   await check(`${role}: direct Stock-In ${label}`,async()=>{
    await goto(filtered+(label==='Previous'?'&stock_in_page=2':''));
    const observed=await clickNav(panel.locator('[data-table-pagination] '+selector));
    const url=new URL(observed.url);
    return {pass:observed.active==='stock-in'&&url.pathname===stockPath&&url.searchParams.get('status')==='available'&&url.searchParams.get('search')==='PUR'&&url.searchParams.get('per_page')==='10'&&url.searchParams.get('sort')==='purchase_date',...observed};
   });
  }
  await check(`${role}: distinct Stock-In pages`,async()=>{await goto(filtered+'&stock_in_page=2');const second=await panel.locator('tbody > tr > td:first-child').allTextContents();return {pass:firstKeys.length===10&&second.length===10&&!firstKeys.some(x=>second.includes(x)),page1:firstKeys,page2:second};});
  for(const [label,selector] of [['page number 2','a[aria-label="Page 2"]'],['Next','a[rel="next"]'],['Previous','a[rel="prev"]']]){
   await check(`${role}: Purchases → Stock-In ${label}`,async()=>{
    await goto(purchasePath+'?per_page=10'+(label==='Previous'?'&stock_in_page=2':''));
    await page.locator('[data-tab-target="stock-in"]').click();
    const before=await state();
    if(label==='Previous' && await panel.locator('[data-table-pagination] a[rel="prev"]').count()===0) await clickNav(panel.locator('[data-table-pagination] a[rel="next"]'));
    const observed=await clickNav(panel.locator('[data-table-pagination] '+selector));
    return {pass:observed.active==='stock-in'&&new URL(observed.url).pathname===stockPath,before,...observed};
   });
  }
  await check(`${role}: Purchases → Stock-In page size`,async()=>{
   await goto(purchasePath+'?per_page=10');await page.locator('[data-tab-target="stock-in"]').click();
   const action=await panel.locator('[data-page-size-form]').getAttribute('action');
   await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),panel.locator('[data-page-size-form] select').selectOption('25')]);
   const observed=await state();return {pass:observed.active==='stock-in'&&new URL(observed.url).pathname===stockPath,formAction:action,...observed};
  });
 }
 await login('admin');
 const modules=[['Purchases','/admin/inventory','purchases','purchases_page'],['Stock-In','/admin/inventory/stock-in','stock-in','stock_in_page'],['Stock-Out','/admin/inventory/stock-out','stock-out','stock_out_page'],['Ledger','/admin/ledger','ledger','ledger_page'],['Ledger Transactions','/admin/ledger/transactions','transactions','ledger_page'],['Fuel Lifting Schedule','/admin/fuel-lifting','schedule','lifts_page'],['Fuel Lifting Hauled','/admin/fuel-lifting/hauled','hauled','lifts_page'],['Sales','/admin/sales','receivables','sales_page'],['Customers','/admin/sales/customers','customers','customers_page']];
 for(const [name,path,tab,param] of modules){
  await check(`${name}: independent filtered page navigation`,async()=>{
   await goto(path+'?per_page=10&search=&sort=id&direction=desc');
   const pagination=page.locator(`[data-tab-panel="${tab}"] [data-table-pagination]`).first();
   const nav=await pagination.count()?pagination:page.locator('[data-table-pagination]').first();
   if(await nav.locator('a[rel="next"]').count()===0) return {pass:Number(await nav.getAttribute('data-total'))<=10,note:'Single page; Next correctly absent'};
   const href=await nav.locator('a[rel="next"]').getAttribute('href');
   const observed=await clickNav(nav.locator('a[rel="next"]'));
   const url=new URL(observed.url);return {pass:observed.active===tab&&url.pathname===path&&url.searchParams.get(param)==='2'&&url.searchParams.get('per_page')==='10'&&url.searchParams.get('sort')==='id',pageParameter:param,...observed};
  });
 }
 await check('Sales → Customers Next keeps Customers',async()=>{await goto('/admin/sales?per_page=10');await page.locator('[data-tab-target="customers"]').click();const panel=page.locator('[data-tab-panel="customers"]');const observed=await clickNav(panel.locator('a[rel="next"]'));return {pass:observed.active==='customers',...observed};});
 for(const [name,path] of [['ledger','/admin/ledger'],['lifting','/admin/fuel-lifting']]){
  await check(`${name}: server tab switch then Next`,async()=>{await goto(path+'?per_page=10');const tab=name==='ledger'?'transactions':'hauled';await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.locator(`[data-tab-target="${tab}"]`).click()]);const observed=await clickNav(page.locator('[data-table-pagination] a[rel="next"]').first());return {pass:observed.active===tab,...observed};});
 }
 for(const path of ['/admin/dashboard','/admin/sales/customers?per_page=10','/admin/imports']){
  await check(`UI controls: ${path}`,async()=>{const response=await goto(path);const detail={http:response.status(),...await state().catch(()=>({url:page.url()}))};if(path.includes('dashboard'))return {pass:response.status()===200&&(await page.locator('[data-dashboard-export]').count())===1&&(await page.locator('[data-revenue-period]').count())===0,...detail};if(path.includes('customers'))return {pass:(await page.locator('input[name="email"]').count())===0&&(await page.locator('select[name="payment_status"]').first().count())===1,...detail};return {pass:response.status()===200,...detail};});
 }
 for(const [from,tab] of [['/admin/inventory','stock-out'],['/admin/inventory/stock-in','purchases'],['/admin/sales/customers','receivables']]) {
  await check(`Reverse/other tab: ${from} → ${tab}`,async()=>{
   await goto(from+'?per_page=10');await page.locator(`[data-tab-target="${tab}"]`).click();
   const observed=await clickNav(page.locator(`[data-tab-panel="${tab}"] a[rel="next"]`));
   return {pass:observed.active===tab,...observed};
  });
 }
 await check('Stock-In sorting controls reset page and remain on Stock-In',async()=>{
  await goto('/admin/inventory/stock-in?per_page=10&stock_in_page=2');
  const panel=page.locator('[data-tab-panel="stock-in"]');
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),panel.locator('select[name="sort"]').selectOption('code')]);
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),panel.locator('select[name="direction"]').selectOption('asc')]);
  const codes=await panel.locator('tbody>tr>td:first-child').allTextContents();const url=new URL(page.url());
  return {pass:url.pathname==='/admin/inventory/stock-in'&&!url.searchParams.has('stock_in_page')&&JSON.stringify(codes)===JSON.stringify([...codes].sort()),url:page.url(),codes};
 });
 await check('Stock-In retains its own search/filter/sort/page state across tabs',async()=>{
  await goto('/admin/inventory/stock-in?per_page=10&search=PUR&status=available&sort=code&direction=asc&stock_in_page=2');
  await page.locator('[data-tab-target="purchases"]').click();
  const purchaseUrl=new URL(page.url());
  await page.locator('[data-tab-target="stock-in"]').click();
  const url=new URL(page.url());const state=await page.locator('[data-tab-panel="stock-in"] [data-table-pagination]').textContent();
  return {pass:purchaseUrl.pathname==='/admin/inventory'&&!purchaseUrl.searchParams.has('status')&&url.pathname==='/admin/inventory/stock-in'&&url.searchParams.get('search')==='PUR'&&url.searchParams.get('status')==='available'&&url.searchParams.get('sort')==='code'&&url.searchParams.get('direction')==='asc'&&url.searchParams.get('per_page')==='10'&&url.searchParams.get('stock_in_page')==='2',url:page.url(),state};
 });
 await fs.writeFile(process.env.AUDIT_OUT||'outputs/part23-remediation/pagination-browser-audit.json',JSON.stringify({results,requests,javascriptErrors:errors},null,2));
 console.log(JSON.stringify({checks:results.length,passed:results.filter(r=>r.pass).length,failed:results.filter(r=>!r.pass).map(r=>({name:r.name,url:r.url,error:r.error})),javascriptErrors:errors},null,2));
 if(results.some(r=>!r.pass)||errors.length) process.exitCode=1;
}finally{await browser.close();}
