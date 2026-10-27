// Local browser regression check; no live Supabase requests or emails.
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');
const root = __dirname;
const source = fs.readFileSync(path.join(root,'../Li.La_20_07_26/index.html'),'utf8');
const config = fs.readFileSync(path.join(root,'supabase-client.js'),'utf8').trim();
assert.equal(config,source.match(/  const LILA_SUPABASE_URL[\s\S]*?(?=\r?\n  let lilaAuthSession)/)[0].trim());
for(const asset of fs.readdirSync(path.join(root,'assets'))){
  assert.deepEqual(fs.readFileSync(path.join(root,'assets',asset)),fs.readFileSync(path.join(root,'../Li.La_20_07_26',asset)));
}
const server=http.createServer((req,res)=>{
  const filename=path.resolve(root,'.'+decodeURIComponent(new URL(req.url,'http://localhost').pathname));
  if(!filename.startsWith(root+path.sep)){res.writeHead(404).end();return;}
  try{const data=fs.readFileSync(filename);const types={'.html':'text/html','.js':'application/javascript','.css':'text/css','.json':'application/json','.png':'image/png'};res.setHeader('Content-Type',types[path.extname(filename)]||'text/plain');res.end(data);}catch{res.writeHead(404).end();}
});
const mock=`window.supabase={createClient:(url,key,options)=>{
 window.clientConfig={url,key,options};window.testSession=null;window.failSignOut=false;
 window.testAuth=session=>{window.testSession=session;window.authCallback('SIGNED_IN',session);};
 return {auth:{getSession:async()=>({data:{session:window.testSession}}),onAuthStateChange:cb=>{window.authCallback=cb;return {data:{subscription:{unsubscribe(){}}}};},signInWithOtp:async args=>{window.otpArgs=args;return {error:null};},signOut:async()=>({error:window.failSignOut?new Error('offline'):null})}};
}};`;
(async()=>{
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try{
  const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://**/*',route=>route.request().url().includes('@supabase/supabase-js')?route.fulfill({contentType:'application/javascript',body:mock}):route.abort());
  await page.goto(`http://127.0.0.1:${server.address().port}/index.html`);
  await page.waitForFunction(()=>!document.getElementById('auth-submit').disabled);
  assert.equal(await page.locator('#cockpit').isVisible(),false);
  await page.locator('#email').fill('test@example.com');await page.locator('#auth-submit').click();
  await page.waitForFunction(()=>window.otpArgs);
  assert.deepEqual(await page.evaluate(()=>window.otpArgs),{email:'test@example.com',options:{shouldCreateUser:false,emailRedirectTo:'https://app.li-la.fr/'}});
  await page.evaluate(()=>{localStorage.setItem('lila_user_profile',JSON.stringify({firstName:'Camille',email:'test@example.com'}));window.testAuth({user:{id:'test-1',email:'test@example.com'}});});
  assert.equal(await page.locator('#cockpit').isVisible(),true);
  assert.equal(await page.locator('#greeting').textContent(),'Bonjour, Camille.');
  assert.equal(await page.locator('.space').count(),6);
  await page.locator('#search').fill('reseau');assert.equal(await page.locator('.space:visible').count(),1);
  await page.locator('.space:visible').click();assert.equal(await page.locator('#detail').isVisible(),true);
  await page.keyboard.press('Escape');await page.locator('#search').fill('zzzz');assert.equal(await page.locator('#no-results').isVisible(),true);
  await page.locator('#search').fill('');
  await page.locator('#profile-button').click();assert.match(await page.locator('#profile-fields').textContent(),/Camille/);
  await page.evaluate(()=>window.failSignOut=true);await page.locator('#sign-out').click();
  assert.equal(await page.locator('#detail').isVisible(),true);assert.match(await page.locator('#detail-status').textContent(),/impossible/);
  await page.evaluate(()=>window.failSignOut=false);await page.locator('#sign-out').click();assert.equal(await page.locator('#cockpit').isVisible(),false);assert.equal(await page.locator('#profile-fields').textContent(),'');
  await page.evaluate(()=>window.testAuth({user:{id:'test-2',email:'other@example.com'}}));
  assert.equal(await page.locator('#greeting').textContent(),'Bonjour.');
  await page.locator('#profile-button').click();assert.doesNotMatch(await page.locator('#profile-fields').textContent(),/Camille/);await page.keyboard.press('Escape');
  for(const width of [320,390,768,1440]){
   await page.setViewportSize({width,height:900});
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`Overflow at ${width}`);
  }
  if(process.env.LILA_SCREENSHOT)await page.screenshot({path:process.env.LILA_SCREENSHOT,fullPage:true});
  await page.evaluate(()=>window.testAuth(null));assert.equal(await page.locator('#cockpit').isVisible(),false);
  assert.deepEqual(errors,[]);
  console.log('PASS: exact Supabase config and assets; auth gate, existing-account OTP, profile isolation, search, dialogs, logout errors, session expiry; 320/390/768/1440px without overflow. Supabase mocked.');
 }finally{await browser.close();server.close();}
})().catch(e=>{console.error(e);server.close();process.exitCode=1;});
