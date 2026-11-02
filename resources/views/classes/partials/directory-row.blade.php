<tr>
    <td class="classes-row-number">{{ $number }}</td>
    <td class="classes-row-name">{{ $row['class']->name }}</td>
    @if($mode === 'sections')<td><span class="classes-section-name">{{ $row['record']->name }}</span></td>@endif
    <td><span class="classes-reference">{{ $row['reference'] }}</span></td>
    @if($mode === 'classes' && $canSections)<td>{{ $row['sections']->pluck('name')->join(', ') ?: 'No active sections' }}</td>@endif
    @if($canStudents)<td><span class="classes-student-count"><x-icon name="users" />{{ $year ? number_format($row['students']) : '—' }}</span></td>@endif
    @if($canTeachers)<td>
        @if(!$year)<span class="classes-muted">No year selected</span>
        @elseif($row['classTeachers']->isEmpty())<span class="classes-unassigned"><x-icon name="info" />Not assigned</span>
        @elseif($mode === 'sections')<span class="classes-teacher-name"><x-icon name="users" />{{ $row['teacher']->employee->fullName() }}<x-icon name="check" class="classes-assigned-check" /></span>
        @else<span class="classes-teacher-name">{{ $row['classTeachers']->pluck('teacher')->map(fn ($teacher) => $teacher->employee->fullName())->unique()->join(', ') }}</span>@endif
    </td>@endif
    @if($canTeachers && $canSubjects && $mode === 'sections')<td><div class="classes-subject-chips">@forelse($row['taught']->take(4) as $subject)<span>{{ $subject->name }}</span>@empty<span class="classes-muted">—</span>@endforelse @if($row['taught']->count() > 4)<span title="{{ $row['taught']->skip(4)->pluck('name')->join(', ') }}" aria-label="More subjects: {{ $row['taught']->skip(4)->pluck('name')->join(', ') }}">+{{ $row['taught']->count() - 4 }}</span>@endif</div></td>@endif
    @if($canSubjects)<td><span class="classes-subject-total">{{ $year ? $row['subjects']->count() : '—' }}</span></td>@endif
    <td><x-status-badge :value="$row['status']" :label="ucfirst(strtolower($row['status']))" /></td>
    <td><div class="classes-row-actions"><x-action-link :href="route('classes.show', $row['class'])" label="View" :icon-only="true" icon="eye" :aria-label="'View '.$row['class']->name.($mode === 'sections' ? ' section '.$row['record']->name : '')" />
        @can('update', $row['class'])<x-action-link :href="route('classes.edit', $row['class'])" label="Edit" :icon-only="true" icon="pencil" :aria-label="'Edit '.$row['class']->name" />@endcan
        @if($row['status'] === 'PENDING' && $year && in_array($year->status->value, ['ACTIVE', 'DRAFT'], true))
            @can('create', \App\Models\TeacherAssignment::class)<x-action-link :href="$year->is_current && $year->status->value === 'ACTIVE' && auth()->user()->can('update', $row['class']) ? route('classes.edit', $row['class']).'#teachers-heading' : route('assignments.create', ['academic_year_id' => $year->id, 'class_id' => $row['class']->id, 'section_id' => $mode === 'sections' ? $row['record']->id : null, 'assignment_type' => 'CLASS_TEACHER', 'scope' => $mode === 'sections' ? 'SECTION' : 'CLASS'])" label="Assign" icon="assignment" :primary="true" :aria-label="'Assign teachers to '.$row['class']->name.($mode === 'sections' ? ' section '.$row['record']->name : '')" />@endcan
        @endif
    </div></td>
</tr>
