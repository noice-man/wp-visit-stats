const assert=require('assert');
const vm=require('vm');
const fs=require('fs');
function run(opts){
 const sent=[]; const calls=[]; const interval=[]; const storage={};
 const ym=function(){calls.push([...arguments]);return 'metrika-response';};
 ym.a=[1];
 const win={WPVS_CONFIG:Object.assign({endpoint:'/wp-admin/admin-ajax.php',postId:10,respectDnt:false,requireConsent:false,goalTracking:true,mirrorMetrika:true},opts||{}),
   fetch:(url,opts)=>{sent.push(new URLSearchParams(opts.body));return Promise.resolve({ok:true});},
   location:{pathname:'/article/',search:''},localStorage:{getItem:(k)=>storage[k]||null,setItem:(k,v)=>{storage[k]=v;}},ym,
   setInterval:(fn)=>{interval.push(fn);return 1;},clearInterval:()=>{}};
 const doc={referrer:'',readyState:'complete'};
 const context={window:win,document:doc,URLSearchParams,navigator:{doNotTrack:'0'},Date,console};
 vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/tracker.js','utf8'),context);
 return {win,sent,calls,interval};
}
let t=run();
assert.strictEqual(t.win.ym(51634436,'reachGoal','zayavka',{email:'private@example.com'}),'metrika-response');
assert.strictEqual(t.calls.length,1);
assert.strictEqual(t.calls[0][3].email,'private@example.com');
let events=t.sent.filter(p=>p.get('action')==='wpvs_goal');
assert.strictEqual(events.length,1);
assert.strictEqual(events[0].get('goal'),'zayavka');
assert.strictEqual(events[0].get('counter_id'),'51634436');
assert.strictEqual(events[0].has('params'),false);
assert.strictEqual(t.win.ym.a[0],1,'ym queue property');
t.win.WPVisitStats.goal('zayavka');
assert.strictEqual(t.sent.filter(p=>p.get('action')==='wpvs_goal').length,1,'dedup same event');
t.win.WPVisitStats.goal('signup');
assert.strictEqual(t.sent.filter(p=>p.get('action')==='wpvs_goal').length,2,'direct goal');
let off=run({mirrorMetrika:false});
off.win.ym(51634436,'reachGoal','purchase');
assert.strictEqual(off.sent.filter(p=>p.get('action')==='wpvs_goal').length,0,'mirror opt-out');
off.win.WPVisitStats.goal('purchase');
assert.strictEqual(off.sent.filter(p=>p.get('action')==='wpvs_goal').length,1,'manual works without mirror');
let privateSite=run({requireConsent:true});
privateSite.win.ym(1,'reachGoal','click');
assert.strictEqual(privateSite.sent.length,0,'consent required');
privateSite.win.WPVisitStats.grantConsent();
privateSite.win.ym(1,'reachGoal','click');
assert.strictEqual(privateSite.sent.filter(p=>p.get('action')==='wpvs_goal').length,1);
console.log('OK: Yandex reachGoal mirroring, no params copied, consent and dedup');
