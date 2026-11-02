document.querySelectorAll('[data-enrollment-editor]').forEach((editor) => {
    const initial = JSON.parse(editor.querySelector('[data-enrollment-initial]').textContent);
    const field = (name) => editor.querySelector(`[data-enrollment-${name}]`);
    const dep = (name) => editor.querySelector(`[data-departure-${name}]`);
    const form = editor.closest('form');
    const admission = form.querySelector('[name="admission_date"]');
    const year = field('year'), enabled = field('enable'), body = field('body');
    const classes = field('class'), sections = field('section');
    const rollMode = field('roll-mode'), roll = field('roll'), joined = field('joined');
    const dialog = field('dialog'), departureDialog = dep('dialog');
    const drafts = new Map();
    const submitButtons = [...form.querySelectorAll('button[type="submit"]')];
    const submitLabels = submitButtons.map((button) => ({button, label:button.querySelector('span'), text:button.querySelector('span')?.textContent}));
    let state, correcting=false, loading=false, controller, serial=0, retryYear, defaultJoined;
    let enrollmentSnapshot, departureStage=null, departureKind='WITHDRAW', dialogReturnFocus, departureReturnFocus;
    const truth = value => value===true || value===1 || value==='1';
    const uuid = () => crypto.randomUUID?.() || 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16);});
    const capture = () => ({action:enabled.checked?'save':'keep',class_id:classes.value,section_id:sections.value,roll_mode:rollMode.value,roll_number:roll.value,joined_at:joined.value,revision:field('revision').value,correct_placement:correcting,reactivate:field('reactivate').checked});
    const selectedSection = () => state?.classes.find(row=>String(row.id)===classes.value)?.sections.find(row=>String(row.id)===sections.value);
    const minimumDate = () => [state?.year.start,admission.value].filter(Boolean).sort().at(-1)||'';
    const pendingEnrollment = () => enabled.checked && state?.can_write;
    const lockPage = () => document.documentElement.classList.toggle('student-modal-open',dialog.open||departureDialog.open);
    const close = (modal,focus) => {modal.close();lockPage();if(focus?.isConnected)focus.focus();};
    const validControls = (root) => {
        const invalid=[...root.querySelectorAll('input,select,textarea')].find(input=>input.willValidate&&!input.checkValidity());
        if(invalid){invalid.reportValidity();return false;}return true;
    };
    const refreshWorkspace = () => {
        const current=state?.enrollment;
        const summary=field('compact');summary.replaceChildren();
        const line=document.createElement('strong');
        line.textContent=state ? `${state.year.name}${current?` · ${current.status.charAt(0)+current.status.slice(1).toLowerCase()}`:''}`:'No academic year selected';
        const detail=document.createElement('p');detail.textContent=current?`${current.class_name} · Section ${current.section_name} · Roll ${current.roll_number}`:'No enrollment saved for this year.';
        summary.append(line,detail);
        if(state?.departure){const history=document.createElement('p');history.textContent=[state.departure.date?`Departure date: ${state.departure.date}`:'',state.departure.reason,state.departure.destination_school?`Destination: ${state.departure.destination_school}`:''].filter(Boolean).join(' · ');summary.append(history);}
        const pending=editor.querySelector('[data-student-action-pending]');
        pending.hidden=!departureStage&&!pendingEnrollment();
        editor.querySelector('[data-student-action-title]').textContent=departureStage?(departureStage.action==='TRANSFER'?'Transfer pending':'Withdrawal pending'):'Enrollment changes pending';
        const className=state?.classes.find(row=>String(row.id)===classes.value)?.name||current?.class_name||'';
        editor.querySelector('[data-student-action-description]').textContent=departureStage?`${departureStage.departure_date} · ${departureStage.reason}${departureStage.destination_school?` · ${departureStage.destination_school}`:''} · Recorded when you save the student.`:`${state?.year.name||''} · ${className}${selectedSection()?` / ${selectedSection().name}`:''} · Applied when you save the student.`;
        editor.querySelectorAll('[data-departure-open]').forEach(button=>{button.disabled=loading||!state?.can_exit||Boolean(pendingEnrollment());});
        field('open').disabled=loading||Boolean(departureStage);
        editor.querySelector('[data-departure-availability]').textContent=departureStage?'Cancel the pending departure before changing enrollment.':pendingEnrollment()?'Cancel pending enrollment changes before recording a withdrawal or transfer.':state?.exit_reason||(!state?'Save an active enrollment before recording a withdrawal or transfer.':'');
        submitLabels.forEach(({label,text})=>{if(label)label.textContent=departureStage?(departureStage.action==='TRANSFER'?'Save changes & transfer':'Save changes & withdraw'):text;});
    };
    const refresh = () => {
        const editing=Boolean(state?.can_write&&enabled.checked&&!loading&&!departureStage);
        field('action').value=editing?'save':'keep';
        body.hidden=!enabled.checked||!state?.can_write;body.disabled=!editing;
        const fixed=Boolean(state?.enrollment&&!correcting);
        field('placement').disabled=fixed||!editing;
        field('fixed-class').disabled=!fixed||!editing;field('fixed-section').disabled=!fixed||!editing;
        field('fixed-class').value=state?.enrollment?.class_id||'';field('fixed-section').value=state?.enrollment?.section_id||'';field('correct').value=correcting?'1':'0';
        classes.required=editing&&!fixed;sections.required=editing&&!fixed;sections.disabled=!classes.value;
        const manual=rollMode.value==='manual';roll.hidden=!manual;roll.disabled=!editing||!manual;roll.required=editing&&manual;
        joined.required=editing;joined.min=minimumDate();joined.max=state?.year.end||'';
        const section=selectedSection(),currentSeat=state?.enrollment?.status==='ACTIVE'&&String(state.enrollment.section_id)===sections.value;
        field('seats').textContent=section?(section.remaining===null?`${section.occupied} students enrolled · No student limit set.`:`${section.remaining} of ${section.capacity} seats available.${currentSeat?' Current seat retained.':''}`):'';
        field('roll-hint').textContent=manual?'Must be unique within this year, class and section.':section?(section.suggested_roll?`Suggested: ${section.suggested_roll}. Assigned when you save.`:'No automatic roll numbers remain. Enter an unused number manually.'):'A roll number will be assigned when you choose a section and save.';
        field('correction').hidden=!state?.enrollment||!state?.can_correct;field('change').textContent=correcting?'Keep original class / section':'Change class / section';field('placement-reason').textContent=state?.placement_reason||'';
        field('reactivate-label').hidden=state?.enrollment?.status!=='INACTIVE';field('reactivate').disabled=!editing||state?.enrollment?.status!=='INACTIVE';
        field('reactivate').setCustomValidity(editing&&field('reactivate').checked&&section?.remaining===0&&!currentSeat?'This section is full. A free seat is required to reactivate this enrollment.':'');
        field('kept').hidden=true;field('enable-label').hidden=true;
        field('apply').disabled=loading;field('apply').textContent=state?.can_write?'Apply enrollment changes':'Done';
        refreshWorkspace();
    };
    const populateSections = (selected='') => {
        const list=state?.classes.find(row=>String(row.id)===classes.value)?.sections||[];
        sections.replaceChildren(new Option(classes.value?(list.length?'Choose a section':'No active sections'):'Choose a class first',''));
        list.forEach(row=>{const option=new Option(`${row.name}${row.remaining===0?' · Full':''}`,String(row.id));option.disabled=row.remaining===0&&String(state?.enrollment?.section_id)!==String(row.id);sections.add(option);});
        if(selected&&![...sections.options].some(option=>option.value===String(selected))&&state?.enrollment&&String(selected)===String(state.enrollment.section_id))sections.add(new Option(state.enrollment.section_name||'Existing section',String(selected)));
        sections.value=[...sections.options].some(option=>option.value===String(selected))?String(selected):'';refresh();
    };
    const render = (next,draft=null) => {
        state=next;year.value=next?String(next.year.id):'';enabled.checked=Boolean(next?.can_write&&(draft?.action==='save'||dialog.open));enabled.disabled=!next?.can_write;
        field('enable-text').textContent=next?.enrollment?'Edit this enrollment':'Assign a class now';
        field('reason').textContent=next?.reason||(!next?'Choose an active academic year to assign a class. You can also save the student and add enrollment later.':'');
        const current=next?.enrollment;field('badge').textContent=current?current.status.charAt(0)+current.status.slice(1).toLowerCase():'Optional';
        field('summary').hidden=!current;field('summary').textContent=current?`${current.class_name} · Section ${current.section_name} · Roll ${current.roll_number} · ${current.status.charAt(0)+current.status.slice(1).toLowerCase()} · Joined ${current.joined_at}`:'';
        correcting=Boolean(next?.can_correct&&truth(draft?.correct_placement));classes.replaceChildren(new Option('Choose a class',''));next?.classes.forEach(row=>classes.add(new Option(row.name,String(row.id))));
        const classId=draft?.class_id||current?.class_id||'';if(classId&&![...classes.options].some(option=>option.value===String(classId))&&current&&String(classId)===String(current.class_id))classes.add(new Option(current.class_name||'Existing class',String(classId)));
        classes.value=String(classId);rollMode.value=draft?.roll_mode||(current?'manual':'auto');roll.value=draft?.roll_number??current?.roll_number??'';
        defaultJoined=minimumDate();joined.value=draft?.joined_at||current?.joined_at||defaultJoined;field('revision').value=draft?.revision||next?.revision||'';field('reactivate').checked=truth(draft?.reactivate);populateSections(draft?.section_id||current?.section_id||'');
    };
    const loadYear=async(target)=>{
        if(state)drafts.set(String(state.year.id),capture());controller?.abort();controller=new AbortController();const request=++serial;retryYear=target;field('retry').hidden=true;field('fetch-error').textContent='';
        if(!target){loading=false;field('loading').hidden=true;editor.removeAttribute('aria-busy');render(null);submitButtons.forEach(button=>{button.disabled=false;});return;}
        const previous=state,previousDraft=state?drafts.get(String(state.year.id)):null;field('loading').hidden=false;loading=true;submitButtons.forEach(button=>{button.disabled=true;});editor.setAttribute('aria-busy','true');refresh();
        try{const url=new URL(editor.dataset.optionsUrl,location.href);url.searchParams.set('academic_year_id',target);const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:controller.signal});if(!response.ok||response.redirected)throw new Error('Enrollment unavailable');const next=await response.json();if(request!==serial)return;let draft=drafts.get(String(next.year.id));if(draft&&draft.revision!==next.revision){draft=null;drafts.delete(String(next.year.id));field('fetch-error').textContent='This enrollment changed while you were editing. Latest details are shown. Student and guardian entries are preserved.';}loading=false;render(next,draft);}
        catch(error){if(error.name==='AbortError'||request!==serial)return;loading=false;render(previous,previousDraft);field('fetch-error').textContent='Could not load that academic year. Your previous enrollment selection is restored. Student and guardian entries are preserved.';field('retry').hidden=false;}
        finally{if(request===serial){loading=false;field('loading').hidden=true;editor.removeAttribute('aria-busy');submitButtons.forEach(button=>{button.disabled=false;});refresh();}}
    };
    const openEnrollment=(trigger,baseline=null)=>{
        if(departureStage){editor.querySelector('[data-departure-availability]').textContent='Cancel the pending departure before changing enrollment.';return;}
        dialogReturnFocus=trigger||document.activeElement;enrollmentSnapshot=baseline||{state,draft:capture(),drafts:new Map(drafts)};
        dialog.showModal();lockPage();enabled.checked=Boolean(state?.can_write);refresh();
    };
    const cancelEnrollment=()=>{controller?.abort();++serial;loading=false;editor.removeAttribute('aria-busy');field('loading').hidden=true;submitButtons.forEach(button=>{button.disabled=false;});dialog.close();drafts.clear();enrollmentSnapshot?.drafts?.forEach((value,key)=>drafts.set(key,value));render(enrollmentSnapshot?enrollmentSnapshot.state:initial.state,enrollmentSnapshot?.draft||null);lockPage();dialogReturnFocus?.focus();};
    const populateDeparture=(value)=>{
        dep('date').value=value?.departure_date||[initial.today,state?.year.end].filter(Boolean).sort()[0]||initial.today;
        dep('reason').value=value?.reason||'';dep('reason').setCustomValidity('');dep('destination').value=value?.destination_school||'';dep('confirm').checked=truth(value?.confirmed);
        dep('year').value=state?.year.id||'';dep('revision').value=value?.revision||state?.revision||'';dep('operation-key').value=value?.operation_key||uuid();
    };
    const setDepartureStage=(value)=>{departureStage=value;dep('action').value=value?.action||'keep';dep('fields').disabled=!value;if(value)populateDeparture(value);refresh();};
    const openDeparture=(action,trigger,value=null)=>{
        if(!state?.can_exit||pendingEnrollment())return;
        departureKind=action;departureReturnFocus=trigger||document.activeElement;
        const transfer=action==='TRANSFER',word=transfer?'transfer':'withdrawal';
        dep('heading').textContent=transfer?'Transfer student':'Withdraw student';dep('date-label').textContent=transfer?'Transfer date *':'Withdrawal date *';dep('apply').textContent=transfer?'Add transfer to changes':'Add withdrawal to changes';
        dep('confirm-label').textContent=`I confirm this ${word} will be recorded when I save the student. Academic records and contact history will be retained.`;
        dep('identity').textContent=[initial.student_name,initial.admission_number].filter(Boolean).join(' · ');
        dep('summary').textContent=`${state.year.name} · ${state.enrollment.class_name} · Section ${state.enrollment.section_name} · Roll ${state.enrollment.roll_number}`;
        populateDeparture(value||(departureStage?.action===action?departureStage:null));dep('destination-area').hidden=!transfer;dep('destination').disabled=!transfer;
        dep('date').min=state.enrollment.joined_at;dep('date').max=[initial.today,state.year.end].sort()[0];dep('fields').disabled=false;dep('dialog-error').textContent='';departureDialog.showModal();lockPage();
    };
    const cancelDeparture=()=>{if(departureStage){populateDeparture(departureStage);dep('destination-area').hidden=departureStage.action!=='TRANSFER';dep('destination').disabled=departureStage.action!=='TRANSFER';}else{dep('fields').disabled=true;dep('action').value='keep';}close(departureDialog,departureReturnFocus);refresh();};
    field('editor-fields').disabled=false;dep('action').disabled=false;render(initial.state,initial.draft);
    field('open').addEventListener('click',event=>openEnrollment(event.currentTarget));
    editor.querySelectorAll('[data-enrollment-dialog-cancel]').forEach(button=>button.addEventListener('click',cancelEnrollment));
    field('apply').addEventListener('click',()=>{if(loading||!validControls(field('editor-fields')))return;close(dialog,dialogReturnFocus);refresh();});
    editor.querySelectorAll('[data-departure-open]').forEach(button=>button.addEventListener('click',()=>openDeparture(button.dataset.departureOpen,button)));
    editor.querySelectorAll('[data-departure-cancel]').forEach(button=>button.addEventListener('click',cancelDeparture));
    dep('apply').addEventListener('click',()=>{if(!validControls(dep('fields')))return;const value={action:departureKind,academic_year_id:dep('year').value,revision:dep('revision').value,operation_key:dep('operation-key').value,departure_date:dep('date').value,reason:dep('reason').value.trim(),destination_school:departureKind==='TRANSFER'?dep('destination').value.trim():'',confirmed:dep('confirm').checked};if(!value.reason){dep('reason').setCustomValidity('Enter a reason for this departure.');dep('reason').reportValidity();return;}setDepartureStage(value);close(departureDialog,departureReturnFocus);});
    dep('reason').addEventListener('input',()=>dep('reason').setCustomValidity(''));
    editor.querySelector('[data-student-action-edit]').addEventListener('click',event=>departureStage?openDeparture(departureStage.action,event.currentTarget):openEnrollment(event.currentTarget));
    editor.querySelector('[data-student-action-clear]').addEventListener('click',()=>{if(departureStage)setDepartureStage(null);else{enabled.checked=false;drafts.clear();render(state,null);}refresh();});
    year.addEventListener('change',()=>loadYear(year.value));field('retry').addEventListener('click',()=>loadYear(retryYear));enabled.addEventListener('change',refresh);
    classes.addEventListener('change',()=>{populateSections();if(state?.enrollment)rollMode.value='auto';refresh();});sections.addEventListener('change',()=>{if(state?.enrollment&&correcting&&String(state.enrollment.section_id)!==sections.value)rollMode.value='auto';refresh();});rollMode.addEventListener('change',refresh);field('reactivate').addEventListener('change',refresh);
    field('change').addEventListener('click',()=>{correcting=!correcting;if(!correcting){classes.value=String(state.enrollment.class_id);rollMode.value='manual';roll.value=state.enrollment.roll_number;populateSections(state.enrollment.section_id);}refresh();});
    admission.addEventListener('change',()=>{const next=minimumDate();if(!state?.enrollment&&joined.value===defaultJoined)joined.value=next;defaultJoined=next;refresh();});
    [[dialog,cancelEnrollment,field('apply')],[departureDialog,cancelDeparture,dep('apply')]].forEach(([modal,cancel,apply])=>{
        modal.addEventListener('cancel',event=>{event.preventDefault();cancel();});
        modal.addEventListener('click',event=>{if(event.target!==modal)return;const rect=modal.getBoundingClientRect();if(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom)cancel();});
        modal.addEventListener('keydown',event=>{if(event.key==='Enter'&&!['TEXTAREA','SELECT','BUTTON'].includes(event.target.tagName)){event.preventDefault();apply.click();}});
    });
    form.addEventListener('invalid',event=>{const modal=event.target.closest('dialog');if(modal&&!modal.open){if(modal===dialog)openEnrollment(field('open'));else openDeparture(departureStage?.action||'WITHDRAW',editor.querySelector('[data-student-action-edit]'),departureStage);}},true);
    form.addEventListener('submit',event=>{if(dialog.open||departureDialog.open){event.preventDefault();(dialog.open?field('apply'):dep('apply')).click();return;}submitButtons.forEach(button=>{button.disabled=true;});});
    if(initial.departure&&state?.can_exit&&!initial.departure_errors){enabled.checked=false;setDepartureStage(initial.departure);}
    if(initial.departure_errors&&initial.departure&&state?.can_exit){enabled.checked=false;refresh();openDeparture(initial.departure.action,editor.querySelector(`[data-departure-open="${initial.departure.action}"]`),initial.departure);}
    else if(initial.enrollment_errors){openEnrollment(field('open'),{state,draft:null,drafts:new Map()});}
    else if(location.hash==='#enrollment-heading'){openEnrollment(field('open'));}
});
