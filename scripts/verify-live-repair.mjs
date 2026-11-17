import {chromium} from 'playwright';
import {execFileSync} from 'node:child_process';
import {mkdirSync} from 'node:fs';
const fixture = JSON.parse(execFileSync('php', ['scripts/live-verification-session.php'], {encoding:'utf8', env:{...process.env, XDG_CONFIG_HOME:'/tmp/cjp-psysh'}}));
const {session_id, ...cookie} = fixture;
const baseUrl = process.env.CJP_VERIFY_BASE_URL || 'http://127.0.0.1:8001';
const outputDirectory = process.env.CJP_VERIFY_OUTPUT || 'outputs/live-repair';
const viewport = {
  width: Number(process.env.CJP_VERIFY_WIDTH || 1440),
  height: Number(process.env.CJP_VERIFY_HEIGHT || 1000),
};
const browser = await chromium.launch({headless:true});
const context = await browser.newContext({viewport});
await context.addCookies([cookie]);
const page = await context.newPage();
const errors=[];
page.on('pageerror', error=>errors.push(error.message));
mkdirSync(outputDirectory, {recursive:true});
try {
  const paths = process.argv.slice(2);
  for (const path of paths) {
    const start=Date.now();
    const response=await page.goto(baseUrl+path, {waitUntil:'networkidle',timeout:60000});
    const body=await page.locator('body').innerText();
    const garageHeaders = await page.locator('.garage-tank-table thead th:visible').allTextContents();
    const garageTableLayout = await page.locator('.garage-tank-table').evaluate(el => ({
      tableWidth: Math.round(el.getBoundingClientRect().width),
      wrapWidth: Math.round(el.parentElement.getBoundingClientRect().width),
      wrapScrollWidth: el.parentElement.scrollWidth,
    })).catch(() => null);
    const report={path,status:response.status(),url:page.url(),ms:Date.now()-start,viewport,stockOutRows:await page.locator('.stock-out-table tbody tr').count(),tanks:await page.locator('[data-garage-tank]').count(),garageHeaders,garageTableLayout,emptyGarage:body.includes('No garage tank matches the filters.'),bodySample:body.slice(0,400)};
    await page.screenshot({path:outputDirectory+'/'+path.replace(/[^a-z0-9]+/gi,'-')+'.png',fullPage:false});
    console.log(JSON.stringify(report));
    if(response.status()!==200 || page.url().includes('/login')) throw new Error('Screen verification failed');
  }
  console.log(JSON.stringify({javascriptErrors:errors}));
} finally {
  await browser.close();
  execFileSync('php',['artisan','tinker','--execute',`Illuminate\\Support\\Facades\\DB::table('sessions')->where('id','${session_id}')->delete();`],{env:{...process.env,XDG_CONFIG_HOME:'/tmp/cjp-psysh'},stdio:'ignore'});
}
