@props(['enrollments'])
<x-data-table :records="$enrollments" title="Student enrollments" icon="classes"><table class="w-full text-left"><thead><tr><th>Student</th><th>Academic year</th><th>Class / Section</th><th>Roll</th><th>Joined</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($enrollments as $record)
<tr><td class="font-semibold">{{ $record->student->fullName() }}<p class="mt-1 text-xs font-normal text-slate-500">{{ $record->student->admission_number }}</p></td><td>{{ $record->academicYear->name }}</td><td>{{ $record->schoolClass->name }} / {{ $record->section->name }}</td><td>{{ $record->roll_number }}</td><td>{{ $record->joined_at->format('d M Y') }}</td><td><x-status-badge :value="$record->status->value" /></td><td><a href="{{ route('enrollments.show',$record) }}" class="table-action text-[#8b2f24]">View<x-icon name="chevron-right" /></a></td></tr>
@empty<tr><td colspan="7">No enrollments match this selection.</td></tr>@endforelse
</tbody></table></x-data-table>
