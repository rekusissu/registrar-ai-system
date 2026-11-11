/* Preview-only controls; printed cards stay independent of the modal. */
let idStudioReturnFocus = null;
function refreshIdStudioDesign(design) {
    const names={classic:['Classic','Original navy-and-white college ID.'],light:['Tech Light','Ice-blue surface with cyan technical accents.'],dark:['Tech Dark','Midnight navy with luminous cyan details.'],arctic:['Arctic Circuit','Light finish with precision circuit borders.'],cobalt:['Cobalt Edge','Royal-blue panels and crisp white details.'],pearl:['Pearl Matrix','Iridescent ivory, pink, lilac, and aqua.'],silver:['Silver Signal','Brushed silver with machined-metal framing.']};
    const info=names[design]||names.light;
    document.getElementById('idStudioDesignName').textContent=info[0];
    document.getElementById('idStudioDesignNote').textContent=info[1];
    document.getElementById('idStudioSwatch').dataset.design=design;
}
function setIdStudioFace(face) {
    const back=face==='back';
    document.getElementById('cardFlip').classList.toggle('flipped',back);
    document.getElementById('idStudioFaceLabel').textContent=back?'Back side':'Front side';
    document.querySelectorAll('[data-card-face]').forEach(button=>{const active=button.dataset.cardFace===(back?'back':'front');button.classList.toggle('selected',active);button.setAttribute('aria-pressed',String(active));});
}
function openIdStudioDetails(trigger) {
    idStudioReturnFocus=trigger;
    for(const [target,source] of Object.entries({idStudioName:'cardName',idStudioNumber:'cardNumber',idStudioCourse:'cardCourse',idStudioExpiry:'cardExpiry'}))document.getElementById(target).textContent=document.getElementById(source).textContent;
    document.getElementById('idStudioInitials').textContent=idInitialsOf(document.getElementById('cardName').textContent);
    setIdStudioFace('front');
    loadIdStudioDetails(idCardData.cardId, idCardData.studentId);
    requestAnimationFrame(()=>{fitIdCardPreview();document.querySelector('#idCardModal .idstudio-close').focus({preventScroll:true});});
}
function closeIdStudio() {
    idStudioDetailsRequest++;
    stopIdConstruction();
    document.getElementById('idCardModal').classList.remove('active');
    document.body.style.overflow='';
    if(idStudioReturnFocus?.isConnected)idStudioReturnFocus.focus({preventScroll:true});
}
document.getElementById('idCardModal').addEventListener('click',event=>{if(event.target===event.currentTarget)closeIdStudio();});
document.addEventListener('keydown',event=>{
    const modal=document.getElementById('idCardModal');if(!modal.classList.contains('active'))return;
    if(event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();closeIdStudio();return;}
    if(event.key==='Tab'){
        const controls=[...modal.querySelectorAll('button,select,a[href],input')].filter(el=>!el.disabled&&el.getClientRects().length);
        const first=controls[0],last=controls[controls.length-1];
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
    }
},true);

let idStudioDetails = null;
let idStudioDetailsRequest = 0;
let idStudioEditing = false;
const idStudioDetailFields=['name','program','address','emergency_name','emergency_relationship','emergency_phone','issued_date','expiry_date'];
function idStudioEditingLayout(editing){
    idStudioEditing=editing;
    const panel=document.querySelector('.idstudio-controls');
    panel.classList.toggle('editing',editing);
    document.getElementById('idStudioEditor').hidden=!editing;
    document.getElementById('idStudioResetConfirm').hidden=true;
    if(editing)panel.scrollTop=0;
}
function updateIdStudioSummary(){
    for(const [target,source] of Object.entries({idStudioName:'cardName',idStudioNumber:'cardNumber',idStudioCourse:'cardCourse',idStudioExpiry:'cardExpiry'}))document.getElementById(target).textContent=document.getElementById(source).textContent;
    document.getElementById('idStudioInitials').textContent=idInitialsOf(document.getElementById('cardName').textContent);
}
function idPrintedDate(value,empty){if(!value)return empty;const [y,m,d]=value.split('-').map(Number);return new Date(y,m-1,d).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});}
function fitIdStudioPrintedText(){
    for(const [id,max,min,height] of [['cardName',23,12,38],['cardCourse',16,9,38]]){
        const node=document.getElementById(id);if(!node)continue;
        node.style.fontSize='';
        let size=Math.min(max,parseFloat(getComputedStyle(node).fontSize)||max);
        node.style.fontSize=size+'px';
        while(size>min&&(node.scrollHeight>height||node.scrollWidth>node.clientWidth+1)){size--;node.style.fontSize=size+'px';}
    }
}
function renderIdStudioDetails(details){
    document.getElementById('cardName').textContent=details.name||'—';
    document.getElementById('cardInitials').textContent=idInitialsOf(details.name);
    document.getElementById('cardCourse').textContent=details.program||'—';
    document.getElementById('cardIssued').textContent=idPrintedDate(details.issued_date,'—');
    document.getElementById('cardExpiry').textContent=idPrintedDate(details.expiry_date,'No expiry');
    const address=document.getElementById('cardBackAddr');address.textContent=details.address||'Not on file';
    const contact=document.getElementById('cardBackEmg');contact.replaceChildren();
    const name=document.createElement('div');name.className='idcard-emg-name';name.textContent=details.emergency_name||'No emergency contact on file';contact.appendChild(name);
    if(details.emergency_name){const sub=document.createElement('div');sub.className='idcard-emg-sub';sub.textContent=[details.emergency_relationship,details.emergency_phone].filter(Boolean).join(' · ');contact.appendChild(sub);}
    fitIdStudioPrintedText();updateIdStudioSummary();
}
async function loadIdStudioDetails(cardId,studentId){
    const request=++idStudioDetailsRequest;idStudioDetails=null;idStudioEditingLayout(false);
    const status=document.getElementById('idStudioDetailsStatus'),edit=document.getElementById('idStudioEditButton'),print=document.querySelector('.idstudio-print');
    edit.disabled=true;print.disabled=true;status.textContent='Loading saved ID details…';status.classList.remove('error');
    try{
        const response=await fetch('../api/id-card-details.php?card_id='+encodeURIComponent(cardId),{cache:'no-store'});const result=await response.json();
        if(request!==idStudioDetailsRequest)return;
        if(!response.ok||!result.success)throw Error(result.message||'Unable to load ID details.');
        if(String(result.data.student_id)!==String(studentId))throw Error('Card assignment changed. Reopen the preview.');
        idStudioDetails=result.data;renderIdStudioDetails(result.data.details);edit.disabled=false;print.disabled=false;status.textContent=result.data.customized?'Saved ID corrections applied.':'';
    }catch(error){if(request!==idStudioDetailsRequest)return;status.classList.add('error');status.textContent=error.message+' Close and reopen to retry.';}
}
function editIdStudioDetails(){
    if(!idStudioDetails)return;stopIdConstruction();
    const form=document.getElementById('idStudioEditForm');form.reset();
    idStudioDetailFields.forEach(field=>{form.elements[field].value=idStudioDetails.details[field]||'';});
    document.getElementById('idStudioEditError').textContent='';
    document.getElementById('idStudioResetButton').hidden=!idStudioDetails.customized;
    idStudioEditingLayout(true);setIdStudioFace('front');form.elements.name.focus({preventScroll:true});
}
function cancelIdStudioEdit(){
    if(idStudioDetails)renderIdStudioDetails(idStudioDetails.details);
    idStudioEditingLayout(false);document.getElementById('idStudioEditButton').focus({preventScroll:true});
}
function readIdStudioForm(){const form=document.getElementById('idStudioEditForm');return Object.fromEntries(idStudioDetailFields.map(field=>[field,form.elements[field].value.trim()]));}
async function persistIdStudioDetails(reset=false){
    if(!idStudioDetails)return;
    const form=document.getElementById('idStudioEditForm');
    if(!reset&&!form.reportValidity())return;
    const request=idStudioDetailsRequest;
    const error=document.getElementById('idStudioEditError'),save=document.getElementById('idStudioSaveButton');error.textContent='';
    const buttons=[...document.querySelectorAll('#idStudioEditor button')];buttons.forEach(button=>button.disabled=true);form.querySelectorAll('input,textarea').forEach(field=>field.disabled=true);
    save.textContent=reset?'Resetting…':'Saving…';
    const body={student_id:idStudioDetails.student_id,version:idStudioDetails.version,reason:form.elements.reason.value.trim()||(reset?'Restored original student details':''),details:readIdStudioForm()};
    try{
        const response=await fetch('../api/id-card-details.php?card_id='+idStudioDetails.card_id,{method:reset?'DELETE':'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const result=await response.json();if(request!==idStudioDetailsRequest)return;
        if(!response.ok||!result.success)throw Error(result.message||'Unable to save.');
        idStudioDetails=result.data;renderIdStudioDetails(result.data.details);idStudioEditingLayout(false);
        document.getElementById('idStudioDetailsStatus').textContent=reset?'Original student details restored.':'ID corrections saved.';
        document.getElementById('idStudioDetailsStatus').classList.remove('error');document.getElementById('idStudioEditButton').focus({preventScroll:true});
    }catch(e){if(request===idStudioDetailsRequest)error.textContent=e.message;}
    finally{buttons.forEach(button=>button.disabled=false);form.querySelectorAll('input,textarea').forEach(field=>field.disabled=false);save.textContent='Save ID details';}
}
function showIdStudioReset(){document.getElementById('idStudioResetConfirm').hidden=false;}
function resetIdStudioDetails(){return persistIdStudioDetails(true);}
document.getElementById('idStudioEditForm').addEventListener('submit',event=>{event.preventDefault();persistIdStudioDetails();});
document.getElementById('idStudioEditForm').addEventListener('input',event=>{if(idStudioEditing&&event.target.name!=='reason')renderIdStudioDetails(readIdStudioForm());});
document.getElementById('idStudioEditForm').addEventListener('focusin',event=>{if(idStudioEditing&&idStudioDetailFields.includes(event.target.name))setIdStudioFace(['address','emergency_name','emergency_relationship','emergency_phone'].includes(event.target.name)?'back':'front');});
