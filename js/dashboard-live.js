/* Read-only DB refresh; chart and tables always share one snapshot. */
(() => {
 'use strict';
 const $ = id => document.getElementById(id);
 const number = value => new Intl.NumberFormat('en-PH').format(Number(value)||0);
 const colors = ['#3969dc','#239d86','#8870cc','#38a6bc','#cb795d'];
 const el = (tag,cls,text) => { const n=document.createElement(tag); if(cls)n.className=cls; if(text!==undefined)n.textContent=text; return n; };
 let hasSnapshot=false, busy=false, timer, authFailed=false;
 function chart(rows) {
  const root=$('registrationChart'); root.replaceChildren();
  const NS='http://www.w3.org/2000/svg'; const svg=document.createElementNS(NS,'svg');
  svg.setAttribute('viewBox','0 0 660 220');svg.setAttribute('role','img');
  svg.setAttribute('aria-label',rows.map(r=>r.month+': '+number(r.count)+' new records').join('; '));
  const node=(tag,attrs,text)=>{const n=document.createElementNS(NS,tag);Object.entries(attrs).forEach(([k,v])=>n.setAttribute(k,v));if(text!==undefined)n.textContent=text;svg.append(n);return n;};
  const peak=Math.max(...rows.map(r=>r.count),1);const max=Math.max(4,Math.ceil(peak/4)*4);
  for(let i=0;i<=4;i++){const y=20+i*41;node('line',{x1:42,y1:y,x2:642,y2:y,stroke:'#edf1f7','stroke-dasharray':'4 4'});node('text',{x:30,y:y+4,'text-anchor':'end',fill:'#74829a','font-size':10},number(max-i*max/4));}
  const pts=rows.map((r,i)=>[42+i*120,184-r.count/max*164]);
  const defs=node('defs',{});const grad=document.createElementNS(NS,'linearGradient');grad.id='trendFill';grad.setAttribute('x1','0');grad.setAttribute('y1','0');grad.setAttribute('x2','0');grad.setAttribute('y2','1');for(const [offset,opacity]of [['0%','.2'],['100%','.01']]){const stop=document.createElementNS(NS,'stop');stop.setAttribute('offset',offset);stop.setAttribute('stop-color','#3969dc');stop.setAttribute('stop-opacity',opacity);grad.append(stop);}defs.append(grad);
  node('path',{d:'M'+pts.map(p=>p.join(',')).join(' L')+' L642,184 L42,184 Z',fill:'url(#trendFill)'});
  node('path',{d:'M'+pts.map(p=>p.join(',')).join(' L'),stroke:'#3969dc','stroke-width':2.5,fill:'none','stroke-linejoin':'round'});
  rows.forEach((r,i)=>{const circle=node('circle',{cx:pts[i][0],cy:pts[i][1],r:4,fill:'#fff',stroke:'#3969dc','stroke-width':2});const title=document.createElementNS(NS,'title');title.textContent=r.month+': '+number(r.count)+' records';circle.append(title);node('text',{x:pts[i][0],y:211,'text-anchor':'middle',fill:'#74829a','font-size':10},r.label);});
  root.append(svg);const total=rows.reduce((a,r)=>a+r.count,0);$('trendTotal').textContent=number(total);
  if(!total)root.append(el('p','ov-chart-empty','No new student records in the last six months.'));
 }
 const typeNames={transcript:'Transcript of records',good_moral:'Good moral certificate',form137:'Form 137',certificate:'Certificate',clearance:'Clearance',diploma:'Diploma',ctc:'Certified true copy',honorable_dismissal:'Honorable dismissal',course_description:'Course description'};
 function render(data) {
  document.querySelectorAll('[data-stat]').forEach(n=>n.textContent=number(data.stats[n.dataset.stat]));
  chart(data.trend);
  const statuses=$('studentStatuses');statuses.replaceChildren();
  data.statuses.forEach((r,i)=>{const box=el('div');box.append(el('span','ov-status-label',r.label),el('strong','ov-status-number',number(r.count)));const bar=el('div','ov-bar'),fill=el('span');const pct=data.stats.students?r.count/data.stats.students*100:0;fill.style.width=pct+'%';fill.style.background=colors[i%colors.length];bar.append(fill);box.append(bar,el('span','ov-status-pct',Math.round(pct)+'% of records'));statuses.append(box);});
  const courses=$('courseDistribution');courses.replaceChildren();
  data.courses.forEach((r,i)=>{const box=el('div','ov-course');const top=el('div','ov-course-top');top.append(el('span','',r.label),el('strong','',number(r.count)));const bar=el('div','ov-bar'),fill=el('span');fill.style.width=(data.stats.students?r.count/data.stats.students*100:0)+'%';fill.style.background=colors[i%colors.length];bar.append(fill);box.append(top,bar);courses.append(box);});
  if(!data.courses.length)courses.append(el('p','ov-empty','No student records yet.'));
  const recent=$('recentRequests');recent.replaceChildren();
  data.recent.forEach(r=>{const tr=el('tr');const td=el('td'),person=el('div','ov-student'),name=el('div');const student=r.student_name||'Student record unavailable';name.append(el('strong','',student),el('small','',r.student_number||r.request_id||'Request #'+r.id));person.append(el('span','ov-student-avatar',student.trim().split(/\s+/).slice(0,2).map(s=>s[0]).join('').toUpperCase()),name);td.append(person);tr.append(td,el('td','',typeNames[r.document_type]||r.document_type||'Not specified'));const state=el('td'),badge=el('span','ov-badge',r.document_status.replace(/_/g,' '));badge.dataset.state=r.document_status;state.append(badge);tr.append(state);const date=el('td','',r.request_date?new Date(r.request_date.replace(' ','T')).toLocaleDateString('en-PH',{month:'short',day:'numeric'}):'—');tr.append(date);recent.append(tr);});
  if(!data.recent.length){const tr=el('tr'),td=el('td','ov-empty','No document requests yet.');td.colSpan=4;tr.append(td);recent.append(tr);}
  const date=new Date(data.updated_at);$('syncStatus').textContent='Updated '+date.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit',timeZone:'Asia/Manila'});
  $('dashboardError').hidden=true;hasSnapshot=true;
 }
 function error(message) {const n=$('dashboardError');n.textContent=message;n.hidden=false;$('syncStatus').textContent=hasSnapshot?'Refresh paused · showing last snapshot':'Database unavailable';if(!hasSnapshot){$('recentRequests').replaceChildren();const row=el('tr'),cell=el('td','ov-empty','Requests unavailable. Please retry.');cell.colSpan=4;row.append(cell);$('recentRequests').append(row);$('registrationChart').textContent='Chart unavailable. Retrying automatically.';}}
 async function refresh(){
  if(busy||authFailed||document.hidden)return;busy=true;if($('refreshDashboard'))$('refreshDashboard').disabled=true;
  const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),12000);
  try{const response=await fetch(new URL('dashboard.php',new URL(document.body.dataset.apiBase,location.href)),{cache:'no-store',credentials:'same-origin',signal:controller.signal});if(response.status===401||response.status===403){authFailed=true;clearInterval(timer);error('Your session ended or access changed. Sign in again to refresh dashboard data.');return;}const result=await response.json();if(!response.ok||!result.success||!result.data)throw Error('Dashboard request failed');render(result.data);}
  catch(e){error('Could not refresh dashboard data. '+(hasSnapshot?'The last successful snapshot is still shown. ':'')+'Updates will retry automatically.');}
  finally{clearTimeout(timeout);busy=false;if($('refreshDashboard'))$('refreshDashboard').disabled=false;}
 }
 try{const data=JSON.parse($('dashboardSnapshot').textContent);if(data)render(data);else error('Database snapshot unavailable. Retrying…');}catch(e){error('Database snapshot unavailable. Retrying…');}
 $('refreshDashboard')?.addEventListener('click',()=>{if(authFailed){location.href='login.php';return;}refresh();});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
 timer=setInterval(refresh,30000);if(!hasSnapshot)refresh();
})();
