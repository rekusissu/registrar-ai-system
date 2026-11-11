/* Three-stage inventory registration, preserving editable rows and review findings. */
(() => {
'use strict';
const modal=document.getElementById('registerModal'),editor=document.getElementById('bulkUidRows'),notes=document.getElementById('registerNotes');
const el=id=>document.getElementById(id),MAX=1000;
let rows=[],rowSequence=0,stage=1,busy=false,preview=null,result=null,submitted=[],knownExisting=new Set(),currentIssue=null,returnFocus=null,inventoryChanged=false;
const makeRow=value=>({id:++rowSequence,value});
function showError(message){el('bulkError').textContent=message;el('bulkError').hidden=!message;}
function entries(){return rows.map((row,index)=>({...row,physicalRow:index+1,uid:row.value.trim()})).filter(row=>row.uid!=='');}
function classify(){const seen=new Map();return entries().map(row=>{if(!/^\d{10}$/.test(row.uid))return {...row,status:'invalid',message:'Must be exactly 10 digits'};if(seen.has(row.uid))return {...row,status:'duplicate',message:'Repeats row '+seen.get(row.uid)};seen.set(row.uid,row.physicalRow);if(knownExisting.has(row.uid))return {...row,status:'existing',message:'Already in inventory'};return {...row,status:'ready',message:''};});}
function issueList(){return classify().filter(row=>row.status!=='ready');}
function setBusy(value){busy=value;modal.setAttribute('aria-busy',String(value));modal.querySelectorAll('input,button').forEach(control=>control.disabled=value);if(!value)updateActions();}
function updateActions(){el('registerPreviewBtn').disabled=busy||!entries().length||entries().length>MAX;el('registerProceedBtn').disabled=busy||!preview||!preview.new_uids.length;el('registerPreviewBtn').innerHTML=busy&&stage===1?'<i class="fas fa-spinner fa-spin"></i> Checking…':'Review batch <i class="fas fa-arrow-right"></i>';if(preview)el('registerProceedBtn').innerHTML=busy&&stage===2?'<i class="fas fa-spinner fa-spin"></i> Registering…':preview.new_uids.length?'Register '+preview.new_uids.length+' ready card'+(preview.new_uids.length===1?'':'s')+' <i class="fas fa-arrow-right"></i>':'No new cards to register';}
function addText(parent,tag,text,className){const node=document.createElement(tag);node.textContent=text;if(className)node.className=className;parent.appendChild(node);return node;}
function ensureTail(){
    if(rows.at(-1)?.value.trim() && rows.length<MAX)rows.push(makeRow(''));
}
function advanceRow(id){
    const index=rows.findIndex(row=>row.id===id);
    if(index<0)return;
    if(index===rows.length-1 && rows.length<MAX){rows.push(makeRow(''));renderEditor(id);}
    const next=rows[index+1];
    if(!next)return;
    const input=editor.querySelector('input[data-row-id="'+next.id+'"]');
    input?.focus({preventScroll:true});
    input?.setSelectionRange(input.value.length,input.value.length);
    input?.closest('.bulk-uid-row').scrollIntoView({block:'nearest'});
}
function changeRow(row,input){
    const previous=row.value;
    // Defensive fallback for autofill/IME; normal typing is limited by maxlength.
    if(input.value.length>10 || /\D/.test(input.value)){
        input.value=previous;showError('Each row accepts digits only, with a maximum of 10.');return;
    }
    row.value=input.value;preview=null;result=null;
    const count=rows.length;ensureTail();
    if(rows.length!==count)renderEditor(row.id);else refreshEditor();
    showError('');
    if(previous.length<10 && row.value.length===10)advanceRow(row.id);
}
function renderEditor(focusId){
    editor.replaceChildren();
    rows.forEach((row,index)=>{
        const line=document.createElement('div');line.className='bulk-uid-row';line.dataset.rowId=row.id;
        addText(line,'span',String(index+1).padStart(2,'0'),'bulk-row-index');
        const content=document.createElement('div');content.className='bulk-row-content';
        const input=document.createElement('input');input.type='text';input.inputMode='numeric';input.autocomplete='off';input.spellcheck=false;input.maxLength=10;input.pattern='[0-9]{10}';input.value=row.value;
        input.setAttribute('aria-label','Card UID row '+(index+1));input.setAttribute('aria-describedby','bulk-row-message-'+row.id);input.dataset.rowId=row.id;
        input.addEventListener('beforeinput',event=>{
            if(event.inputType!=='insertText'||event.data===null)return;
            const start=input.selectionStart??input.value.length,end=input.selectionEnd??start;
            const proposed=input.value.slice(0,start)+event.data+input.value.slice(end);
            if(/\D/.test(event.data)||proposed.length>10){event.preventDefault();showError('Each row accepts digits only, with a maximum of 10.');}
        });
        input.addEventListener('input',()=>changeRow(row,input));
        input.addEventListener('paste',event=>pasteRows(event,row.id));
        input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();if(input.value)advanceRow(row.id);}});
        content.appendChild(input);
        const message=addText(content,'span','','bulk-row-message');message.id='bulk-row-message-'+row.id;line.appendChild(content);
        const remove=document.createElement('button');remove.type='button';remove.setAttribute('aria-label','Remove row '+(index+1));remove.innerHTML='<i class="fas fa-xmark" aria-hidden="true"></i>';
        remove.addEventListener('click',()=>{const index=rows.findIndex(r=>r.id===row.id);rows.splice(index,1);while(rows.length<6)rows.push(makeRow(''));preview=null;renderEditor(rows[Math.min(index,rows.length-1)].id);});
        line.appendChild(remove);editor.appendChild(line);
    });
    refreshEditor();
    if(focusId)editor.querySelector('input[data-row-id="'+focusId+'"]')?.focus({preventScroll:true});
}
function pasteRows(event,id){
    event.preventDefault();
    const text=event.clipboardData.getData('text').trim();
    const values=text.split(/[\r\n,;]+/).map(value=>value.trim()).filter(Boolean);
    if(!values.length)return;
    if(values.some(value=>value.length>10)){showError('Each row accepts at most 10 digits. The pasted entries were not added.');return;}
    if(values.some(value=>/\D/.test(value))){showError('Card UIDs must contain digits only. The pasted entries were not added.');return;}
    const index=rows.findIndex(row=>row.id===id);if(index<0)return;
    if(values.length===1 && !/[\r\n,;]/.test(text)){
        const input=event.currentTarget,start=input.selectionStart??0,end=input.selectionEnd??input.value.length;
        const proposed=input.value.slice(0,start)+values[0]+input.value.slice(end);
        if(proposed.length>10){showError('Each row accepts at most 10 digits. The pasted entry was not added.');return;}
        input.value=proposed;changeRow(rows[index],input);return;
    }
    const count=entries().length-(rows[index].value.trim()?1:0)+values.length;
    if(count>MAX){showError('Maximum 1,000 cards per batch. No pasted entries were added.');return;}
    const inserted=values.map(makeRow);rows.splice(index,1,...inserted);
    while(rows.length>6&&rows.at(-1).value===''&&rows.at(-2)?.value==='')rows.pop();
    ensureTail();while(rows.length<6)rows.push(makeRow(''));
    preview=null;result=null;showError('');renderEditor(inserted.at(-1).id);
    if(inserted.at(-1).value.length===10)advanceRow(inserted.at(-1).id);
}
function refreshEditor(){const classified=classify(),map=new Map(classified.map(row=>[row.id,row]));editor.querySelectorAll('.bulk-uid-row').forEach(line=>{const row=map.get(Number(line.dataset.rowId));line.className='bulk-uid-row'+(row?' '+row.status:'');const message=line.querySelector('.bulk-row-message');message.textContent=row?.message||'';line.querySelector('input').setAttribute('aria-invalid',String(!!row&&row.status==='invalid'));});const issues=classified.filter(row=>row.status!=='ready');if(!issues.some(row=>row.id===currentIssue))currentIssue=null;el('bulkIssueNav').hidden=!issues.length;el('bulkIssueCount').textContent=issues.length+' flagged entr'+(issues.length===1?'y':'ies');const position=issues.findIndex(row=>row.id===currentIssue);el('bulkIssuePosition').textContent=position>=0?(position+1)+' of '+issues.length:issues.length+' to review';el('uidCountHint').textContent=classified.length+' UID'+(classified.length===1?'':'s')+' entered';updateActions();if(stage===1)updateSummary();}
window.navigateBulkIssue=direction=>{const issues=issueList();if(!issues.length)return;let index=issues.findIndex(row=>row.id===currentIssue);index=index<0?(direction<0?issues.length-1:0):(index+direction+issues.length)%issues.length;focusIssue(issues[index].id);};
function focusIssue(id){currentIssue=id;editor.querySelectorAll('.current-issue').forEach(row=>row.classList.remove('current-issue'));const line=editor.querySelector('[data-row-id="'+id+'"].bulk-uid-row');if(!line)return;line.classList.add('current-issue');line.querySelector('input').focus({preventScroll:true});line.scrollIntoView({block:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});const issues=issueList();el('bulkIssuePosition').textContent=(issues.findIndex(row=>row.id===id)+1)+' of '+issues.length;}
window.clearBulkRows=()=>{if(busy)return;rows=Array.from({length:6},()=>makeRow(''));preview=null;result=null;knownExisting.clear();currentIssue=null;showError('');renderEditor(rows[0].id);};
function summaryRows(pairs){const list=el('bulkSummaryRows');list.replaceChildren();for(const [label,value] of pairs){const row=document.createElement('div');addText(row,'dt',label);addText(row,'dd',String(value));list.appendChild(row);}}
function updateSummary(){let title,pairs,infoTitle,infoText,warning=false;if(stage===1){title='Batch overview';pairs=[['Entered',entries().length],['Format','10-digit UIDs'],['Destination','Available pool']];infoTitle='Review before registering';infoText='The next step checks invalid UIDs, duplicates, and cards already in inventory.';}else if(stage===2){const ready=preview.summary.new,skip=preview.summary.total_input-ready;title=ready+' card'+(ready===1?'':'s')+' ready';pairs=[['Will register',ready],['Will skip',skip],['Destination','Available pool']];warning=skip>0;infoTitle=warning?'Your existing cards are safe':'Registration only';infoText=warning?'Existing cards and repeated or invalid entries will not be registered again.':'These cards will be added to inventory. Assign them to students separately.';}else{title='Registration complete';pairs=[['Registered',result.registered],['Skipped',result.skipped_count],['Destination','Available pool']];infoTitle='Ready for the next step';infoText='Assign a card to a student when you are ready. A student ID card is issued during assignment.';warning=!result.registered;}
el('bulkSummaryTitle').textContent=title;summaryRows(pairs);el('bulkInfoTitle').textContent=infoTitle;el('bulkInfoText').textContent=infoText;el('bulkInfo').classList.toggle('warning',warning);const valid=[...new Set(entries().filter(row=>/^\d{10}$/.test(row.uid)).map(row=>row.uid))];modal.querySelectorAll('.bulk-stack-layer').forEach(layer=>{const index=Number(layer.className.match(/layer-(\d+)/)?.[1]||0);layer.querySelector('.bulk-stack-uid').textContent=valid[index]||valid[0]||'— — — — —';});}
function setStep(step){stage=step;for(const [i,id] of ['bulkEntryPanel','bulkReviewPanel','bulkCompletePanel'].entries())el(id).hidden=i+1!==step;for(const [i,id] of ['bulkEntryActions','bulkReviewActions','bulkCompleteActions'].entries())el(id).hidden=i+1!==step;modal.querySelectorAll('[data-bulk-step]').forEach(item=>{const n=Number(item.dataset.bulkStep);item.classList.toggle('active',n===step);item.classList.toggle('done',n<step);if(n===step)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');item.querySelector('span').textContent=n<step?'✓':n;});el('registerSubheader').textContent=step===1?'Add new cards to your available inventory.':step===2?'Review the checked UIDs before adding them to inventory.':(result.registered?'Your batch has been added to inventory.':'Batch complete. No new cards were added.');el('bulkFooterNote').innerHTML=step===1?'<i class="fas fa-lock"></i> Cards remain unassigned until you assign them.':step===2?'Review flagged rows before continuing.':'<i class="fas fa-check"></i> Registration results saved below.';if(step===3)el('bulkFooterNote').innerHTML=result.registered?'<i class="fas fa-check"></i> Saved to card inventory.':'No new cards were added.';updateSummary();updateActions();modal.querySelector('.bulk-body').scrollTop=0;}
window.regGoStep=step=>{if(busy||step!==1)return;setStep(1);refreshEditor();const issues=issueList();if(issues.length)requestAnimationFrame(()=>focusIssue(issues[0].id));else editor.querySelector('input')?.focus({preventScroll:true});};
function noteLine(id){const note=el(id);note.replaceChildren();if(notes.value.trim()){addText(note,'strong','Batch notes: ');note.append(document.createTextNode(notes.value.trim()));}note.hidden=!notes.value.trim();}
function makeTable(target,data,complete=false){const tbody=el(target);tbody.replaceChildren();const labels={ready:'Ready',registered:'Available',existing:'Already registered',duplicate:'Duplicate in batch',invalid:'Invalid UID'};const icons={ready:'fa-circle-check',registered:'fa-circle-check',existing:'fa-circle-info',duplicate:'fa-triangle-exclamation',invalid:'fa-circle-xmark'};data.forEach(row=>{const tr=document.createElement('tr');const source=submitted[row.index];addText(tr,'td',String(source?.physicalRow||row.index+1).padStart(2,'0'));addText(tr,'td',row.uid);const cell=document.createElement('td');const pill=document.createElement('span');pill.className='bulk-status '+row.status;const icon=document.createElement('i');icon.className='fas '+icons[row.status];icon.setAttribute('aria-hidden','true');pill.append(icon,document.createTextNode(labels[row.status]||row.status));cell.appendChild(pill);tr.appendChild(cell);const duplicateRow=row.duplicate_of===undefined?null:submitted[row.duplicate_of]?.physicalRow||row.duplicate_of+1;const detail=row.status==='invalid'?'Must be 10 digits':row.status==='duplicate'?'Repeats row '+duplicateRow:row.status==='existing'?'Exists in inventory':complete?'Unassigned':'New card';addText(tr,'td',detail);tbody.appendChild(tr);});}
function reviewScreen(){const summary=preview.summary;const metrics=el('bulkReviewMetrics');metrics.replaceChildren();for(const [count,label] of [[summary.total_input,'Entered'],[summary.new,'Ready'],[summary.already_registered,'Already registered'],[summary.duplicates_in_list+summary.format_errors,'Need attention']]){const card=document.createElement('div');addText(card,'strong',String(count));addText(card,'small',label);metrics.appendChild(card);}const skipped=summary.total_input-summary.new;el('bulkSkipNotice').hidden=!skipped;el('bulkSkipNotice').textContent=skipped+' entr'+(skipped===1?'y will':'ies will')+' be skipped unless corrected.';el('bulkReviewHelp').textContent=skipped?'Fix flagged entries or register only the '+summary.new+' ready cards.':'Only new, valid cards will be registered.';makeTable('bulkReviewRows',preview.entries);noteLine('bulkReviewNote');setStep(2);}
window.previewBulkRegister=async()=>{if(busy)return;const list=entries();if(!list.length||list.length>MAX){showError('Enter between 1 and 1,000 card UIDs.');return;}showError('');submitted=list.map(row=>({...row}));setBusy(true);try{const response=await fetch('../api/rfid.php?action=preview-register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({uids:submitted.map(row=>row.uid)})});const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||'Unable to check the batch.');preview=data;for(const row of data.entries)if(row.status==='existing')knownExisting.add(row.uid);reviewScreen();}catch(e){showError(e.message);}finally{setBusy(false);}};
window.submitBulkRegister=async()=>{if(busy||!preview?.new_uids.length)return;showError('');setBusy(true);try{const response=await fetch('../api/rfid.php?action=register',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({uids:submitted.map(row=>row.uid),notes:notes.value.trim()})});const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||'Unable to register this batch.');result=data;inventoryChanged=inventoryChanged||data.registered>0;makeTable('bulkResultRows',data.entries,true);el('bulkResultTitle').textContent=data.registered?data.registered+' card'+(data.registered===1?'':'s')+' registered':'No new cards registered';el('bulkResultDescription').textContent=data.registered?'The new cards are now available for assignment.':'The reviewed cards are already in inventory or need correction.';modal.querySelector('.bulk-result-heading').classList.toggle('attention',!data.registered);noteLine('bulkResultNote');setStep(3);}catch(e){showError(e.message);}finally{setBusy(false);}};
function freshBatch(){rows=Array.from({length:6},()=>makeRow(''));preview=null;result=null;submitted=[];knownExisting.clear();currentIssue=null;notes.value='';showError('');renderEditor();setStep(1);}
window.startAnotherBulkBatch=()=>{if(busy)return;freshBatch();editor.querySelector('input').focus({preventScroll:true});};
window.openRegisterModal=()=>{returnFocus=document.activeElement;modal.classList.add('active');document.body.style.overflow='hidden';freshBatch();requestAnimationFrame(()=>editor.querySelector('input').focus({preventScroll:true}));};
window.closeRegisterModal=()=>{if(busy)return;modal.classList.remove('active');document.body.style.overflow='';returnFocus?.focus({preventScroll:true});if(inventoryChanged)window.location.reload();};
window.viewInPool=window.closeRegisterModal;
modal.addEventListener('click',event=>{if(event.target===modal)closeRegisterModal();});
document.addEventListener('keydown',event=>{if(!modal.classList.contains('active'))return;if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();closeRegisterModal();return;}if(event.key==='Tab'){const controls=[...modal.querySelectorAll('button,input')].filter(control=>!control.disabled&&control.getClientRects().length);const first=controls[0],last=controls.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}}},true);
})();
