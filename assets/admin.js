(function(){
 'use strict';
 var data=window.WPVS_CHART, target=document.getElementById('wpvs-chart');
 if(!target || !Array.isArray(data))return;
 var ns='http://www.w3.org/2000/svg';
 var labels={visitors:'Посетители',sessions:'Сеансы',views:'Просмотры'};
 function node(tag,attrs,text){
   var el=document.createElementNS(ns,tag);
   Object.keys(attrs||{}).forEach(function(k){el.setAttribute(k,attrs[k]);});
   if(text!==undefined)el.textContent=text;
   return el;
 }
 function el(tag,className){
   var element=document.createElement(tag);
   element.className=className;
   return element;
 }
 function prettyDate(iso){return iso.slice(8,10)+'.'+iso.slice(5,7)+'.'+iso.slice(0,4);}
 function plot(metric){
   while(target.firstChild)target.removeChild(target.firstChild);
   var w=900,h=270,left=42,right=18,bottom=39,base=h-bottom,area=w-left-right;
   var svg=node('svg',{viewBox:'0 0 '+w+' '+h,role:'group',tabindex:'0','aria-label':'Динамика: '+labels[metric]+'. Используйте стрелки влево и вправо для просмотра точек.'});
   var max=Math.max(1,...data.map(function(d){return Number(d[metric])||0;}));
   var scale=Math.ceil(max/4)||1, ceiling=scale*4;
   for(var i=0;i<=4;i++){
     var y=base-i*(base-18)/4;
     svg.appendChild(node('line',{x1:left,y1:y,x2:w-right,y2:y,stroke:'#e2e8f0','stroke-width':1}));
     svg.appendChild(node('text',{x:left-10,y:y+4,'text-anchor':'end',fill:'#667085','font-size':11},String(Math.round(ceiling*i/4))));
   }
   var points=data.map(function(d,i){
     return [left+(data.length===1?area/2:i*area/(data.length-1)),base-(Number(d[metric])||0)*(base-18)/ceiling,d];
   });
   var cross=node('line',{x1:0,y1:18,x2:0,y2:base,stroke:'#94a3b8','stroke-width':1,'stroke-dasharray':'4 4',visibility:'hidden','pointer-events':'none'});
   svg.appendChild(cross);
   if(points.length){
     var path=points.map(function(c,i){return (i?'L':'M')+c[0].toFixed(1)+' '+c[1].toFixed(1);}).join(' ');
     svg.appendChild(node('path',{d:path,fill:'none',stroke:'#4169e1','stroke-width':3,'stroke-linecap':'round','stroke-linejoin':'round','pointer-events':'none'}));
     points.forEach(function(c,i){
       svg.appendChild(node('circle',{cx:c[0],cy:c[1],r:data.length>100?2.1:(data.length===1?5:3.4),fill:'#4169e1','pointer-events':'none'}));
       if(i===0 || i===points.length-1 || (i%Math.max(1,Math.ceil(points.length/8))===0)){
         svg.appendChild(node('text',{x:c[0],y:h-12,'text-anchor':'middle',fill:'#667085','font-size':11,'pointer-events':'none'},c[2].date.slice(5)));
       }
     });
   }
   var active=node('circle',{cx:0,cy:0,r:6,fill:'#fff',stroke:'#4169e1','stroke-width':3,visibility:'hidden','pointer-events':'none'});
   svg.appendChild(active);
   target.appendChild(svg);
   var tooltip=el('div','wpvs-chart-tooltip');
   tooltip.setAttribute('role','tooltip');
   tooltip.hidden=true;
   var dateLine=el('span','wpvs-chart-tooltip-date');
   var metricLine=el('span','wpvs-chart-tooltip-metric');
   var valueLine=el('strong','wpvs-chart-tooltip-value');
   tooltip.appendChild(dateLine);tooltip.appendChild(metricLine);tooltip.appendChild(valueLine);
   target.appendChild(tooltip);
   var selected=-1;
   function hide(){tooltip.hidden=true;cross.setAttribute('visibility','hidden');active.setAttribute('visibility','hidden');selected=-1;}
   function show(index){
     if(!points.length)return;
     selected=Math.max(0,Math.min(points.length-1,index));
     var c=points[selected],d=c[2],amount=Number(d[metric])||0;
     dateLine.textContent=prettyDate(d.date);
     metricLine.textContent=labels[metric];
     valueLine.textContent=amount.toLocaleString('ru-RU');
     cross.setAttribute('x1',c[0]);cross.setAttribute('x2',c[0]);cross.setAttribute('visibility','visible');
     active.setAttribute('cx',c[0]);active.setAttribute('cy',c[1]);active.setAttribute('visibility','visible');
     tooltip.hidden=false;
     var bounds=svg.getBoundingClientRect();
     var half=tooltip.offsetWidth/2, center=c[0]*bounds.width/w;
     var minX=Math.min(bounds.width/2,half+8),maxX=Math.max(bounds.width/2,bounds.width-half-8);
     tooltip.style.left=Math.max(minX,Math.min(maxX,center))+'px';
     var y=c[1]*bounds.height/h, below=y<70;
     tooltip.classList.toggle('is-below',below);
     tooltip.style.top=(y+(below?14:-12))+'px';
     svg.setAttribute('aria-label',labels[metric]+': '+prettyDate(d.date)+', '+amount.toLocaleString('ru-RU')+'. Стрелки влево и вправо для перехода к соседним датам.');
   }
   svg.addEventListener('pointermove',function(event){
     var r=svg.getBoundingClientRect();
     if(!r.width || !points.length)return;
     var x=(event.clientX-r.left)*w/r.width,y=(event.clientY-r.top)*h/r.height;
     if(x<left-12 || x>w-right+12 || y<4 || y>base+12){hide();return;}
     var index=points.length===1?0:Math.round((x-left)/area*(points.length-1));
     show(index);
   });
   svg.addEventListener('pointerleave',hide);
   svg.addEventListener('focus',function(){show(selected>=0?selected:points.length-1);});
   svg.addEventListener('blur',hide);
   svg.addEventListener('keydown',function(event){
     if(['ArrowLeft','ArrowRight','Home','End'].indexOf(event.key)===-1)return;
     event.preventDefault();
     if(event.key==='Home')show(0);
     else if(event.key==='End')show(points.length-1);
     else show((selected<0?points.length-1:selected)+(event.key==='ArrowLeft'?-1:1));
   });
 }
 document.querySelectorAll('[data-wpvs-metric]').forEach(function(btn){
   btn.addEventListener('click',function(){
     document.querySelectorAll('[data-wpvs-metric]').forEach(function(b){b.classList.toggle('is-active',b===btn);});
     plot(btn.dataset.wpvsMetric);
   });
 });
 plot('visitors');
}());
