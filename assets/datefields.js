(function(){
var M={jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
function p2(n){return (n<10?'0':'')+n}
function ok(y,m,d){var t=new Date(y,m-1,d);return t.getFullYear()===y&&t.getMonth()===m-1&&t.getDate()===d}
function fmt(y,m,d){if(y<100)y+=2000;return ok(y,m,d)?y+'-'+p2(m)+'-'+p2(d):''}
function parse(s){
s=String(s||'').trim();if(!s)return '';var m;
if((m=s.match(/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/)))return fmt(+m[1],+m[2],+m[3]);
if((m=s.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/)))return fmt(+m[3],+m[1],+m[2]);
if((m=s.match(/([a-z]{3})[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/i))&&M[m[1].toLowerCase()])return fmt(+m[3],M[m[1].toLowerCase()],+m[2]);
if((m=s.match(/(\d{1,2})(?:st|nd|rd|th)?\s+([a-z]{3})[a-z]*\.?,?\s+(\d{4})/i))&&M[m[2].toLowerCase()])return fmt(+m[3],M[m[2].toLowerCase()],+m[1]);
if((m=s.match(/^(\d{4})(\d{2})(\d{2})$/)))return fmt(+m[1],+m[2],+m[3]);
return ''}
function fire(el){['input','change'].forEach(function(t){el.dispatchEvent(new Event(t,{bubbles:true}))})}
function fix(el){var v=el.value.trim();if(!v)return;var d=parse(v);if(d&&d!==el.value){el.value=d;fire(el)}}
function enhance(el){
if(el.dataset.dfDone)return;el.dataset.dfDone='1';
var plain=el.type==='date';
if(!plain&&!el.hasAttribute('data-date'))return;
el.type='text';el.setAttribute('inputmode','numeric');el.setAttribute('autocomplete','off');el.setAttribute('placeholder','YYYY-MM-DD');el.setAttribute('maxlength','40');
el.addEventListener('paste',function(e){var t=(e.clipboardData||window.clipboardData).getData('text');var d=parse(t);if(!d)return;e.preventDefault();el.value=d;fire(el)});
el.addEventListener('blur',function(){fix(el)});
el.addEventListener('keydown',function(e){if(e.key==='Enter')fix(el);if(e.key==='Escape')closeCal()});
var w=document.createElement('span');w.className='spe-dwrap';el.parentNode.insertBefore(w,el);w.appendChild(el);
var b=document.createElement('button');b.type='button';b.className='spe-dbtn';b.setAttribute('aria-label','Open calendar');b.tabIndex=-1;b.textContent='▾';w.appendChild(b);
b.addEventListener('mousedown',function(e){e.preventDefault()});
b.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();if(cal&&cal.owner===el){closeCal();return}openCal(el,w)});
}
var cal=null;
function closeCal(){if(cal){cal.remove();cal=null}}
function openCal(el,w){closeCal();var d=parse(el.value),t=d?new Date(+d.slice(0,4),+d.slice(5,7)-1,1):new Date();t.setDate(1);cal=document.createElement('div');cal.className='spe-cal';cal.owner=el;w.appendChild(cal);
function draw(){var y=t.getFullYear(),m=t.getMonth(),first=new Date(y,m,1).getDay(),n=new Date(y,m+1,0).getDate(),h='<div class="spe-calh"><button type="button" data-n="-1">‹</button><b>'+t.toLocaleString(undefined,{month:'long',year:'numeric'})+'</b><button type="button" data-n="1">›</button></div><div class="spe-calg">';
'SMTWTFS'.split('').forEach(function(c){h+='<i>'+c+'</i>'});for(var i=0;i<first;i++)h+='<span></span>';var sel=parse(el.value),now=new Date(),td=fmt(now.getFullYear(),now.getMonth()+1,now.getDate());
for(var k=1;k<=n;k++){var s2=y+'-'+p2(m+1)+'-'+p2(k);h+='<button type="button" data-d="'+s2+'"'+(s2===sel?' class="on"':s2===td?' class="td"':'')+'>'+k+'</button>'}
cal.innerHTML=h+'</div><div class="spe-calf"><button type="button" data-d="'+td+'">Today</button><button type="button" data-x="1">Close</button></div>'}
draw();
cal.addEventListener('mousedown',function(e){e.preventDefault()});
cal.addEventListener('click',function(e){e.stopPropagation();var b=e.target.closest('button');if(!b)return;if(b.dataset.n){t.setMonth(t.getMonth()+ +b.dataset.n);draw();return}if(b.dataset.x){closeCal();return}if(b.dataset.d){el.value=b.dataset.d;fire(el);closeCal()}})}
document.addEventListener('click',function(e){if(cal&&!cal.contains(e.target))closeCal()});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeCal()});
function run(root){(root||document).querySelectorAll('input[type=date]').forEach(function(el){if(el.closest('.spe-field,.spn-info,#spn-info,.spe-grid,.spe-form,form#spe-form'))enhance(el)})}
run();
})();
