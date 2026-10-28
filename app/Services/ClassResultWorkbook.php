<?php

namespace App\Services;

use App\Models\StudentResultSubject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use XMLWriter;
use ZipArchive;

class ClassResultWorkbook
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function create(iterable $results): string
    {
        $results = collect($results);
        if ($results->isEmpty()) {
            throw ValidationException::withMessages(['results' => 'Choose at least one published result.']);
        }
        $query = StudentResultSubject::whereIn('student_result_id', $results->pluck('id'));
        if ((clone $query)->count() > (int) config('exports.max_subject_rows', 100000)) {
            throw ValidationException::withMessages(['results' => 'Choose a smaller section or search selection for this detailed export.']);
        }
        $subjects = (clone $query)->select('subject_id')->selectRaw('MIN(subject_name) as subject_name')->groupBy('subject_id')->orderBy('subject_id')->get();
        if ($subjects->count() > (int) config('exports.max_subject_columns', 500)) {
            throw ValidationException::withMessages(['results' => 'This export exceeds the supported number of subject columns.']);
        }
        $labels = [];
        $used = [];
        foreach ($subjects as $subject) {
            $name = $subject->subject_name;
            $used[$name] = ($used[$name] ?? 0) + 1;
            $labels[$subject->subject_id] = $name.($used[$name] > 1 ? ' ('.$used[$name].')' : '');
        }
        $summary = ['Roll Number', 'Admission Number', 'Student Name', 'Section', ...array_map(fn ($name) => $name.' (Marks)', array_values($labels)), 'Total', 'Maximum', 'Percentage', 'Grade', 'Grade Point', 'Result', 'Rank', 'Academic Year', 'Exam', 'Class', 'Version', 'Published'];
        $details = ['Roll Number', 'Admission Number', 'Student Name', 'Section', 'Subject', 'Theory Marks', 'Theory Maximum', 'Practical Marks', 'Practical Maximum', 'Subject Total', 'Subject Maximum', 'Percentage', 'Passing Marks', 'Grade', 'Grade Point', 'Result', 'Absent', 'Remarks', 'Academic Year', 'Exam', 'Class', 'Version', 'Published'];
        $path = tempnam(sys_get_temp_dir(), 'school-results-');
        if ($path === false) {
            throw new \RuntimeException('Cannot allocate the workbook file.');
        }
        $folder = $path.'-parts';
        $zip = new ZipArchive;
        try {
            File::makeDirectory($folder, 0700);
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create Excel workbook.');
            }
            $main = $this->startSheet($folder.'/summary.xml', $summary, 3);
            $detail = $this->startSheet($folder.'/details.xml', $details, 3);
            $row = 2;
            $detailRow = 2;
            $subjectCount = count($labels);
            $totalColumn = 4 + $subjectCount;
            foreach ($results->chunk(100) as $chunk) {
                $models = new Collection($chunk->map(fn ($model) => $model->withoutRelations())->all());
                $models->load('subjects');
                foreach ($models as $r) {
                    $admission = $r->report_context['admission_number'] ?? 'Not recorded at publication';
                    $byId = $r->subjects->keyBy('subject_id');
                    $marks = [];
                    foreach ($labels as $id => $name) {
                        $subject = $byId->get($id);
                        $marks[] = $subject?->is_absent ? 'ABSENT' : $subject?->obtained_marks;
                    }
                    $values = [$r->roll_number, $admission, $r->student_name, $r->section_name, ...$marks, $r->obtained_marks, $r->total_max_marks, $r->percentage, $r->grade, $r->grade_point, $r->result_status, $r->rank, $r->academic_year_name, $r->exam_name, $r->class_name, $r->version, $r->published_at->format('Y-m-d H:i:s')];
                    $numeric = [0 => 0, $totalColumn => 2, $totalColumn + 1 => 2, $totalColumn + 2 => 3, $totalColumn + 4 => 2, $totalColumn + 6 => 0, $totalColumn + 10 => 0];
                    foreach ($marks as $i => $value) {
                        if ($value !== null && $value !== 'ABSENT') {
                            $numeric[4 + $i] = 2;
                        }
                    }
                    $this->row($main, $row++, $values, $numeric);
                    foreach ($r->subjects->sortBy('subject_id') as $subject) {
                        $values = [$r->roll_number, $admission, $r->student_name, $r->section_name, $labels[$subject->subject_id], $subject->theory_marks, $subject->theory_max_marks, $subject->practical_marks, $subject->practical_max_marks, $subject->obtained_marks, $subject->max_marks, $subject->percentage, $subject->passing_marks, $subject->grade, $subject->grade_point, $subject->result_status, $subject->is_absent ? 'Yes' : 'No', $subject->remarks, $r->academic_year_name, $r->exam_name, $r->class_name, $r->version, $r->published_at->format('Y-m-d H:i:s')];
                        $this->row($detail, $detailRow++, $values, [0 => 0, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2, 10 => 2, 11 => 3, 12 => 2, 14 => 2, 21 => 0]);
                    }
                }
            }
            $this->finishSheet($main, count($summary), $row - 1);
            $this->finishSheet($detail, count($details), $detailRow - 1);
            $info = $this->startSheet($folder.'/info.xml', ['Field', 'Value'], 0);
            $first = $results->first();
            $infoRows = [['Academic Year', $first->academic_year_name], ['Exam', $first->exam_name], ['Class', $first->class_name], ['Students', (string) $results->count()], ['Source', 'Immutable published result snapshots'], ['Ranks', 'Original published class ranks; filters do not recalculate ranks.'], ['Admission Numbers', 'Older publications without a captured admission number are explicitly marked.'], ['Subject Marks', 'Summary cells show saved subject totals; ABSENT is distinct from numeric zero.'], ['Detailed Sheet', 'Subject Results includes components, maxima, passing marks and saved remarks.'], ['Repeated Names', 'Repeated subject names are numbered in stable subject order.']];
            foreach ($infoRows as $i => $values) {
                $this->row($info, $i + 2, $values, []);
            }
            $this->finishSheet($info, 2, count($infoRows) + 1);
            $types = '';
            $rels = '';
            $sheets = '';
            foreach (['Class Results', 'Subject Results', 'Export Notes'] as $i => $name) {
                $n = $i + 1;
                $types .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
                $sheets .= '<sheet name="'.$name.'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>'.$sheets.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
            $zip->addFromString('xl/styles.xml', $this->styles());
            foreach (['summary', 'details', 'info'] as $i => $name) {
                $zip->addFile($folder.'/'.$name.'.xml', 'xl/worksheets/sheet'.($i + 1).'.xml');
            }
            if (! $zip->close()) {
                throw new \RuntimeException('Cannot finish the Excel workbook.');
            }

            return $path;
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\Throwable) {
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        } finally {
            unset($main, $detail, $info);
            File::deleteDirectory($folder);
        }
    }

    private function startSheet(string $path, array $headers, int $frozen): XMLWriter
    {
        $xml = new XMLWriter;
        if (! $xml->openUri($path)) {
            throw new \RuntimeException('Cannot create worksheet.');
        }
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', self::NS);
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '1');
        if ($frozen) {
            $xml->writeAttribute('xSplit', (string) $frozen);
        }
        $xml->writeAttribute('topLeftCell', $this->column($frozen + 1).'2');
        $xml->writeAttribute('activePane', $frozen ? 'bottomRight' : 'bottomLeft');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('cols');
        foreach ($headers as $i => $name) {
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) ($i + 1));
            $xml->writeAttribute('max', (string) ($i + 1));
            $xml->writeAttribute('width', in_array($name, ['Student Name', 'Remarks', 'Value'], true) ? '32' : (str_contains($name, 'Exam') ? '28' : '19'));
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('sheetData');
        $this->row($xml, 1, $headers, [], true);

        return $xml;
    }

    private function finishSheet(XMLWriter $xml, int $columns, int $rows): void
    {
        $xml->endElement();
        $xml->startElement('autoFilter');
        $xml->writeAttribute('ref', 'A1:'.$this->column($columns).$rows);
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    private function row(XMLWriter $xml, int $index, array $values, array $numeric, bool $header = false): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $index);
        if ($header) {
            $xml->writeAttribute('ht', '34');
            $xml->writeAttribute('customHeight', '1');
        }
        foreach ($values as $i => $value) {
            $xml->startElement('c');
            $xml->writeAttribute('r', $this->column($i + 1).$index);
            $xml->writeAttribute('s', (string) ($header ? 1 : ($numeric[$i] ?? 4)));
            if ($value !== null) {
                $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value);
                if (array_key_exists($i, $numeric)) {
                    $xml->writeElement('v', $text);
                } else {
                    $xml->writeAttribute('t', 'inlineStr');
                    $xml->startElement('is');
                    $xml->startElement('t');
                    $xml->writeAttribute('xml:space', 'preserve');
                    $xml->text($text);
                    $xml->endElement();
                    $xml->endElement();
                }
            }
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function column(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + $number % 26).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="'.self::NS.'"><numFmts count="1"><numFmt numFmtId="164" formatCode="0.00&quot;%&quot;"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF8B2F24"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
