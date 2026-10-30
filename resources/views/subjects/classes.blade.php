@php
 $back=($workspace['mapping']??['subject_id'=>$subject->id,...($year?['academic_year_id'=>$year->id]:[])])+array_intersect_key($workspace,['catalog'=>1]);
 $oldClasses=old('class_ids',$selected);
 $saved=$errors->has('setup_revision')||!is_array($oldClasses)?$selected:collect($oldClasses)->filter(fn($id)=>is_numeric($id))->map(fn($id)=>(int)$id)->all();
@endphp
<x-app-layout :title="$subject->name.' · Manage classes'" :full-width="true">
<a href="{{ route('class-subjects.index',$back) }}" class="workspace-back"><x-icon name="chevron-left" /><span>Back to subject classes</span></a>
<h1 class="setup-page-title">Manage classes</h1><p class="setup-page-description">Choose where <strong>{{ $subject->name }}</strong> is taught. Marks settings stay with each class.</p><x-admin-feedback />
<form method="GET" action="{{ route('subjects.classes.edit',$subject) }}" class="subject-year-form" data-subject-year>
<x-context-fields :values="$workspace" /><label for="subject-class-year">Academic year<select id="subject-class-year" name="academic_year_id">
@foreach($years as $option)<option value="{{ $option->id }}" @selected($year?->id===$option->id)>{{ $option->name }} · {{ ucfirst(strtolower($option->status->value)) }}</option>
@endforeach</select></label><button class="table-action action-link">Show classes</button>
</form>
<p class="workspace-note">Choose the year before changing classes. Save changes before switching years.</p>
@if(!$year)<p class="workspace-note">Create a draft or active academic year before assigning classes.</p>
@elseif(!$editable)<p class="workspace-note">{{ $subject->status->value!=='ACTIVE'?'Activate this subject before changing its classes.':'This academic year is read-only. Select a draft or active year to make changes.' }}</p>
@endif
<form method="POST" action="{{ route('subjects.classes.update',$subject) }}" data-subject-class-setup>
@csrf @method('PUT')<x-context-fields :values="$workspace" />
<input type="hidden" name="academic_year_id" value="{{ $year?->id }}"><input type="hidden" name="setup_revision" value="{{ $revision }}"><input type="hidden" name="classes_present" value="1">
<section class="setup-card"><div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="classes" /></span><div><h2>Classes for {{ $subject->name }}</h2><p>{{ $year?->name ?? 'No academic year selected' }} · Select all the classes that use this subject.</p></div></div></div>
<div class="setup-card-body"><fieldset class="setup-fieldset" @disabled(!$editable)>
<label class="subject-class-search">Find a class<input type="search" data-subject-class-search placeholder="Search by class name" autocomplete="off" disabled></label>
<div class="subject-class-grid">
@forelse($classes as $class)
@php($protected=in_array($class->id,$locked,true))
@php($wasAssigned=in_array($class->id,$selected,true))
<label class="subject-class-option" data-subject-class-option data-name="{{ $class->name }}">
@if($protected)<input type="hidden" name="class_ids[]" value="{{ $class->id }}">
@endif
<input type="checkbox" name="class_ids[]" value="{{ $class->id }}" @checked($protected||in_array($class->id,$saved,true)) @disabled($protected) data-was-assigned="{{ $wasAssigned?'true':'false' }}" data-class-name="{{ $class->name }}">
<span><strong>{{ $class->name }}</strong><span>{{ $protected?($class->status->value==='ACTIVE'?'Used by teachers or exams · kept selected':'Inactive class · kept selected'):($wasAssigned?'Currently assigned':($allocations->has($class->id)?'Previously assigned · settings retained':'Ready to assign')) }}</span></span>
</label>
@empty<p class="workspace-note">No active classes are available. Create a class first.</p>
@endforelse
</div><p class="workspace-note" data-subject-class-empty hidden>No classes match your search.</p>
<p class="subject-class-summary" data-subject-class-summary aria-live="polite">Choose classes above, then save your selections.</p>
<section class="subject-removal-review" data-subject-removal-review><strong>Review class removals</strong><p>Unchecking an unused class stops using this subject there for the selected year. Its marks settings and history are retained.</p><ul data-subject-removal-names></ul><label><input type="checkbox" name="confirm_removals" value="1" data-subject-removal-confirm @checked(old('confirm_removals'))>I confirm removing the unchecked classes for this year.</label></section>
<p class="workspace-note">New classes start without marks settings. Restored classes keep their previous settings. You can edit marks for each class after saving.</p>
</fieldset></div></section>
<div class="setup-form-footer"><p>All class changes save together.</p><div class="setup-form-actions"><a href="{{ route('class-subjects.index',$back) }}" class="setup-cancel">Cancel</a><button class="setup-save" @disabled(!$editable)><x-icon name="check" /><span>Save classes</span></button></div></div>
</form></x-app-layout>
