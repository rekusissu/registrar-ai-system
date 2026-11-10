
(() => {
 let current=null, trigger=null;
 const modal=document.getElementById('requestViewModal'),body=document.getElementById('requestViewBody');
 window.closeRequestView=function(){if(current){const holder=document.querySelector('#detail-'+current+' > td');const content=body.querySelector('.rd-detail');if(holder&&content)holder.append(content);}modal.classList.remove('active');document.body.style.overflow='';current=null;if(trigger?.isConnected)trigger.focus();};
 window.toggleDetail=function(id){if(current!==null)closeRequestView();const content=document.querySelector('#detail-'+id+' .rd-detail');if(!content)return;current=id;trigger=document.activeElement;body.replaceChildren(content);modal.classList.add('active');document.body.style.overflow='hidden';body.scrollTop=0;modal.querySelector('.modal-close').focus();};
 modal.addEventListener('click',e=>{if(e.target===modal)closeRequestView()});
 modal.addEventListener('keydown',e=>{if(e.key==='Escape'){e.stopPropagation();closeRequestView()}if(e.key==='Tab'){const controls=Array.from(modal.querySelectorAll('button,a[href],input,select,textarea,[tabindex="0"]')).filter(n=>!n.disabled&&n.getClientRects().length);const first=controls[0],last=controls[controls.length-1];if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus()}}});
})();
