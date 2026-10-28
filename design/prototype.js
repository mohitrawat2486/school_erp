
(function(){
 const page=location.pathname.split('/').pop()||'index.html';
 const toast=(m)=>{let x=document.createElement('div');x.className='toast';x.textContent=m;document.body.appendChild(x);setTimeout(()=>x.remove(),2600)};
 const modal=(title,body,ok='Save')=>{
   let b=document.createElement('div');b.className='modal-backdrop';
   b.innerHTML=`<div class="proto-modal"><h2>${title}</h2>${body}<div class="modal-actions"><button class="btn btn-light" data-close>Cancel</button><button class="btn btn-primary" data-ok>${ok}</button></div></div>`;
   document.body.appendChild(b); b.querySelector('[data-close]').onclick=()=>b.remove();
   b.querySelector('[data-ok]').onclick=()=>{toast(title+' completed in prototype');b.remove()};
 };
 document.addEventListener('click',e=>{
   const a=e.target.closest('a'); if(a && a.getAttribute('href')==='#'){e.preventDefault();toast('Prototype action opened');}
   const btn=e.target.closest('button'); if(!btn)return;
   const t=btn.textContent.trim().toLowerCase();
   if(btn.onclick || t.includes('print')) return;
   if(t.includes('add student')){modal('Add Student',`<div class="form-grid"><label>Admission No.<input class="input" value="SAPS-006"></label><label>Student Name<input class="input" placeholder="Full name"></label><label>Father Name<input class="input"></label><label>Mother Name<input class="input"></label><label>Class<select class="select"><option>Class 1</option><option>Class 2</option><option>Class 3</option></select></label><label>Section<select class="select"><option>A</option><option>B</option></select></label></div>`,'Add Student');}
   else if(t.includes('add assessment area')) modal('Add Assessment Area','<label>Assessment Area<input class="input" value="Music"></label><label>Grade Scale<select class="select"><option>A+ to C</option></select></label>','Add Area');
   else if(page==='classes.html' && t.includes('add class')) modal('+ Add Class','<label>Name<input class="input" placeholder="Enter name"></label><label>Section<input class="input" placeholder="e.g. A, B, C"></label><label>Status<select class="select"><option>Active</option><option>Draft</option></select></label>','+ Add Class');
   else if(t.includes('add') || t.includes('create') || t.includes('setup')) modal(btn.textContent.trim(),'<label>Name<input class="input" placeholder="Enter name"></label><label>Status<select class="select"><option>Active</option><option>Draft</option></select></label>',btn.textContent.trim());
   else if(t.includes('save')) toast('Draft/data saved successfully in prototype');
   else if(t.includes('finalize')) toast('Prototype validation completed. Finalization action demonstrated.');
   else if(t.includes('export excel')) toast('Excel export prepared in prototype');
   else if(t.includes('export pdf')) toast('PDF export prepared in prototype');
   else if(t.includes('load')) toast('Selected records loaded');
   else if(t.includes('filter') || t.includes('show all') || t.includes('missing marks') || t.includes('failed students') || t.includes('incomplete results')) toast(btn.textContent.trim()+' filter applied');
   else if(t.includes('fill down')) toast('Selected grade copied down the column');
   else if(t.includes('download')) toast('Template download demonstrated');
   else toast(btn.textContent.trim()+' action completed in prototype');
 });
 window.protoToast=toast; window.protoModal=modal;
})();
