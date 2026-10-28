<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrollments', function (Blueprint $t) {
            $t->unique(['school_id', 'academic_year_id', 'class_id', 'student_id', 'id'], 'enrollment_marks_reference');
        });
        Schema::create('student_marks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['academic_year_id', 'class_id', 'exam_id', 'exam_subject_id', 'enrollment_id', 'student_id', 'subject_id'] as $field) {
                $t->unsignedBigInteger($field);
            }
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'exam_id'], 'marks_exam_fk')->references(['school_id', 'academic_year_id', 'class_id', 'id'])->on('exams')->restrictOnDelete();
            $t->foreign(['school_id', 'exam_id', 'subject_id', 'exam_subject_id'], 'marks_subject_fk')->references(['school_id', 'exam_id', 'subject_id', 'id'])->on('exam_subjects')->restrictOnDelete();
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'student_id', 'enrollment_id'], 'marks_enrollment_fk')->references(['school_id', 'academic_year_id', 'class_id', 'student_id', 'id'])->on('student_enrollments')->restrictOnDelete();
            $t->decimal('theory_marks', 6, 2)->nullable();
            $t->decimal('practical_marks', 6, 2)->nullable();
            $t->decimal('total_marks', 7, 2)->nullable();
            $t->boolean('is_absent')->default(false);
            $t->string('remarks', 500)->nullable();
            $t->string('status', 20)->default('DRAFT');
            $t->unsignedInteger('lock_version')->default(1);
            foreach (['entered_by', 'submitted_by', 'verified_by', 'locked_by'] as $field) {
                $t->unsignedBigInteger($field)->nullable();
                $t->foreign(['school_id', $field])->references(['school_id', 'id'])->on('users')->restrictOnDelete();
            }
            foreach (['submitted_at', 'verified_at', 'locked_at'] as $field) {
                $t->timestamp($field)->nullable();
            }
            $t->timestamps();
            $t->unique(['school_id', 'exam_id', 'student_id', 'subject_id'], 'student_marks_identity');
            $t->index(['school_id', 'exam_subject_id', 'status']);
        });
        DB::statement("ALTER TABLE student_marks ADD CONSTRAINT student_marks_status CHECK (status IN ('DRAFT','SUBMITTED','VERIFIED','LOCKED')), ADD CONSTRAINT student_marks_nonnegative CHECK ((theory_marks IS NULL OR theory_marks >= 0) AND (practical_marks IS NULL OR practical_marks >= 0)), ADD CONSTRAINT student_marks_total CHECK (total_marks IS NULL OR total_marks = COALESCE(theory_marks,0) + COALESCE(practical_marks,0)), ADD CONSTRAINT student_marks_absence CHECK (is_absent = 0 OR (theory_marks IS NULL AND practical_marks IS NULL AND total_marks IS NULL)), ADD CONSTRAINT student_marks_submission CHECK (status = 'DRAFT' OR (submitted_by IS NOT NULL AND submitted_at IS NOT NULL AND (is_absent = 1 OR total_marks IS NOT NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('student_marks');
        Schema::table('student_enrollments', fn (Blueprint $t) => $t->dropUnique('enrollment_marks_reference'));
    }
};
