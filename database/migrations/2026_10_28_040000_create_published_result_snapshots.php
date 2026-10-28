<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_batches', function (Blueprint $t) {
            $t->timestamp('snapshots_completed_at')->nullable();
        });
        Schema::table('result_drafts', fn (Blueprint $t) => $t->unique(['school_id', 'id']));
        Schema::create('student_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['academic_year_id', 'class_id', 'exam_id', 'student_id', 'enrollment_id', 'section_id', 'result_batch_id', 'source_draft_id', 'grading_scheme_id'] as $f) {
                $t->unsignedBigInteger($f);
            }
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'exam_id'], 'snapshot_exam_fk')->references(['school_id', 'academic_year_id', 'class_id', 'id'])->on('exams')->restrictOnDelete();
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'student_id', 'enrollment_id'], 'snapshot_enrollment_fk')->references(['school_id', 'academic_year_id', 'class_id', 'student_id', 'id'])->on('student_enrollments')->restrictOnDelete();
            $t->foreign(['school_id', 'class_id', 'section_id'], 'snapshot_section_fk')->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
            foreach (['result_batch_id' => 'result_batches', 'source_draft_id' => 'result_drafts', 'grading_scheme_id' => 'grading_schemes'] as $f => $table) {
                $t->foreign(['school_id', $f])->references(['school_id', 'id'])->on($table)->restrictOnDelete();
            }
            $t->string('student_name', 500);
            foreach (['exam_name', 'academic_year_name', 'class_name', 'section_name', 'grading_scheme_name'] as $f) {
                $t->string($f, 100);
            }
            $t->unsignedInteger('roll_number');
            $t->unsignedInteger('grading_scheme_version');
            $t->decimal('total_max_marks', 13, 2);
            $t->decimal('obtained_marks', 13, 2);
            $t->decimal('percentage', 5, 2);
            $t->string('grade', 20);
            $t->decimal('grade_point', 5, 2)->nullable();
            $t->decimal('grade_min_percentage', 5, 2);
            $t->decimal('grade_max_percentage', 5, 2);
            $t->string('grade_remarks', 500)->nullable();
            $t->unsignedInteger('rank')->nullable();
            $t->string('result_status', 20);
            $t->string('remarks', 500)->nullable();
            $t->timestamp('published_at');
            $t->unsignedInteger('version');
            $t->string('source_payload_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['school_id', 'exam_id', 'student_id', 'version'], 'snapshot_student_version');
            $t->unique(['school_id', 'result_batch_id', 'enrollment_id'], 'snapshot_batch_enrollment');
            $t->unique(['school_id', 'source_draft_id']);
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'academic_year_id', 'exam_id', 'class_id', 'section_id'], 'snapshot_cohort_index');
        });
        Schema::create('student_result_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('student_result_id');
            $t->foreign(['school_id', 'student_result_id'])->references(['school_id', 'id'])->on('student_results')->restrictOnDelete();
            $t->unsignedBigInteger('subject_id');
            $t->string('subject_name', 150);
            $t->unsignedBigInteger('exam_subject_id');
            $t->unsignedBigInteger('marks_id');
            $t->unsignedInteger('marks_version');
            foreach (['theory_marks', 'practical_marks', 'theory_max_marks', 'practical_max_marks', 'passing_marks'] as $f) {
                $t->decimal($f, 6, 2)->nullable();
            }
            $t->decimal('max_marks', 7, 2);
            $t->decimal('obtained_marks', 7, 2);
            $t->decimal('percentage', 5, 2);
            $t->string('grade', 20);
            $t->decimal('grade_point', 5, 2)->nullable();
            $t->unsignedBigInteger('grade_rule_id');
            $t->decimal('grade_min_percentage', 5, 2);
            $t->decimal('grade_max_percentage', 5, 2);
            $t->string('grade_remarks', 500)->nullable();
            $t->boolean('is_absent');
            $t->string('result_status', 20);
            $t->string('remarks', 500)->nullable();
            $t->timestamp('created_at');
            $t->unique(['school_id', 'student_result_id', 'subject_id'], 'snapshot_subject_unique');
        });
        DB::statement("ALTER TABLE student_results ADD CONSTRAINT snapshot_result_status CHECK (result_status IN ('PASS','FAIL')), ADD CONSTRAINT snapshot_totals CHECK (total_max_marks > 0 AND obtained_marks >= 0 AND obtained_marks <= total_max_marks AND percentage BETWEEN 0 AND 100), ADD CONSTRAINT snapshot_version CHECK (version > 0 AND (`rank` IS NULL OR (`rank` > 0 AND result_status = 'PASS')))");
        DB::statement("ALTER TABLE student_result_subjects ADD CONSTRAINT snapshot_subject_status CHECK (result_status IN ('PASS','FAIL','ABSENT')), ADD CONSTRAINT snapshot_subject_totals CHECK (max_marks > 0 AND obtained_marks >= 0 AND obtained_marks <= max_marks AND percentage BETWEEN 0 AND 100), ADD CONSTRAINT snapshot_absence CHECK ((is_absent = 0 AND result_status != 'ABSENT') OR (is_absent = 1 AND result_status = 'ABSENT' AND theory_marks IS NULL AND practical_marks IS NULL AND obtained_marks = 0))");
        foreach (['student_results', 'student_result_subjects'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $action) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_".strtolower($action)." BEFORE $action ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Published result snapshots are immutable'");
            }
        }
        DB::unprepared("CREATE TRIGGER student_results_insert_guard BEFORE INSERT ON student_results FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM result_batches b JOIN result_drafts d ON d.id = NEW.source_draft_id AND d.school_id = b.school_id AND d.result_batch_id = b.id AND d.student_id = NEW.student_id AND d.enrollment_id = NEW.enrollment_id WHERE b.id = NEW.result_batch_id AND b.school_id = NEW.school_id AND b.published_at = NEW.published_at AND b.grading_scheme_id = NEW.grading_scheme_id AND b.status = 'PUBLISHED' AND b.snapshots_completed_at IS NULL AND b.revision = NEW.version AND b.exam_id = NEW.exam_id AND b.academic_year_id = NEW.academic_year_id AND b.class_id = NEW.class_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Snapshot batch is not open for archival'; END IF; END");
        DB::unprepared("CREATE TRIGGER student_result_subjects_insert_guard BEFORE INSERT ON student_result_subjects FOR EACH ROW BEGIN IF NOT EXISTS (SELECT 1 FROM student_results r JOIN result_batches b ON b.id = r.result_batch_id AND b.school_id = r.school_id WHERE r.id = NEW.student_result_id AND r.school_id = NEW.school_id AND b.status = 'PUBLISHED' AND b.snapshots_completed_at IS NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Snapshot subjects are sealed'; END IF; END");
        DB::unprepared("CREATE TRIGGER result_batches_snapshot_seal BEFORE UPDATE ON result_batches FOR EACH ROW BEGIN IF OLD.snapshots_completed_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archived publication batches are immutable'; END IF; END");
        foreach (['UPDATE', 'DELETE', 'INSERT'] as $action) {
            $ref = $action === 'INSERT' ? 'NEW' : 'OLD';
            DB::unprepared('CREATE TRIGGER result_drafts_sealed_'.strtolower($action)." BEFORE $action ON result_drafts FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM result_batches b WHERE b.id = $ref.result_batch_id AND b.school_id = $ref.school_id AND b.snapshots_completed_at IS NOT NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archived publication payloads are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['student_results_immutable_update', 'student_results_immutable_delete', 'student_result_subjects_immutable_update', 'student_result_subjects_immutable_delete', 'student_results_insert_guard', 'student_result_subjects_insert_guard', 'result_batches_snapshot_seal', 'result_drafts_sealed_update', 'result_drafts_sealed_delete', 'result_drafts_sealed_insert'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS $trigger");
        }Schema::dropIfExists('student_result_subjects');
        Schema::dropIfExists('student_results');
        Schema::table('result_drafts', fn (Blueprint $t) => $t->dropUnique(['school_id', 'id']));
        Schema::table('result_batches', fn (Blueprint $t) => $t->dropColumn('snapshots_completed_at'));
    }
};
