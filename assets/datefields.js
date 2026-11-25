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
el.addEventListener('keydown',function(e){if(e.key==='Enter')fix(el)});
var b=document.createElement('button');b.type='button';b.className='spe-datebtn';b.setAttribute('aria-label','Pick a date');b.textContent='▾';b.tabIndex=-1;
var h=document.createElement('input');h.type='date';h.tabIndex=-1;h.setAttribute('aria-hidden','true');h.className='spe-datehid';
b.addEventListener('click',function(e){e.preventDefault();var c=parse(el.value);h.value=c;try{if(h.showPicker)h.showPicker();else{h.focus();h.click()}}catch(x){h.focus();h.click()}});
h.addEventListener('change',function(){if(h.value){el.value=h.value;fire(el)}});
el.insertAdjacentElement('afterend',b);b.insertAdjacentElement('afterend',h)}
function run(root){(root||document).querySelectorAll('input[type=date]').forEach(function(el){if(el.closest('.spe-field,.spn-info,#spn-info,.spe-grid,.spe-form,form#spe-form'))enhance(el)})}
run();
})();
