@props(['links','student'=>null])
<x-data-table :records="$links" title="{{ $student ? 'Guardian contacts' : 'Linked students' }}" icon="users" :sticky-actions="false">
<table class="w-full text-left"><thead><tr><th>{{ $student ? 'Guardian' : 'Student' }}</th><th>Relationship</th><th>Contact settings</th><th>Link status</th></tr></thead><tbody>
@forelse($links as $link)
<tr><td>@if($student)<a href="{{ route('guardians.show',$link->guardian) }}" class="font-semibold text-[#8b2f24] underline">{{ $link->guardian->fullName() }}</a><p class="mt-1">{{ $link->guardian->phone }}</p><x-status-badge :value="$link->guardian->status->value" />@else<a href="{{ route('students.show',$link->student) }}" class="font-semibold text-[#8b2f24] underline">{{ $link->student->fullName() }}</a><p class="mt-1">{{ $link->student->admission_number }}</p>@endif</td><td>{{ ucfirst(strtolower($link->relationship->value)) }}</td><td>{{ $link->is_primary ? 'Primary contact' : 'Additional contact' }}@if($link->is_emergency_contact)<p class="mt-1 text-sm">Emergency contact</p>@endif</td><td><x-status-badge :value="$link->status->value" :label="$link->status->value === 'ACTIVE' ? 'LINKED' : 'UNLINKED'" /></td></tr>
@empty<tr><td colspan="4">No guardian relationships recorded.</td></tr>@endforelse
</tbody></table></x-data-table>
