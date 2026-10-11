// Run: node tests/tracker-smoke.js
const fs=require('fs'),vm=require('vm'),assert=require('node:assert/strict');
const js=fs.readFileSync(__dirname+'/../assets/tracker.js','utf8');
function run(requireConsent,dnt){
  const requests=[],mem=new Map();
  const window={WPVS_CONFIG:{endpoint:'/wp-admin/admin-ajax.php',postId:123,respectDnt:true,requireConsent},
    localStorage:{getItem:k=>mem.get(k),setItem:(k,v)=>mem.set(k,v)},location:{pathname:'/article/',search:'?utm_source=yandex&utm_campaign=autumn'},
    fetch:(url,opts)=>{requests.push({url,opts});return Promise.resolve({ok:true});},addEventListener:(event,callback)=>callback()};
  const context={window,document:{readyState:'complete',referrer:'https://yandex.ru/search?q=test'},navigator:{doNotTrack:dnt?'1':'0'},URLSearchParams};
  vm.runInNewContext(js,context);
  return {requests,api:window.WPVisitStats,mem};
}
let a=run(false,false);assert.equal(a.requests.length,1);assert.match(a.requests[0].opts.body,/utm_source=yandex/);
a.api.grantConsent();assert.equal(a.requests.length,1,'no duplicate after grant');
a.api.denyConsent();assert.equal(a.requests.length,2,'optout request');
let b=run(true,false);assert.equal(b.requests.length,0,'no event before consent');
b.api.grantConsent();assert.equal(b.requests.length,1,'event after consent');
let c=run(false,true);assert.equal(c.requests.length,0,'respect DNT');
console.log('OK: 6 JS tracker checks');
