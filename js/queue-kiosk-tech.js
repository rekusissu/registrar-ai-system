/* Kiosk-only presentation. Queue eligibility and issuance remain server-owned. */
(function(){
 'use strict';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const host=()=>document.getElementById('resultCard');
 const go=tab=>document.querySelector('.q-tab-btn[data-tab="'+tab+'"]').click();
 const facts=data=>Object.entries(data).filter(([,v])=>v!==undefined&&v!==null&&v!=='').map(([k,v])=>`<div><dt>${esc(k)}</dt><dd>${esc(v)}</dd></div>`).join('');
 const clock=()=>{document.getElementById('ktTime').textContent=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit'}).format(new Date());};clock();setInterval(clock,30000);
 window.KioskUI={
  connection(ok){const el=document.getElementById('ktConnection');el.textContent=ok?'Queue service connected':'Queue service unavailable';el.className=ok?'online':'offline';},
  reading(){const el=host();el.className='result-card kt-result';el.dataset.tone='info';el.innerHTML='<div class="kt-reading-rings"><i class="fas fa-id-card" aria-hidden="true"></i></div><h2>Reading your student ID card</h2><p>Please keep your card near the reader.</p>';},
  busy(){const el=host();el.className='result-card kt-result';el.dataset.tone='info';el.innerHTML='<div class="kt-reading-rings"><i class="fas fa-id-card" aria-hidden="true"></i></div><h2>Confirming your queue request</h2><p>Please wait. Your number will appear when confirmed.</p>';document.querySelectorAll('.q-tab-btn[data-tab]').forEach(b=>b.disabled=true);},
  ready(){document.querySelectorAll('.q-tab-btn[data-tab]').forEach(b=>b.disabled=false);},
  result(response){
   const d=response.data||{},code=response.code||'',success=!!response.success;
   let title='',tone='info',icon='circle-info',number='',lane='',detail=response.message||'',notice='',metadata='',action='tap';
   if(success){title=d.re_queued?'A new number has been issued':'You’re in the queue';tone='success';icon='circle-check';number=d.display_number||'';lane=d.lane_label||'';detail=d.re_queued?'You are now at the back of this line.':'Your queue request has been confirmed.';metadata=facts({'Assigned window':d.window,'Position in this line':d.position});notice='Wait for your number to be called before approaching a window.';}
   else if(code==='already_queued'){title='You already have a queue number';tone='warn';icon='hourglass-half';number=d.display_number||'';lane=d.lane_label||'';notice='No additional number was issued. Wait for your number to be called.';}
   else if(code==='now_serving'){title='Your number is being served';tone='success';icon='circle-check';number=d.display_number||'';lane=d.lane_label||'';notice=d.counter||d.window?'Please proceed to Window '+(d.counter||d.window)+'.':'Please check the queue monitor for your serving window.';detail='The registrar is ready to assist you.';}
   else if(code==='cooldown'){title='Please wait before rejoining';tone='warn';icon='clock';number=d.display_number||'';notice='A new number cannot be issued yet. Follow the queue service guidance.';}
   else if(code==='bounce'){title='Card already read';icon='id-card';notice='Remove your card from the reader and wait before tapping again.';}
   else if(code==='queue_closed'){title='The queue is closed';tone='warn';icon='clock';notice='New queue numbers are not being issued. Check the registrar’s posted schedule.';}
   else if(code==='day_full'){title='All queue numbers have been issued';tone='warn';icon='ticket';notice='Please check the registrar’s next available session.';}
   else if(code==='tap_limit'){title='Your daily queue limit is reached';tone='warn';icon='ticket';notice='Please see the registrar if you still need assistance.';}
   else if(code==='bad_lane'){title='Please choose your service again';icon='circle-question';notice='The selected queue option is unavailable. No number was issued for this request.';action='service';}
   else if(code==='denied'){title='Card not accepted';tone='error';icon='id-card';notice='Please ask the registrar to verify your card.';}
   else if(code==='network'){title='Unable to confirm your request';icon='wifi';detail='The kiosk could not confirm the outcome with the queue service.';notice='Check your number before trying again. Your request may have reached the service.';action='standing';this.connection(false);}
   else{title='Unable to join the queue';tone='error';icon='circle-exclamation';notice='Please ask the registrar for assistance.';}
   const el=host();el.className='result-card kt-result';el.dataset.tone=tone;
   el.innerHTML=`<div class="kt-result-icon"><i class="fas fa-${icon}" aria-hidden="true"></i></div><h2>${esc(title)}</h2>${number?`<div class="kt-ticket">${esc(number)}</div>`:''}${lane?`<div class="kt-lane">${esc(lane)}</div>`:''}${metadata?`<dl class="kt-facts">${metadata}</dl>`:''}${detail?`<p>${esc(detail)}</p>`:''}<p class="kt-notice">${esc(notice)}</p><div class="kt-result-actions"><button type="button" data-kt-action="${action}">${action==='standing'?'Check my number':action==='service'?'Back to service selection':'Return to tap screen'}</button></div><small>${code==='network'?'Confirm your ticket before submitting another request.':'This screen will return automatically.'}</small>`;
   this.ready();
  }
 };
 document.addEventListener('click',e=>{const button=e.target.closest('[data-kt-action]');if(!button)return;if(button.dataset.ktAction==='service'&&window.queueKioskRetry){window.queueKioskRetry();return;}go(button.dataset.ktAction==='standing'?'standing':'tap');});
})();
