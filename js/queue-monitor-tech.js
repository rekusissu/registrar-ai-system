(function(){
 'use strict';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const by=id=>document.getElementById(id),previous=new Map();let first=true,latest=null,callKey='',listKey='';
 function tick(){const now=new Date();by('mtTime').textContent=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit'}).format(now);by('mtDate').textContent=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',month:'long',day:'numeric',year:'numeric'}).format(now);}tick();setInterval(tick,1000);
 function time(value){if(!value)return '—';const d=new Date(String(value).replace(' ','T')+(/Z$|[+-]\d\d:\d\d$/.test(value)?'':'+08:00'));return isNaN(d.getTime())?'—':new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit'}).format(d);}
 const empty=(icon,text)=>`<div class="mt-empty"><i class="fas fa-${icon}" aria-hidden="true"></i><p>${esc(text)}</p></div>`;
 const table=(head,rows)=>`<table><thead><tr>${head.map(s=>`<th>${esc(s)}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table>`;
 window.MonitorUI={
 offline(){by('mtLive').textContent='Connection interrupted';by('mtLive').className='mt-live offline';by('mtCallMessage').textContent='Showing the last received records. Waiting for an update…';},
 render(data){
  by('mtLive').textContent=data.queue_closed?'Queue closed · monitor live':'Live queue';by('mtLive').className='mt-live online';
  const slots=data.windows||[],strip=by('windowStrip');if(first)strip.innerHTML='';
  slots.forEach(slot=>{
   const serving=slot.serving,key=serving?String(serving.ticket_id||serving.id||serving.display_number)+'|'+String(serving.called_at||''):'';
   const changed=previous.has(slot.window)&&previous.get(slot.window)!==key&&serving;
   if(changed||(!latest&&serving))latest={window:slot.window,number:serving.display_number,key};
   let card=strip.querySelector(`[data-window="${Number(slot.window)}"]`);
   if(!card){card=document.createElement('article');card.dataset.window=Number(slot.window);strip.append(card);card.addEventListener('animationend',event=>{if(event.animationName==='mt-called')card.classList.remove('calling');});}
   const tone=slot.priority_group==='priority'?'priority':slot.txn_type==='claim'?'claim':'student';
   const cardKey=JSON.stringify([key,slot.label,tone]);
   if(card.dataset.content!==cardKey){card.dataset.content=cardKey;card.className='mt-window '+tone+(serving?' busy':' idle')+(changed?' calling':'');card.innerHTML=`<h2>Window ${Number(slot.window)}</h2><p class="mt-lane">${esc(slot.label||((slot.txn_type==='claim'?'Claim':'Service')+' · '+(slot.priority_group==='priority'?'Priority':'Student')))}</p><div class="mt-number">${serving?esc(serving.display_number):'—'}</div><div class="mt-window-state">${serving?'<span></span> Now serving':'<strong>Available</strong><small>Ready for next ticket</small>'}</div>`;}
   previous.set(slot.window,key);
  });
  const dated=slots.filter(s=>s.serving?.called_at).sort((a,b)=>String(b.serving.called_at).localeCompare(String(a.serving.called_at)));
  if(dated[0]) latest={window:dated[0].window,number:dated[0].serving.display_number};
  strip.querySelectorAll('[data-window]').forEach(card=>{const selected=latest&&Number(card.dataset.window)===Number(latest.window);card.classList.toggle('last-called',!!selected);if(card.classList.contains('busy')){const state=card.querySelector('.mt-window-state'),label=selected?'→ Please proceed':'● Now serving';if(state.textContent!==label)state.textContent=label;}});
  if(latest&&!slots.some(s=>Number(s.window)===Number(latest.window)&&s.serving?.display_number===latest.number))latest=null;
  const currentKey=latest?latest.window+'|'+latest.number:'';
  if(currentKey!==callKey||first){callKey=currentKey;by('mtCall').textContent=latest?latest.number+' → Window '+latest.window:'Please watch for your number';}
  by('mtCallMessage').textContent=latest?'Please proceed to the registrar counter.':data.queue_closed?'New numbers are not being issued.':'Approach your window only when called.';
  const waiting=data.waiting||[],recent=(data.recently_served||[]).filter(r=>r.status==='completed');
  const visibleRows=innerHeight<900?3:4;
  const key=JSON.stringify([waiting,recent,visibleRows]);
  if(key!==listKey||first){listKey=key;by('mtWaitingCount').textContent=waiting.length+' waiting';by('waitList').innerHTML=waiting.length?table(['Number','Transaction','Line'],waiting.slice(0,visibleRows).map(w=>`<tr><td class="mt-ticket">${esc(w.number)}</td><td>${w.txn_type==='claim'?'Document claim':'Registrar service'}</td><td><span class="mt-tag ${w.priority_group==='priority'?'priority':'student'}">${w.priority_group==='priority'?'Priority':'Student'}</span></td></tr>`).join('')):empty('users','No one is waiting');by('mtMore').textContent=waiting.length>visibleRows?'+'+(waiting.length-visibleRows)+' more in line':'';by('recentList').innerHTML=recent.length?table(['Number','Window','Time'],recent.slice(0,3).map(r=>`<tr><td class="mt-ticket">${esc(r.number)}</td><td>${r.window>0?'Window '+Number(r.window):'—'}</td><td>${esc(time(r.served_at))}</td></tr>`).join('')):empty('clock','No completed tickets yet');}
  first=false;
 }
 };
})();
