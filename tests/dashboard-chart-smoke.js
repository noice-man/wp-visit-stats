// Run: node tests/dashboard-chart-smoke.js
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/admin.js'),'utf8');
const php=fs.readFileSync(path.join(__dirname,'../includes/class-wpvs-admin.php'),'utf8');
assert.match(php,/>Сегодня<\/a>/,'Today shortcut must render in the dashboard');
assert.match(php,/wpvs_from.*\$today.*wpvs_to.*\$today/,'Today shortcut must use same-day range');
class Element {
  constructor(tag) {this.tagName=tag;this.children=[];this.attributes={};this.listeners={};this.style={};this.dataset={};this.className='';this.hidden=false;this.firstChild=null;this.textContent='';this.offsetWidth=125;this.classList={toggle:()=>{}};}
  setAttribute(k,v){this.attributes[k]=String(v);}
  appendChild(x){this.children.push(x);this.firstChild=this.children[0];return x;}
  removeChild(x){this.children.splice(this.children.indexOf(x),1);this.firstChild=this.children[0]||null;}
  addEventListener(k,fn){this.listeners[k]=fn;}
  getBoundingClientRect(){return {left:0,top:0,width:900,height:270};}
}
function check(data){
  const target=new Element('div'),buttons=['visitors','sessions','views'].map(key=>{const x=new Element('button');x.dataset.wpvsMetric=key;return x;});
  const doc={getElementById:()=>target,createElementNS:(ns,tag)=>new Element(tag),createElement:(tag)=>new Element(tag),querySelectorAll:()=>buttons};
  const context={window:{WPVS_CHART:data},document:doc};
  vm.runInNewContext(source,context);
  const svg=target.children[0],tip=target.children[1];
  assert.equal(tip.hidden,true);
  assert.equal(svg.tagName,'svg');
  svg.listeners.pointermove({clientX:450,clientY:130});
  assert.equal(tip.hidden,false);
  assert.equal(tip.children[0].textContent,data.length===1?'12.10.2026':'11.10.2026');
  assert.match(tip.children[2].textContent,/^[0-9]/);
  svg.listeners.pointerleave();
  assert.equal(tip.hidden,true);
  svg.listeners.focus();
  assert.equal(tip.hidden,false);
  let prevented=false;
  svg.listeners.keydown({key:'Home',preventDefault:()=>prevented=true});
  assert.equal(prevented,true);
  svg.listeners.blur();
  assert.equal(tip.hidden,true);
  buttons[2].listeners.click();
  assert.equal(target.children[0].attributes['aria-label'].includes('Просмотры'),true);
}
check([{date:'2026-10-12',visitors:7,sessions:8,views:12}]);
check([{date:'2026-10-11',visitors:1,sessions:2,views:3},{date:'2026-10-12',visitors:4,sessions:5,views:6}]);
console.log('OK: Today preset, single-day and multi-day tooltips, keyboard, metric switching');
