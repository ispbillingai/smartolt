const assert = require('node:assert/strict');
const fs = require('node:fs');

// Each scenario uses visible browser controls, then checks the resulting page, API state,
// downloaded content or actual chart data. Repeated table rows share the same behavior.
module.exports = async ({page,check,go,nav,form,confirm,api}) => {
 const download = async locator => {
  const [file] = await Promise.all([page.waitForEvent('download'), locator.click()]);
  const text = fs.readFileSync(await file.path(),'utf8');
  assert(!/<html|Fatal error|Warning:/i.test(text),'Download contains an application error');
  return text;
 };
 const submit = async f => {if(await f.getAttribute('data-confirm')){await f.locator('button').click();await confirm();}else await nav(()=>f.locator('button').click());};
 await check('events search, mute one, muted tab and unmute',async()=>{
  const device=await api('olt.save',{name:'Browser event fixture',conn_type:'direct',host:'127.0.0.1',telnet_port:1,username:'root',password:'admin123'});assert(device.ok);
  try {
  const failed=await api('olt.refresh',{id:device.id});assert.equal(failed.ok,false,'Unreachable fixture unexpectedly connected');
  await go('events.php?tab=all&q=Browser%20event%20fixture');
  const mute=form('mute').filter({hasNot:page.locator('[name=similar]')}).first();
  assert(await mute.count(),'Fixture must have a real event');
  const id=await mute.locator('[name=id]').inputValue();
  await nav(()=>mute.locator('button').click());
  await nav(()=>page.locator('a[href*="tab=muted"]').first().click());
  const unmute=form('unmute').filter({has:page.locator('[name=id][value="'+id+'"]')});
  assert(await unmute.count());await nav(()=>unmute.locator('button').click());
  assert.equal(await form('unmute').filter({has:page.locator('[name=id][value="'+id+'"]')}).count(),0);
  await go('events.php?tab=all&q=Browser%20event%20fixture');await nav(()=>form('mute').filter({has:page.locator('[name=similar]')}).first().locator('button').click());await nav(()=>page.locator('a[href*="tab=muted"]').first().click());await nav(()=>form('unmute').filter({has:page.locator('[name=id][value="'+id+'"]')}).locator('button').click());
  await page.locator('[name=q]').fill('no-such-browser-event');await nav(()=>page.locator('button[title=Search]').click());assert((await page.locator('main').innerText()).includes('No events found'));
  } finally {await api('olt.delete',{id:device.id});}
 });
 await check('diagnostics search, status toggle, sort and filtered CSV',async()=>{
  await go('diag.php');await page.locator('[name=q]').fill('alice');await nav(()=>page.getByRole('button',{name:'Refresh',exact:false}).click());
  assert.equal(await page.locator('tbody tr').count(),1);await nav(()=>page.locator('a[title=Online]').click());assert(page.url().includes('status=online'));
  await nav(()=>page.locator('th a').first().click());assert(page.url().includes('sort=signal'));
  const text=await download(page.locator('button[name=export]'));assert(/alice/i.test(text));assert(!/bob/i.test(text));
  await nav(()=>page.locator('tbody a[href*="onu.php"]').first().click());assert(page.url().includes('onu.php?id=1'));
 });
 await check('OLT refresh, save running configuration, edit and persisted fields',async()=>{
  await go('olts.php?edit=1');const original=await page.locator('[name=name]').inputValue();await page.locator('[name=name]').fill(original+' browser');await nav(()=>form('save').locator('button').click());
  assert((await api('olt.list')).olts.some(o=>Number(o.id)===1&&o.name===original+' browser'));
  await go('olts.php?edit=1');await page.locator('[name=name]').fill(original);await nav(()=>form('save').locator('button').click());
  await nav(()=>form('refresh').filter({has:page.locator('[name=id][value="1"]')}).locator('button').click());assert((await page.locator('main').innerText()).includes('ONUs'));
  await page.locator('[data-open=soSave]').click();await page.locator('#soSave [name=olt_id]').selectOption('1');await nav(()=>page.locator('#soSave button.so-btn-blue').click());
  assert((await api('olt.list')).olts.find(o=>Number(o.id)===1).saved_at,'Device save timestamp absent');
 });
 await check('OLT SNMP test reports actual fixture interfaces and chart follows theme',async()=>{
  await go('olt.php?id=1&tab=uplink');await nav(()=>form('snmp').locator('button').click());assert((await page.locator('main').innerText()).includes('SNMP read completed'));
  assert.equal((await api('olt.list')).olts.find(o=>Number(o.id)===1).snmp_status,'online');
  const before=await page.evaluate(()=>Object.values(Chart.instances).map(c=>({id:c.id,labels:c.data.labels.length,color:c.options.scales.y?.ticks.color})));
  await page.locator('#soTheme').click();const after=await page.evaluate(()=>Object.values(Chart.instances).map(c=>({id:c.id,labels:c.data.labels.length,color:c.options.scales.y?.ticks.color})));
  assert.deepEqual(after.map(c=>[c.id,c.labels]),before.map(c=>[c.id,c.labels]));for(const c of after)if(c.color)assert.equal(c.color,await page.evaluate(()=>soChart.tick()));
 });
 await check('VLAN create and delete reflect the device table',async()=>{
  await go('olt.php?id=1&tab=vlans');await page.locator('[data-open=vlanModal]').click();const add=form('vlan_add');await add.locator('[name=vlan]').fill('333');await add.locator('[name=desc]').fill('BrowserVLAN');await nav(()=>add.locator('button.so-btn-green').click());assert(await form('vlan_delete').filter({has:page.locator('[name=vlan][value="333"]')}).count());
  await form('vlan_delete').filter({has:page.locator('[name=vlan][value="333"]')}).locator('button').click();await confirm();assert.equal(await form('vlan_delete').filter({has:page.locator('[name=vlan][value="333"]')}).count(),0);
 });
 await check('OLT diagnostics command returns hardware output and copy button works',async()=>{
  await go('olt.php?id=1&tab=cli');await page.locator('[name=command]').fill('display version');await nav(()=>form('cli').filter({has:page.locator('[name=command]')}).locator('button').click());assert((await page.locator('#cliOut').innerText()).includes('MA5683T'));
  const copy=page.locator('[data-copy]').first();if(await copy.count()){await copy.click();await page.waitForFunction(()=>document.querySelector('[data-copy]').innerText.includes('Copied')||getSelection().toString().length>0);}
 });
 await check('backup schedule, capture, download, delete and OLT filter',async()=>{
  await go('backups.php');const schedule=form('schedule').filter({has:page.locator('[name=olt_id][value="1"]')});await schedule.locator('[name=hours]').selectOption('24');await nav(()=>schedule.locator('button').click());assert.equal(await form('schedule').filter({has:page.locator('[name=olt_id][value="1"]')}).locator('[name=hours]').inputValue(),'24');
  await form('capture').locator('[name=olt_id]').selectOption('1');await nav(()=>form('capture').locator('button').click());assert((await page.locator('main').innerText()).includes('Configuration backup created'));
  const get=form('download').first(),id=await get.locator('[name=id]').inputValue();assert((await download(get.locator('button'))).includes('sysname'));
  await form('delete').filter({has:page.locator('[name=id][value="'+id+'"]')}).locator('button').click();await confirm();assert.equal(await form('download').filter({has:page.locator('[name=id][value="'+id+'"]')}).count(),0);
  await nav(()=>page.locator('select[name=olt]').selectOption('1'));assert(page.url().includes('olt=1'));
  const disable=form('schedule').filter({has:page.locator('[name=olt_id][value="1"]')});await disable.locator('[name=hours]').selectOption('0');await nav(()=>disable.locator('button').click());
 });
 await check('Ethernet counters, live start-stop, retained chart samples, port selection and MAC CSV',async()=>{
  await go('traffic.php?id=1');await Promise.all([page.waitForResponse(r=>r.url().endsWith('/traffic.php')&&r.request().method()==='POST'),page.locator('#readTraffic').click()]);await page.locator('#trafficRows tr').first().waitFor();assert((await page.locator('#trafficResult').innerText()).includes('Last successful read'));
  const data=await page.evaluate(()=>Chart.getChart('trafficChart').data.labels.length);assert(data>0);await page.locator('#soTheme').click();assert.equal(await page.evaluate(()=>Chart.getChart('trafficChart').data.labels.length),data);
  await Promise.all([page.waitForResponse(r=>r.url().endsWith('/traffic.php')&&r.request().method()==='POST'),page.locator('#liveTraffic').click()]);assert.equal(await page.locator('#liveTraffic').getAttribute('aria-pressed'),'true');await page.locator('#liveTraffic').click();assert.equal(await page.locator('#liveTraffic').getAttribute('aria-pressed'),'false');
  const csv=await download(form('mac').locator('button'));assert(/MAC/.test(csv));assert(/[A-Fa-f0-9]{2}[:-][A-Fa-f0-9]{2}/.test(csv));
  if(await page.locator('#ethPort option').count()>1){await nav(()=>page.locator('#ethPort').selectOption('2'));assert(page.url().includes('port=2'));}
 });
 for(const [route,selector] of [['reports.php?r=auth','button[name=csv]'],['reports.php?r=export','button.so-btn-green'],['reports.php?r=status','button[name=csv]'],['reports.php?r=splitters','button[name=csv]'],['reports.php?r=traffic','button[name=csv]'],['olts.php','a[href="olts.php?export=1"]']])await check('real CSV download: '+route,async()=>{await go(route);const content=await download(page.locator(selector));assert(content.length>10);assert(content.includes(','));});
 await check('mismatch scan and automatic reconciliation switch',async()=>{
  await go('reports.php?r=mismatch');const before=await form('auto_sync').locator('[name=on]').inputValue();await submit(form('auto_sync'));assert.notEqual(await form('auto_sync').locator('[name=on]').inputValue(),before);await submit(form('auto_sync'));assert.equal(await form('auto_sync').locator('[name=on]').inputValue(),before);
  await form('scan').locator('select').selectOption('1');await nav(()=>form('scan').locator('button').click());assert(!await page.locator('.a-err').count());
 });
 await check('automatic authorization global start and stop persists',async()=>{
  await go('auto_actions.php');const before=await form('auto_toggle').innerText();await nav(()=>form('auto_toggle').locator('button').click());assert.notEqual(await form('auto_toggle').innerText(),before);await nav(()=>form('auto_toggle').locator('button').click());assert.equal(await form('auto_toggle').innerText(),before);
 });
 await check('OLT add, direct connection fields, filter export and delete',async()=>{
  const main=(await api('olt.list')).olts.find(o=>Number(o.id)===1);
  await go('olts.php?add=1');await page.locator('#connType').selectOption('direct');assert(await page.locator('[name=host]').isVisible());assert(!await page.locator('[name=lan_ip]').isVisible());
  for(const [name,value] of Object.entries({name:'Browser added OLT',host:'127.0.0.1',telnet_port:String(main.telnet_port),username:'root',password:'admin123'}))await page.locator('[name='+name+']').fill(value);
  await nav(()=>form('save').locator('button').click());const added=(await api('olt.list')).olts.find(o=>o.name==='Browser added OLT');assert(added&&added.status==='online');
  await page.locator('[name=q]').fill('Browser added OLT');await nav(()=>page.locator('[name=q]').press('Enter'));assert.equal(await page.locator('tbody tr').count(),1);
  await form('delete').filter({has:page.locator('[name=id][value="'+added.id+'"]')}).locator('button').click();await confirm();assert(!(await api('olt.list')).olts.some(o=>Number(o.id)===Number(added.id)));
 });
 await check('ONU CSV upload changes metadata and unknown serial reports a skipped row',async()=>{
  const onu=(await api('onu.list',{q:'alice'})).onus[0];await go('reports.php?r=import');await page.locator('[type=file]').setInputFiles({name:'onus.csv',mimeType:'text/csv',buffer:Buffer.from('Serial number,Address,External ID\n'+onu.sn+',Browser imported address,browser-import\nHWTCDEADFFFF,Unknown,none\n')});
  await nav(()=>form('import').locator('button').click());assert((await page.locator('main').innerText()).includes('Updated 1 ONU'));assert((await page.locator('main').innerText()).includes('1 rows skipped'));
  const updated=(await api('onu.list',{q:'alice'})).onus[0];assert.equal(updated.address,'Browser imported address');assert.equal(updated.customer_ref,'browser-import');
 });
 await check('pending authorization delete confirms and removes the queued serial',async()=>{
  await go('unconfigured.php');const row=page.locator('tbody tr').filter({hasText:'Browser_scheduled_ONU'});assert.equal(await row.count(),1);await row.locator('button').click();await confirm();assert.equal(await page.locator('tbody tr').filter({hasText:'Browser_scheduled_ONU'}).count(),0);
 });
 await check('ticket schedule follows the saved timezone and history date filters apply',async()=>{
  await go('settings.php');const zone=await page.locator('[name=timezone]').inputValue();await page.locator('[name=timezone]').selectOption('Europe/London');await nav(()=>page.getByRole('button',{name:'Save changes'}).click());
  await go('tickets.php?add=1&onu=1');await page.locator('[name=subject]').fill('Browser timezone ticket');await page.locator('[name=start_at]').fill('2026-11-05T09:00');await page.locator('[name=duration_min]').fill('90');await page.locator('[name=priority]').selectOption('high');await nav(()=>page.getByRole('button',{name:'Add the ticket'}).click());await go('tickets.php');assert((await page.locator('tbody tr').filter({hasText:'Browser timezone ticket'}).innerText()).includes('05-Nov-2026 09:00'));
  await go('settings.php');await page.locator('[name=timezone]').selectOption('Africa/Nairobi');await nav(()=>page.getByRole('button',{name:'Save changes'}).click());await go('tickets.php');assert((await page.locator('tbody tr').filter({hasText:'Browser timezone ticket'}).innerText()).includes('05-Nov-2026 12:00'));
  await go('settings.php');await page.locator('[name=timezone]').selectOption(zone);await nav(()=>page.getByRole('button',{name:'Save changes'}).click());
  await go('tasks.php');await nav(()=>page.locator('[name=olt]').selectOption('1'));await nav(()=>page.locator('[name=from]').fill('2099-01-01'));assert((await page.locator('main').innerText()).includes('No tasks yet'));
 });
 await check('ONU delete, rediscovery, authorize, VIP, resync, restore, serial replacement and final deletion',async()=>{
  // Bob is a disposable fixture subscriber. All mutation targets are resolved from the local API.
  await go('auto_actions.php');const autoWasOn=(await form('auto_toggle').innerText()).includes('Stop');if(autoWasOn)await nav(()=>form('auto_toggle').locator('button').click());
  await go('olts.php');await nav(()=>form('refresh').filter({has:page.locator('[name=id][value="1"]')}).locator('button').click());const old=(await api('onu.list',{q:'bob'})).onus[0];assert(old,'Expected disposable Bob ONU');await go('onu.php?id='+old.id);await form('delete').locator('button').click();await confirm();
  await go('unconfigured.php?olt=1');await nav(()=>page.locator('button[name=refresh]').click());const authorize=page.locator('tr').filter({hasText:old.sn}).locator('a[href*="authorize.php"]');await nav(()=>authorize.click());
  await page.locator('#preset').selectOption({index:1});await page.locator('[name=description]').fill('Browser authorized ONU');await page.locator('[name=customer_ref]').fill('browser-authorize');await page.locator('details summary').click();assert.equal(await page.locator('[name=gemport]').getAttribute('min'),'1');await page.locator('[name=gemport]').fill('1');await nav(()=>page.locator('form.so-form button.so-btn-green:not([type=button])').click());
  let onu=(await api('onu.list',{q:'browser-authorize'})).onus[0];assert(onu&&Number(onu.olt_id)===1);assert((await page.locator('main').innerText()).includes('ONU authorized'));
  await nav(()=>form('vip').locator('button').click());assert.equal(Number((await api('onu.get',{id:onu.id})).onu.vip),1);
  await submit(form('disable'));assert.equal((await api('onu.get',{id:onu.id})).onu.status_group,'disabled');await submit(form('enable'));assert.notEqual((await api('onu.get',{id:onu.id})).onu.status_group,'disabled');
  await submit(form('resync'));assert((await page.locator('main').innerText()).includes('put on the OLT again'));await submit(form('restore'));assert((await page.locator('main').innerText()).includes('Factory defaults restored'));
  await page.locator('[data-open=replaceSn]').click();await page.locator('#replaceSn [name=new_sn]').fill('HWTCABCDEF88');await page.locator('#replaceSn button.so-btn-blue').click();await confirm();assert.equal((await api('onu.get',{id:onu.id})).onu.sn,'48575443ABCDEF88');
  await form('delete').locator('button').click();await confirm();assert(!(await api('onu.list',{q:'browser-authorize'})).onus.length);
  if(autoWasOn){await go('auto_actions.php');await nav(()=>form('auto_toggle').locator('button').click());}
 });
 await check('settings password mismatch rejects then valid password changes and restores',async()=>{
  await go('settings.php');await page.locator('[name=new_password]').fill('Browser-Changed-2026');await page.locator('[name=confirm_password]').fill('Different-2026');await nav(()=>page.getByRole('button',{name:'Save changes'}).click());assert(/match/i.test(await page.locator('main').innerText()));
  await page.locator('[name=new_password]').fill('Browser-Changed-2026');await page.locator('[name=confirm_password]').fill('Browser-Changed-2026');await nav(()=>page.getByRole('button',{name:'Save changes'}).click());await go('logout.php');await go('index.php');await page.locator('[name=username]').fill('admin');await page.locator('[name=password]').fill('Browser-Changed-2026');await nav(()=>page.locator('button[type=submit]').first().click());await page.locator('.so-dashboard').waitFor();
  await go('settings.php');await page.locator('[name=new_password]').fill('Fibre-Test-2026');await page.locator('[name=confirm_password]').fill('Fibre-Test-2026');await nav(()=>page.getByRole('button',{name:'Save changes'}).click());
 });
 if(process.env.SMARTOLT_TEST_ACS_URL)await check('ACS settings, test connection, device readings, refresh, WiFi and reboot queue',async()=>{
  const acsBase=process.env.SMARTOLT_TEST_ACS_URL;assert.equal(new URL(acsBase).hostname,'127.0.0.1');
  await page.request.post(acsBase+'/__control',{data:{reset:true,serial:'HWTC4D2F0001'}});
  const action=name=>page.locator('form').filter({has:page.locator('[name=action][value="'+name+'"]')});
  await go('acs.php');await page.locator('[name=base_url]').fill(acsBase);await page.locator('[name=api_key]').fill('fixture-acs-key');await page.locator('[name=enabled]').check();await nav(()=>action('settings').locator('button').click());assert((await page.locator('main').innerText()).includes('ACS connection saved'));
  await nav(()=>action('test').locator('button').click());assert((await page.locator('main').innerText()).includes('ACS accepted'));
  await go('acs.php?onu_id=1');await nav(()=>action('read').locator('button').click());assert((await page.locator('main').innerText()).includes('Fixture WiFi'));assert(!(await page.content()).includes('device-wifi-secret'));
  await nav(()=>action('refresh').locator('button').click());assert((await page.locator('main').innerText()).includes('queued in the ACS'));
  await action('wifi').locator('[name=ssid]').fill('Browser verified WiFi');await action('wifi').locator('[name=wifi_password]').fill('browser-wifi-secret');await action('wifi').locator('[name=confirmed]').check();await nav(()=>action('wifi').locator('button').click());assert((await page.locator('main').innerText()).includes('queued in the ACS'));assert(!(await page.content()).includes('browser-wifi-secret'));
  await action('reboot').locator('[name=confirmed]').check();await nav(()=>action('reboot').locator('button').click());const state=await (await page.request.get(acsBase+'/__control')).json();assert.equal(state.submitted,3);
  await page.request.post(acsBase+'/__control',{data:{task_status:'completed'}});await nav(()=>action('read').locator('button').click());assert((await page.locator('main').innerText()).includes('Completed'));
  await go('reports.php?r=wifi');assert((await page.locator('main').innerText()).includes('Fixture WiFi'));
 });
};
