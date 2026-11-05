// Optional visual/browser smoke test after web_test.sh has populated the local fixture.
// NODE_PATH must contain Playwright; set SMARTOLT_BROWSER to a Chromium executable if needed.
const { chromium } = require('playwright');
const path = require('node:path');
const fs = require('node:fs');
const base = process.argv[2] || 'http://127.0.0.1:48099';
if (!['127.0.0.1', 'localhost'].includes(new URL(base).hostname)) throw new Error('Browser fixture tests are restricted to localhost.');
(async () => {
  const browser = await chromium.launch({headless:true, ...(process.env.SMARTOLT_BROWSER ? {executablePath:process.env.SMARTOLT_BROWSER} : {})});
  const errors = [];
  const page = await browser.newPage({viewport:{width:1440,height:1000}});
  page.on('pageerror', error => errors.push(error.message));
  const output = path.resolve(__dirname, '../.test-runtime');
  fs.mkdirSync(output, {recursive:true});
  try {
    await page.goto(base + '/index.php');
    await page.locator('[name=username]').fill('admin');
    await page.locator('[name=password]').fill('Fibre-Test-2026');
    await Promise.all([page.waitForNavigation(), page.locator('button[type=submit],button:not([type])').first().click()]);
    await page.locator('.so-dashboard').waitFor();
    const chartState = await page.evaluate(() => typeof Chart !== 'undefined' && !!Chart.getChart('authChart'));
    if (!chartState && !await page.locator('.dash-chart-fallback').last().isVisible()) throw new Error('Neither a rendered chart nor its unavailable notice is visible.');
    console.log(chartState ? 'PASS dashboard chart rendered' : 'PASS unavailable chart has an explicit fallback (CDN did not load)');
    await page.screenshot({path:path.join(output,'dashboard-desktop.png'),fullPage:true});
    await page.screenshot({path:path.join(output,'dashboard-preview.png')});
    await page.locator('#soTheme').click();
    await page.screenshot({path:path.join(output,'dashboard-light.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:path.join(output,'dashboard-mobile.png'),fullPage:true});
    const dimensions = await page.evaluate(() => ({width:innerWidth,scroll:document.documentElement.scrollWidth}));
    if (dimensions.scroll > dimensions.width + 1) {
      const overflow = await page.evaluate(() => Array.from(document.querySelectorAll('body *')).map(el => ({tag:el.tagName,cls:el.className,right:el.getBoundingClientRect().right})).filter(el => el.right > innerWidth + 1).slice(0,20));
      throw new Error('Dashboard overflows the mobile viewport: ' + JSON.stringify({dimensions,overflow}));
    }
    for (const target of ['/olt.php?id=1&tab=uplink', '/backups.php', '/vpn.php']) {
      const response = await page.goto(base + target);
      if (response.status() !== 200) throw new Error(target + ' HTTP ' + response.status());
      if (/Fatal error|Uncaught|<b>Warning<\/b>/.test(await page.content())) throw new Error('PHP error on ' + target);
    }
    if (errors.length) throw new Error('Browser script errors: ' + [...new Set(errors)].join('; '));
    console.log('PASS browser login, desktop/light/mobile dashboard, mobile width, SNMP/backups/VPN pages and JavaScript');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
