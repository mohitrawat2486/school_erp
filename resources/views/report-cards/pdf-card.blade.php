<table class="brand"><tr>@if($logo)<td style="width:76px"><img src="{{ $logo }}" class="logo" alt="School logo"></td>@endif<td><h1>{{ $school['name']??'School Report Card' }}</h1><div class="address">{{ $school['address']??'Address not recorded at publication' }}</div></td></tr></table>
<div class="title"><h2>STUDENT REPORT CARD</h2>{{ $result->academic_year_name }} · {{ $result->exam_name }}</div>
<table class="info"><tr><td><div class="label">Student name</div><div class="value">{{ $result->student_name }}</div></td><td><div class="label">Admission number</div><div class="value">{{ $identity['admission_number']??'Not recorded at publication' }}</div></td></tr><tr><td><div class="label">Class / Section</div><div class="value">{{ $result->class_name }} / {{ $result->section_name }}</div></td><td><div class="label">Roll number</div><div class="value">{{ $result->roll_number }}</div></td></tr><tr><td><div class="label">Date of birth</div><div class="value">{{ $identity['date_of_birth']??'Not recorded at publication' }}</div></td><td><div class="label">Academic year</div><div class="value">{{ $result->academic_year_name }}</div></td></tr></table>
<table class="marks"><thead><tr><th>Subject</th><th>Theory / Max</th><th>Practical / Max</th><th>Total / Max</th><th>Grade</th><th>Result</th></tr></thead><tbody>
@foreach($subjects as $subject)
<tr><td>{{ $subject->subject_name }}</td>
@foreach(['theory','practical'] as $part)
<td>
@if($subject->{$part.'_max_marks'}===null)
—
@elseif($subject->is_absent)
Absent
@else
{{ $subject->{$part.'_marks'} }} / {{ $subject->{$part.'_max_marks'} }}
@endif
</td>
@endforeach
<td>{{ $subject->obtained_marks }} / {{ $subject->max_marks }}</td><td>{{ $subject->grade }}</td><td>{{ $subject->result_status }}</td></tr>
@endforeach
</tbody></table>
<table class="totals keep"><tr><td><div class="label">Total marks</div><strong>{{ $result->total_max_marks }}</strong></td><td><div class="label">Obtained marks</div><strong>{{ $result->obtained_marks }}</strong></td><td><div class="label">Percentage</div><strong>{{ $result->percentage }}%</strong></td></tr><tr><td><div class="label">Grade</div><strong>{{ $result->grade }}</strong></td><td><div class="label">Result status</div><strong>{{ $result->result_status }}</strong></td><td><div class="label">Class rank</div><strong>{{ $result->rank??'—' }}</strong></td></tr></table>
<div class="remarks"><strong>Teacher remarks</strong>
@if($result->remarks)<p>{{ $result->remarks }}</p>@endif
@foreach($subjects->filter(fn($row)=>filled($row->remarks)) as $subject)
<p>{{ $subject->subject_name }}: {{ $subject->remarks }}</p>
@endforeach
@if(!$result->remarks&&!$subjects->contains(fn($row)=>filled($row->remarks)))
<p>No remarks recorded.</p>
@endif
</div>
<table class="signatures"><tr><td><div>Class Teacher signature</div></td><td><div>Principal signature</div></td></tr></table>
<div class="footer">Published {{ $result->published_at->format('d M Y') }} · Version {{ $result->version }}<br>Values are retained from the published result. Blank signature lines are for authorized manual signing.</div>
