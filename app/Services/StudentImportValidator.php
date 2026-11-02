<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\GuardianEditorRules;
use App\Support\StudentRules;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class StudentImportValidator
{
    private function key(string $value): string
    {
        return mb_strtolower(Str::ascii(trim($value)));
    }

    private function message(array &$row, string $field, string $status, string $message): void
    {
        $cell = $row['fields'][$field] ?? ['status' => 'valid', 'messages' => [], 'normalizedValue' => $row['values'][$field] ?? null];
        if ($cell['status'] !== 'invalid' || $status === 'invalid') {
            $cell['status'] = $status;
        }
        $cell['messages'][] = $message;
        $row['fields'][$field] = $cell;
    }

    private function date(array &$row, string $field, string $format): ?string
    {
        $value = $row['values'][$field] ?? '';
        if ($value === '') {
            return null;
        }
        $iso = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
        $candidate = $iso ? $value : str_replace('-', '/', $value);
        $expected = $iso ? 'Y-m-d' : $format;
        $pattern = $expected === 'Y-m-d' ? '/^\d{4}-\d{2}-\d{2}$/' : '/^\d{1,2}\/\d{1,2}\/\d{4}$/';
        $date = preg_match($pattern, $candidate) ? DateTimeImmutable::createFromFormat('!'.$expected, $candidate) : false;
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            $this->message($row, $field, 'invalid', 'Invalid date. Use '.(['d/m/Y' => 'DD/MM/YYYY', 'm/d/Y' => 'MM/DD/YYYY', 'Y-m-d' => 'YYYY-MM-DD'][$format]).' (or YYYY-MM-DD).');

            return $value;
        }
        $normalized = $date->format('Y-m-d');
        $row['values'][$field] = $normalized;
        if ($normalized !== $value) {
            $this->message($row, $field, 'warning', 'Date converted to '.$normalized.' using the chosen date format.');
        }

        return $normalized;
    }

    private function name(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2);

        return ['first_name' => $parts[0] ?? '', 'last_name' => $parts[1] ?? null];
    }

    public function validate(User $actor, array $input): array
    {
        $mappings = $input['mappings'];
        $settings = $input['settings'];
        $classes = SchoolClass::active()->get(['id', 'name', 'code']);
        $sections = Section::eligible()->get(['id', 'class_id', 'name', 'capacity']);
        $years = AcademicYear::where('status', 'ACTIVE')->get();
        $canPlace = $actor->can('student.update');
        $canGuardians = Gate::forUser($actor)->allows('create', Guardian::class);
        $prepared = [];
        foreach ($input['rows'] as $index => $cells) {
            $values = [];
            foreach ($mappings as $column => $field) {
                if ($field) {
                    $values[$field] = trim((string) ($cells[$column] ?? ''));
                }
            }
            $prepared[] = ['row' => $index + 1, 'values' => $values, 'fields' => [], 'messages' => [], 'status' => 'valid'];
        }
        $numbers = array_column(array_column($prepared, 'values'), 'admission_number');
        $frequencies = array_count_values(array_map(fn ($number) => $this->key($number), $numbers));
        $existing = [];
        foreach (array_chunk(array_values(array_unique($numbers)), 500) as $chunk) {
            foreach (Student::whereIn('admission_number', $chunk)->pluck('admission_number') as $number) {
                $existing[$this->key($number)] = true;
            }
        }
        $yearIds = $years->pluck('id');
        $occupied = StudentEnrollment::whereIn('academic_year_id', $yearIds)->selectRaw('academic_year_id, section_id, COUNT(*) AS reserved, SUM(status = ?) AS occupied, MAX(roll_number) AS last_roll', ['ACTIVE'])->groupBy('academic_year_id', 'section_id')->get()->keyBy(fn ($entry) => $entry->academic_year_id.':'.$entry->section_id);
        $rolls = StudentEnrollment::whereIn('academic_year_id', $yearIds)->get(['academic_year_id', 'section_id', 'roll_number'])->mapWithKeys(fn ($entry) => [$entry->academic_year_id.':'.$entry->section_id.':'.$entry->roll_number => true])->all();
        $batchRolls = [];
        foreach ($prepared as &$row) {
            foreach ($row['values'] as $field => $value) {
                $row['fields'][$field] = ['status' => $value === '' ? 'warning' : 'valid', 'messages' => $value === '' ? ['Empty optional value; required fields are checked below.'] : [], 'normalizedValue' => $value];
            }
            if (count($input['rows'][$row['row'] - 1]) !== count($mappings)) {
                $row['messages'][] = 'This row has a different number of columns. Correct the pasted row before importing.';
                $row['status'] = 'invalid';
            }
            $data = Arr::only($row['values'], StudentService::FIELDS);
            if (array_key_exists('student_name', $row['values'])) {
                $data += $this->name($row['values']['student_name']);
                if (str_contains($row['values']['student_name'], ' ')) {
                    $this->message($row, 'student_name', 'warning', 'Split into first name "'.$data['first_name'].'" and last name "'.($data['last_name'] ?? '').'". Review this split.');
                }
            }
            $data['admission_date'] = $row['values']['admission_date'] ?? ($settings['admission_date'] ?? null);
            foreach (['admission_date', 'date_of_birth', 'joined_at'] as $field) {
                if (array_key_exists($field, $row['values'])) {
                    $date = $this->date($row, $field, $settings['date_format']);
                    if ($field !== 'joined_at') {
                        $data[$field] = $date;
                    }
                }
            }
            if (! empty($data['gender'])) {
                $raw = $data['gender'];
                $data['gender'] = ['M' => 'MALE', 'F' => 'FEMALE', 'PREFER NOT TO SAY' => 'PREFER_NOT_TO_SAY'][strtoupper($raw)] ?? strtoupper($raw);
                $row['values']['gender'] = $data['gender'];
                if ($raw !== $data['gender']) {
                    $this->message($row, 'gender', 'warning', 'Gender interpreted as '.(Student::GENDERS[$data['gender']] ?? $data['gender']).'.');
                }
            }
            if (! empty($data['blood_group'])) {
                $data['blood_group'] = strtoupper($data['blood_group']);
                $row['values']['blood_group'] = $data['blood_group'];
            }
            $data = array_map(fn ($value) => $value === '' ? null : $value, $data);
            $validation = Validator::make($data, StudentRules::rules(checkUnique: false));
            foreach ($validation->errors()->messages() as $field => $messages) {
                $field = isset($row['values']['student_name']) && in_array($field, ['first_name', 'last_name'], true) ? 'student_name' : $field;
                foreach ($messages as $message) {
                    $this->message($row, $field, 'invalid', $message);
                }
            }
            $number = $this->key((string) ($data['admission_number'] ?? ''));
            if (isset($existing[$number])) {
                $this->message($row, 'admission_number', 'invalid', 'Admission number already exists in this school.');
            }
            if (($frequencies[$number] ?? 0) > 1 && $number !== '') {
                $this->message($row, 'admission_number', 'invalid', 'Admission number appears more than once in the pasted data.');
            }
            $guardians = [];
            $guardianKeys = [];
            foreach (['father' => 'FATHER', 'mother' => 'MOTHER', 'guardian' => 'GUARDIAN'] as $prefix => $relationship) {
                $name = $row['values'][$prefix.'_name'] ?? '';
                $phone = $row['values'][$prefix.'_phone'] ?? '';
                $email = $row['values'][$prefix.'_email'] ?? '';
                if ($name === '' && $phone === '' && $email === '') {
                    continue;
                }
                if (! $canGuardians) {
                    $this->message($row, $prefix.'_name', 'invalid', 'Guardian management requires student.update permission.');
                }
                $guardianKeys[count($guardians)] = $prefix;
                $guardians[] = ['kind' => 'new', 'relationship' => $relationship, 'is_emergency_contact' => true, 'details' => $this->name($name) + ['phone' => $phone, 'email' => $email ?: null, 'same_address' => true]];
                if ($phone !== '' && (! preg_match('/^\+?[0-9 ()\-.]+$/', $phone) || ! preg_match('/^[0-9]{7,15}$/', preg_replace('/\D/', '', $phone)))) {
                    $this->message($row, $prefix.'_phone', 'invalid', 'Use 7–15 phone digits with an optional +, spaces, brackets or hyphens.');
                }
            }
            if ($guardians) {
                $guardianData = ['guardians_present' => 1, 'guardians' => $guardians, 'primary_guardian' => '0'];
                $guardianValidation = Validator::make($guardianData, GuardianEditorRules::rules($guardianData));
                foreach ($guardianValidation->errors()->messages() as $path => $messages) {
                    $parts = explode('.', $path);
                    $prefix = $guardianKeys[$parts[1] ?? ''] ?? 'guardian';
                    $field = $parts[3] ?? 'name';
                    $field = in_array($field, ['first_name', 'last_name'], true) ? 'name' : $field;
                    foreach ($messages as $message) {
                        $this->message($row, $prefix.'_'.$field, 'invalid', str_replace($path, ucfirst($prefix).' '.$field, $message));
                    }
                }
                $data += $guardianData;
            }
            $placement = null;
            $className = $row['values']['class'] ?? '';
            $sectionName = $row['values']['section'] ?? '';
            if ($className !== '' || $sectionName !== '' || ($row['values']['roll_number'] ?? '') !== '' || ($row['values']['academic_year'] ?? '') !== '' || ($row['values']['joined_at'] ?? '') !== '') {
                if (! $canPlace) {
                    $this->message($row, 'class', 'invalid', 'Class placement requires student.update permission.');
                }
                $classMatches = $classes->filter(fn ($class) => $this->key($className) === $this->key($class->code));
                if ($classMatches->isEmpty()) {
                    $classMatches = $classes->filter(fn ($class) => $this->key($className) === $this->key($class->name));
                }
                if ($classMatches->isEmpty()) {
                    $plain = preg_replace('/^(class|grade)\s*/i', '', $className);
                    $classMatches = $classes->filter(fn ($class) => $this->key(preg_replace('/^(class|grade)\s*/i', '', $class->name)) === $this->key($plain));
                }
                $class = $classMatches->count() === 1 ? $classMatches->first() : null;
                if (! $class) {
                    $this->message($row, 'class', 'invalid', $classMatches->count() > 1 ? 'Class is ambiguous. Use its exact unique code.' : 'Choose an existing active class name or code.');
                } elseif ($className !== $class->name && $className !== $class->code) {
                    $this->message($row, 'class', 'warning', 'Class matched to '.$class->name.'.');
                }
                $matchingSections = $sections->where('class_id', $class?->id)->filter(fn ($section) => $this->key($section->name) === $this->key(preg_replace('/^section\s+/i', '', $sectionName)));
                $section = $matchingSections->count() === 1 ? $matchingSections->first() : null;
                if (! $section) {
                    $this->message($row, 'section', 'invalid', 'Choose an active section belonging to this class.');
                }
                $yearName = $row['values']['academic_year'] ?? '';
                $matches = $yearName !== '' ? $years->filter(fn ($year) => $this->key($year->name) === $this->key($yearName)) : $years->where('id', $settings['academic_year_id'] ?? null);
                $year = $matches->count() === 1 ? $matches->first() : null;
                if (! $year) {
                    $this->message($row, 'academic_year', 'invalid', 'Choose an active academic year in this school.');
                }
                $joined = $row['values']['joined_at'] ?? ($data['admission_date'] ?? null);
                if ($year && $joined) {
                    $dates = Validator::make(['joined_at' => $joined], ['joined_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$year->start_date->format('Y-m-d'), 'before_or_equal:'.$year->end_date->format('Y-m-d'), 'after_or_equal:'.($data['admission_date'] ?? '')]]);
                    foreach ($dates->errors()->all() as $message) {
                        $this->message($row, 'joined_at', 'invalid', $message);
                    }
                }
                $roll = $row['values']['roll_number'] ?? '';
                $rollValidation = Validator::make(['roll_number' => $roll === '' ? null : $roll], ['roll_number' => ['nullable', 'integer', 'between:1,4294967295']]);
                foreach ($rollValidation->errors()->all() as $message) {
                    $this->message($row, 'roll_number', 'invalid', $message);
                }
                if ($section && $year) {
                    $scope = $year->id.':'.$section->id;
                    if ($roll !== '' && ! $rollValidation->fails()) {
                        $key = $scope.':'.(int) $roll;
                        if (isset($rolls[$key])) {
                            $this->message($row, 'roll_number', 'invalid', 'Roll number is already reserved in this year and section.');
                        }
                        $batchRolls[$key][] = $row['row'] - 1;
                    }
                    $placement = ['academic_year_id' => $year->id, 'class_id' => $class?->id, 'section_id' => $section->id, 'roll_number' => $roll === '' ? null : (int) $roll, 'joined_at' => $joined];
                    $row['values']['class'] = $class?->name;
                    $row['values']['section'] = $section->name;
                    $row['values']['academic_year'] = $year->name;
                    if ($roll === '' && (int) ($occupied[$scope]->last_roll ?? 0) >= 4294967295) {
                        $this->message($row, 'roll_number', 'invalid', 'No automatic roll numbers remain in this section.');
                    }
                }
            }
            $row['data'] = $data;
            $row['enrollment'] = $placement;
        }
        unset($row);
        foreach ($batchRolls as $indices) {
            if (count($indices) > 1) {
                foreach ($indices as $index) {
                    $this->message($prepared[$index], 'roll_number', 'invalid', 'Roll number appears more than once for this year and section in the pasted data.');
                }
            }
        }
        $manualMax = [];
        foreach ($prepared as $row) {
            if ($row['enrollment'] && $row['enrollment']['roll_number'] && $row['status'] !== 'invalid' && ! collect($row['fields'])->contains('status', 'invalid')) {
                $key = $row['enrollment']['academic_year_id'].':'.$row['enrollment']['section_id'];
                $manualMax[$key] = max($manualMax[$key] ?? 0, $row['enrollment']['roll_number']);
            }
        }
        $automatic = [];
        $reserved = [];
        foreach ($prepared as &$row) {
            $row['status'] = $row['status'] === 'invalid' || collect($row['fields'])->contains('status', 'invalid') ? 'invalid' : (collect($row['fields'])->contains('status', 'warning') ? 'warning' : 'valid');
            if ($row['status'] !== 'invalid' && $row['enrollment']) {
                $p = $row['enrollment'];
                $scope = $p['academic_year_id'].':'.$p['section_id'];
                $capacity = $sections->firstWhere('id', $p['section_id'])?->capacity;
                if ($p['roll_number'] === null) {
                    $automatic[$scope] = ($automatic[$scope] ?? 0) + 1;
                    if (max((int) ($occupied[$scope]->last_roll ?? 0), $manualMax[$scope] ?? 0) + $automatic[$scope] > 4294967295) {
                        $this->message($row, 'roll_number', 'invalid', 'Automatic roll numbers would exceed the allowed maximum. Enter an unused roll number.');
                        $row['status'] = 'invalid';
                    }
                }
                if ($row['status'] !== 'invalid') {
                    if ($capacity !== null && ($occupied[$scope]->occupied ?? 0) + ($reserved[$scope] ?? 0) >= $capacity) {
                        $this->message($row, 'section', 'invalid', 'This section has reached its capacity for the academic year.');
                        $row['status'] = 'invalid';
                    } else {
                        $reserved[$scope] = ($reserved[$scope] ?? 0) + 1;
                    }
                }
            }
            foreach ($row['fields'] as $field => &$cell) {
                $cell['normalizedValue'] = $row['values'][$field] ?? null;
            }
            unset($cell);
        }
        unset($row);

        return ['rows' => $prepared, 'summary' => ['total' => count($prepared), 'valid' => count(array_filter($prepared, fn ($row) => $row['status'] === 'valid')), 'warning' => count(array_filter($prepared, fn ($row) => $row['status'] === 'warning')), 'invalid' => count(array_filter($prepared, fn ($row) => $row['status'] === 'invalid'))]];
    }
}
