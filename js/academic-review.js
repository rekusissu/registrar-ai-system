'use strict';
const AR = JSON.parse(document.getElementById('arData').textContent);
let arSelected = null;
const arState = gate => gate.state === 'accepted' ? 'accepted' : (gate.can_accept ? 'ready' : 'waiting');
const arLabel = state => ({accepted:'Accepted',ready:'Ready for review',waiting:'Needs attention'}[state]);
function arRender() {
 const program=document.getElementById('arProgram').value, status=document.getElementById('arStatus').value, query=document.getElementById('arSearch').value.trim().toLowerCase();
 const gates=AR.filter(g=>(!program||g.program===program)&&(!status||arState(g)===status)&&(`${g.program} ${g.code} ${g.label}`.toLowerCase().includes(query)));
 document.getElementById('arSummary').innerHTML=`<span>${gates.length} sections</span><span class="ar-ready">${gates.filter(g=>arState(g)==='ready').length} ready for review</span><span class="ar-waiting">${gates.filter(g=>arState(g)==='waiting').length} need attention</span><span class="ar-accepted">${gates.filter(g=>arState(g)==='accepted').length} accepted</span>`;
 document.getElementById('arRows').innerHTML=gates.map(g=>{const i=AR.indexOf(g),state=arState(g),received=g.students.filter(s=>s.record).length;return `<tr class="${arSelected===i?'ar-selected':''}" data-ar-row="${i}"><td><strong>${esc(g.program)}</strong><small>Year ${esc(g.year||'—')} · Section ${esc(g.code)}</small></td><td><strong>${received} of ${g.roster} students</strong><div class="ar-meter"><i style="width:${g.roster?received/g.roster*100:0}%"></i></div></td><td>${g.blocking.length?`<span class="ar-waiting">${g.blocking.length} blocking issue${g.blocking.length===1?'':'s'}</span>`:'No blocking issues'}${g.advisory.length?`<small>${g.advisory.length} advisories</small>`:''}</td><td><span class="aw-badge ${state==='accepted'?'green':state==='waiting'?'amber':''}">${arLabel(state)}</span></td><td><button class="aw-view" type="button" data-ar-review="${i}">${state==='accepted'?'View':'Review'}</button></td></tr>`;}).join('');
 document.getElementById('arEmpty').hidden=gates.length>0;
 if(arSelected!==null&&!gates.includes(AR[arSelected])){arSelected=null;document.getElementById('arDetail').hidden=true;}
}
function arDetail(index) {
 arSelected=index;arRender();const g=AR[index],state=arState(g),panel=document.getElementById('arDetail');
 const findings=g.blocking.map(f=>`<div class="ar-check issue"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><div><strong>${esc(f.title)}</strong><p>${esc(f.detail)}</p></div></div>`).join('');
 const advisories=g.advisory.map(f=>`<div class="ar-check"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><div><strong>${esc(f.title)}</strong><p>${esc(f.detail)}</p></div></div>`).join('');
 panel.innerHTML=`<div class="ar-detail-head"><h2 id="arDetailTitle">${esc(g.label)} · Year ${esc(g.year||'—')} · Section ${esc(g.code)}</h2><span class="aw-badge ${state==='accepted'?'green':state==='waiting'?'amber':''}">${arLabel(state)}</span></div><div class="ar-detail-body"><div><h3>Record checks</h3>${findings||'<div class="ar-check"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>No blocking issues found by the term audit.</div>'}${advisories}</div><div><dl class="ar-dl"><div><dt>Academic period</dt><dd>${esc(AH.sem)} Semester · ${esc(AH.sy)}</dd></div><div><dt>Students</dt><dd>${g.roster}</dd></div><div><dt>Records received</dt><dd>${g.students.filter(s=>s.record).length}</dd></div><div><dt>Accepted records</dt><dd>${g.stamped} of ${g.roster}</dd></div></dl></div></div><div class="ar-detail-footer"><small>Acceptance is recorded under your account.</small><div><button type="button" class="btn btn-light" id="arViewStudents">View student records</button><button type="button" class="btn btn-primary" id="arAccept" ${state!=='accepted'&&!g.can_accept?'disabled':''}>${state==='accepted'?'Reopen term records':'Accept term records'}</button></div></div><p id="arMessage" role="status" class="ar-footnote"></p><div class="ar-students" id="arStudents" hidden>${g.students.map(s=>`<button class="aw-view ah-view" type="button" data-view="${s.id}">${esc(s.name)} →</button>`).join('')}</div>`;
 panel.hidden=false;
 if(AH.readOnly) document.getElementById('arAccept').hidden=true;
 document.getElementById('arViewStudents').onclick=()=>{const list=document.getElementById('arStudents');list.hidden=!list.hidden;};
 document.getElementById('arAccept').onclick=()=>arSubmit(index);
}
async function arSubmit(index){
 if(AH.readOnly)return;
 const g=AR[index],button=document.getElementById('arAccept'),message=document.getElementById('arMessage'),action=arState(g)==='accepted'?'reopen':'accept';
 button.disabled=true;message.textContent=action==='accept'?'Verifying current records…':'Reopening records…';
 try{const result=await ahb.call(action,g.code,g.program);if(!result)throw Error('Another review action is still running.');if(!result.ok)throw Error(result.data.message||'The records could not be updated.');
 // Reload the canonical records after a write; grades, student badges and audit must agree.
 const url=new URL(location.href);url.searchParams.set('view','review');location.assign(url.href);
 }catch(error){message.textContent=error.message;button.disabled=false;}
}
['arProgram','arStatus','arSearch'].forEach(id=>document.getElementById(id).addEventListener(id==='arSearch'?'input':'change',arRender));
document.addEventListener('click',event=>{const button=event.target.closest('[data-ar-review]');if(button)arDetail(Number(button.dataset.arReview));});
arRender();
if(new URLSearchParams(location.search).get('view')==='review')document.querySelector('.aw-tabs [data-workspace-tab="review"]').click();

let apStudent=null,apPreviousFocus=null;
function apTerms(){return document.querySelector('[name="apDocument"]:checked').value==='all'?apStudent.career:apStudent.career.filter(t=>t.school_year===document.getElementById('apYear').value&&t.semester===document.getElementById('apSemester').value);}
function apSemesters(preferred){const year=document.getElementById('apYear').value,select=document.getElementById('apSemester');select.innerHTML=apStudent.career.filter(t=>t.school_year===year).map(t=>`<option value="${esc(t.semester)}">${esc(t.semester)} Semester</option>`).join('');if([...select.options].some(o=>o.value===preferred))select.value=preferred;apSummary();}
function apSummary(){
 const all=document.querySelector('[name="apDocument"]:checked').value==='all',terms=apTerms(),subjects=terms.flatMap(t=>apStudent.careerSubjects[t.school_year+'|'+t.semester]||[]),units=terms.reduce((sum,t)=>sum+Number(t.units_live||0),0),demo=terms.some(t=>t.source_system==='demo'),unaccepted=terms.some(t=>!t.accepted_at);
 ['apYear','apSemester'].forEach(id=>document.getElementById(id).disabled=all);
 document.getElementById('apScope').textContent=all?'All recorded school years and semesters will be included.':'Only this school year and semester will be included.';
 const values=[['Student',apStudent.name],['Document',all?'Complete academic history':'Semester grade record'],['Period',all?`${terms.length} recorded terms`:(terms[0]?`${terms[0].semester} Semester ${terms[0].school_year}`:'No recorded term')],['Subjects',subjects.length],['Included units',awNumber(units)],[all?'Cumulative GWA':'Term GWA',awNumber(all?apStudent.careerGwa:terms[0]?.gwa_live)]];
 document.getElementById('apSummary').innerHTML=values.map(([label,value])=>`<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`).join('');
 document.getElementById('apWarning').textContent=Number(apStudent.level)>=11?'SHS source percentage grades. College GWA is not applicable. This is a record preview, not an official SHS certification.':demo?'Demo record. Preview only. A sample watermark will appear on the document.':unaccepted?'Unaccepted records are included. This is a preview, not an officially issued academic document.':'Print preview of the selected academic records. Follow the registrar’s issuance procedure for official release.';
 document.getElementById('apPrint').disabled=!subjects.length;
}
openPrintMenu=function(studentId){
 const r=rowById(Number(studentId));if(!r)return;apStudent=r;apPreviousFocus=document.activeElement;
 document.getElementById('apIdentity').innerHTML=`<div class="aw-person"><span class="aw-avatar">${esc(AH.initials[r.id]||'?')}</span><div><strong>${esc(r.name)}</strong><small>${esc(r.number)}</small></div></div><p>${esc(r.program)}</p>`;
 document.querySelector('[name="apDocument"][value="term"]').checked=true;
 const year=document.getElementById('apYear');year.innerHTML=[...new Set(r.career.map(t=>t.school_year))].map(y=>`<option>${esc(y)}</option>`).join('');const selected=awStudent?.id===r.id&&awTerm?awTerm:r.career.find(t=>t.school_year===AH.sy&&t.semester===AH.sem)||r.career[0];if(selected)year.value=selected.school_year;apSemesters(selected?.semester);
 const modal=document.getElementById('printModal');modal.hidden=false;modal.classList.add('active');document.body.classList.add('aw-modal-open');document.getElementById('printModalTitle').focus();
};
closePrintMenu=function(){const modal=document.getElementById('printModal');modal.hidden=true;modal.classList.remove('active');if(document.getElementById('recordSheet').hidden)document.body.classList.remove('aw-modal-open');apPreviousFocus?.focus();};
document.getElementById('apYear').addEventListener('change',()=>apSemesters());document.getElementById('apSemester').addEventListener('change',apSummary);document.querySelectorAll('[name="apDocument"]').forEach(r=>r.addEventListener('change',apSummary));
document.getElementById('printModal').addEventListener('click',event=>{if(event.target.closest('[data-ap-close]')||event.target.id==='printModal')closePrintMenu();});
document.getElementById('apPrint').addEventListener('click',()=>{const terms=apTerms();if(!terms.length)return;const all=document.querySelector('[name="apDocument"]:checked').value==='all';printOfficial(apStudent.id,all?'all':'selected:'+terms[0].school_year+'|'+terms[0].semester);});
document.getElementById('printModal').addEventListener('keydown',event=>{if(event.key!=='Tab')return;const nodes=[...event.currentTarget.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled])')].filter(n=>n.getClientRects().length),first=nodes[0],last=nodes[nodes.length-1];if(event.shiftKey&&(document.activeElement===first||document.activeElement.id==='printModalTitle')){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}});
