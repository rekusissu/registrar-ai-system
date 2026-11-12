(function(){
 'use strict';
 const config=window.ReportExportConfig;if(!config?.enabled)return;
 const api=config.root+'api/report-exports.php',nativeFetch=window.fetch.bind(window),nativeOpen=window.open.bind(window),nativePrint=window.print.bind(window),nativeRevoke=URL.revokeObjectURL.bind(URL);
 const pendingBlobs=new Set(),deferredRevoke=new Set();let executing=false,asking=false;
 const nativeAnchorClick=HTMLAnchorElement.prototype.click;
 HTMLAnchorElement.prototype.click=function(){
  if(executing||!this.hasAttribute('download'))return nativeAnchorClick.call(this);
  const anchor=this,url=anchor.href,name=anchor.download||'Report download';if(url.startsWith('blob:'))pendingBlobs.add(url);
  return run({report:name,format:name.includes('.')?name.split('.').pop():'file',resource:'client:'+location.pathname+':'+name},()=>nativeAnchorClick.call(anchor)).finally(()=>{pendingBlobs.delete(url);if(deferredRevoke.delete(url))setTimeout(()=>nativeRevoke(url),1000);});
 };
 URL.revokeObjectURL=function(url){if(pendingBlobs.has(url))deferredRevoke.add(url);else nativeRevoke(url);};
 const target=url=>{try{const u=new URL(url,location.href);if(u.origin!==location.origin)return null;const name=u.pathname.split('/').pop();return ['file-download.php','student-template.php','generate-document-pdf.php'].includes(name)||name==='masterlist-folders.php'&&u.searchParams.get('action')==='export'||name==='document-preview.php'&&u.searchParams.has('print')?u:null;}catch{return null;}};
 async function request(action,body){const response=await nativeFetch(api+'?action='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},body:JSON.stringify(body)});const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||'Export could not be authorized.');return data;}
 function confirm(meta){
  if(asking)return Promise.resolve(null);asking=true;
  return new Promise(resolve=>{
   const previous=document.activeElement,overlay=document.createElement('div');overlay.className='ex-overlay';overlay.innerHTML='<form class="ex-dialog" role="dialog" aria-modal="true" aria-labelledby="exTitle"><h2 id="exTitle">Confirm report export</h2><p>Enter your own account password to continue. This export and your account details will be recorded for transparency.</p><div class="ex-report"></div><label>Account password<input type="password" name="export_password" autocomplete="current-password" required maxlength="512"></label><p class="ex-error" role="alert"></p><div class="ex-buttons"><button type="button" data-ex-cancel>Cancel</button><button type="submit">Confirm export</button></div></form>';
   overlay.querySelector('.ex-report').textContent=meta.report||'Report';document.body.append(overlay);
   const form=overlay.querySelector('form'),input=overlay.querySelector('input'),submit=overlay.querySelector('[type=submit]'),cancel=overlay.querySelector('[data-ex-cancel]');let busy=false;
   const finish=value=>{input.value='';overlay.remove();asking=false;previous?.focus();resolve(value);};
   cancel.onclick=()=>{if(!busy)finish(null);};
   overlay.addEventListener('keydown',e=>{e.stopPropagation();if(e.key==='Escape'&&!busy){e.preventDefault();finish(null);}if(e.key==='Tab'){const nodes=[input,cancel,submit];if(e.shiftKey&&document.activeElement===input){e.preventDefault();submit.focus();}else if(!e.shiftKey&&document.activeElement===submit){e.preventDefault();input.focus();}}});
   form.addEventListener('submit',async e=>{e.preventDefault();if(busy)return;busy=true;submit.disabled=cancel.disabled=true;submit.textContent='Verifying…';try{const result=await request('authorize',{...meta,password:input.value});finish(result);}catch(error){input.value='';overlay.querySelector('.ex-error').textContent=error.message;input.focus();}finally{busy=false;submit.disabled=cancel.disabled=false;submit.textContent='Confirm export';}});input.focus();
  });
 }
 async function run(meta,operation){const grant=await confirm(meta);if(!grant)return false;try{await request('consume',{token:grant.token,resource:grant.resource});executing=true;try{return await operation();}finally{executing=false;}}catch(error){alert(error.message);return false;}}
 async function serverUrl(url,meta={}){const u=target(url);if(!u)return url;u.searchParams.delete('export_grant');const grant=await confirm({report:meta.report||'Student document / report',format:meta.format||'file',server_url:u.pathname+u.search});if(!grant)return null;u.searchParams.set('export_grant',grant.token);return u.href;}
 const bypass=new WeakSet();
 document.addEventListener('click',event=>{
  const el=event.target.closest('a,button');if(!el)return;
  if(asking&&!el.closest('.ex-overlay')){event.preventDefault();event.stopImmediatePropagation();return;}
  if(bypass.has(el)){bypass.delete(el);return;}
  const href=el.tagName==='A'?el.getAttribute('href'):null,server=href&&target(href);
  if(server){event.preventDefault();event.stopImmediatePropagation();serverUrl(server.href,{report:el.textContent.trim()||'Student document download'}).then(url=>{if(!url)return;const a=document.createElement('a');a.href=url;a.target=el.target||'_self';a.rel='noreferrer';bypass.add(a);document.body.append(a);a.click();a.remove();});return;}
  if(executing)return;
  const handler=el.getAttribute('onclick')||'',clientDownload=el.tagName==='A'&&el.hasAttribute('download');
  const gated=clientDownload||/\bexport(?:CSV|Excel|Filtered|Students|SelectedCSV)\s*\(|\b(?:printRows|printSelected|printTable|printCard)\s*\(/i.test(handler)||el.id==='exportTxt';
  if(!gated)return;
  event.preventDefault();event.stopImmediatePropagation();
  const name=el.download||el.textContent.trim()||'Report export',blob=clientDownload&&el.href.startsWith('blob:')?el.href:null;if(blob)pendingBlobs.add(blob);
  run({report:name,format:clientDownload?(name.split('.').pop()||'file'):'report',resource:'client:'+location.pathname+':'+name},()=>{bypass.add(el);el.click();}).finally(()=>{if(blob){pendingBlobs.delete(blob);if(deferredRevoke.delete(blob))setTimeout(()=>nativeRevoke(blob),1000);}});
 },true);
 window.print=function(){if(executing)return nativePrint();return run({report:document.title,format:'print-pdf',resource:'client-print:'+location.pathname},()=>nativePrint());};
 window.open=function(url,...args){if(url&&target(url)){const popup=nativeOpen('',...args);if(!popup)return null;popup.document.body.textContent='Waiting for password confirmation…';serverUrl(url).then(approved=>{if(approved)popup.location.replace(approved);else popup.close();}).catch(()=>popup.close());return popup;}return nativeOpen(url,...args);};
 window.fetch=async function(input,init){const url=typeof input==='string'?input:input instanceof URL?input.href:input.url;if(target(url)){const approved=await serverUrl(url);if(!approved)throw new DOMException('Export cancelled','AbortError');return nativeFetch(approved,init);}return nativeFetch(input,init);};
 window.ExportSecurity={run,serverUrl,isExecuting:()=>executing};
})();
