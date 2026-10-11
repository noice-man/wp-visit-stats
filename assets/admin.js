(function(){
 'use strict';
 var data=window.WPVS_CHART, target=document.getElementById('wpvs-chart');
 if(!target || !Array.isArray(data))return;
 var ns='http://www.w3.org/2000/svg';
 function node(tag,attrs,text){var el=document.createElementNS(ns,tag);Object.keys(attrs||{}).forEach(function(k){el.setAttribute(k,attrs[k]);});if(text!==undefined)el.textContent=text;return el;}
 function plot(metric){
   while(target.firstChild)target.removeChild(target.firstChild);
   var w=900,h=270,p=42,right=18,bottom=39,base=h-bottom,left=p,area=w-left-right;
   var svg=node('svg',{viewBox:'0 0 '+w+' '+h,role:'img','aria-label':'Динамика: '+metric});
   var max=Math.max(1,...data.map(function(d){return Number(d[metric])||0;}));
   var scale=Math.ceil(max/4)||1, ceiling=scale*4;
   for(var i=0;i<=4;i++){
     var y=base-i*(base-18)/4;
     svg.appendChild(node('line',{x1:left,y1:y,x2:w-right,y2:y,stroke:'#e2e8f0','stroke-width':1}));
     svg.appendChild(node('text',{x:left-10,y:y+4,'text-anchor':'end',fill:'#667085','font-size':11},String(Math.round(ceiling*i/4))));
   }
   var points=data.map(function(d,i){return [left+(data.length===1?area/2:i*area/(data.length-1)),base-(Number(d[metric])||0)*(base-18)/ceiling,d];});
   if(points.length){var path=points.map(function(c,i){return (i?'L':'M')+c[0].toFixed(1)+' '+c[1].toFixed(1);}).join(' ');
     svg.appendChild(node('path',{d:path,fill:'none',stroke:'#4169e1','stroke-width':3,'stroke-linecap':'round','stroke-linejoin':'round'}));
     points.forEach(function(c,i){var pt=node('circle',{cx:c[0],cy:c[1],r:data.length>100?2.1:3.4,fill:'#4169e1'});
       pt.appendChild(node('title',{},c[2].date+': '+c[2][metric]));svg.appendChild(pt);
       if(i===0 || i===points.length-1 || (i%Math.max(1,Math.ceil(points.length/8))===0)){
         svg.appendChild(node('text',{x:c[0],y:h-12,'text-anchor':'middle',fill:'#667085','font-size':11},c[2].date.slice(5)));
       }
     });
   }
   target.appendChild(svg);
 }
 document.querySelectorAll('[data-wpvs-metric]').forEach(function(btn){btn.addEventListener('click',function(){
  document.querySelectorAll('[data-wpvs-metric]').forEach(function(b){b.classList.toggle('is-active',b===btn);});
  plot(btn.dataset.wpvsMetric);
 });});
 plot('visitors');
}());
