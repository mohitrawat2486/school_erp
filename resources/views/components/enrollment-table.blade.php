@props(['enrollments'])
<x-data-table :records="$enrollments" title="Student enrollments" icon="classes">
    <table class="w-full text-left">
        <thead><tr><th>Student</th><th>Academic year</th><th>Class / Section</th><th>Roll</th><th>Joined</th><th>Status / Outcome</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($enrollments as $record)
            @php($outcome = $record->promotionOutcome)
            @php($origin = $record->promotionOrigin)
            <tr>
                <td class="font-semibold">{{ $record->student->fullName() }}<p class="mt-1 text-xs font-normal text-slate-500">{{ $record->student->admission_number }}</p></td>
                <td>{{ $record->academicYear->name }}</td>
                <td>{{ $record->schoolClass->name }} / {{ $record->section->name }}</td>
                <td>{{ $record->roll_number }}</td>
                <td>{{ $record->joined_at->format('d M Y') }}</td>
                <td>
                    <x-status-badge :value="$record->status->value" />
                    @if($outcome && in_array($outcome->action->value, ['PROMOTE', 'REPEAT'], true))
                        <span class="enrollment-history-progression"><strong>{{ $outcome->action->value === 'REPEAT' ? 'Repeating' : 'Promoted' }}</strong> → {{ $outcome->targetEnrollment->academicYear->name }} · {{ $outcome->targetEnrollment->schoolClass->name }} / {{ $outcome->targetEnrollment->section->name }}</span>
                    @endif
                    @if($origin)
                        <span class="enrollment-history-progression">{{ $origin->action->value === 'REPEAT' ? 'Repeated from' : 'Promoted from' }} {{ $origin->sourceEnrollment->academicYear->name }} · {{ $origin->sourceEnrollment->schoolClass->name }} / {{ $origin->sourceEnrollment->section->name }}</span>
                    @endif
                    @if($outcome && in_array($outcome->action->value, ['WITHDRAW', 'TRANSFER'], true))
                        <details class="enrollment-history-departure"><summary>Departure details</summary>
                            @if($outcome->departure_date)<p>{{ $outcome->departure_date->format('d M Y') }}</p>@endif
                            <p>{{ $outcome->notes }}</p>
                            @if($outcome->destination_school)<p>Destination: {{ $outcome->destination_school }}</p>@endif
                        </details>
                    @endif
                </td>
                <td>
                    @can('update', $record->student)
                        <x-action-link :href="route('students.edit', ['student' => $record->student_id, 'enrollment_year' => $record->academic_year_id]).'#enrollment-heading'" label="View" icon="eye" />
                    @else
                        <x-action-link :href="route('enrollments.show', $record)" label="View" icon="eye" />
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="7">No enrollments match this selection.</td></tr>
        @endforelse
        </tbody>
    </table>
</x-data-table>
