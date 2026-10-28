<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['academic_year_id', 'class_id', 'subject_id', 'exam_id', 'class_subject_id'] as $field) {
                $t->unsignedBigInteger($field);
            }
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'exam_id'], 'exam_subject_exam_fk')->references(['school_id', 'academic_year_id', 'class_id', 'id'])->on('exams')->restrictOnDelete();
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'subject_id', 'class_subject_id'], 'exam_subject_curriculum_fk')->references(['school_id', 'academic_year_id', 'class_id', 'subject_id', 'id'])->on('class_subjects')->restrictOnDelete();
            $t->decimal('theory_max_marks', 6, 2)->nullable();
            $t->decimal('practical_max_marks', 6, 2)->nullable();
            $t->decimal('passing_marks', 6, 2);
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['school_id', 'exam_id', 'class_subject_id'], 'exam_subject_allocation_unique');
            $t->unique(['school_id', 'exam_id', 'subject_id'], 'exam_subject_identity');
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'exam_id', 'subject_id', 'id'], 'exam_subject_marks_reference');
            $t->index(['school_id', 'exam_id', 'status']);
            $t->index(['school_id', 'class_subject_id']);
        });
        DB::statement("ALTER TABLE exam_subjects ADD CONSTRAINT exam_subject_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT exam_subject_nonnegative CHECK ((theory_max_marks IS NULL OR theory_max_marks >= 0) AND (practical_max_marks IS NULL OR practical_max_marks >= 0) AND passing_marks >= 0), ADD CONSTRAINT exam_subject_marks_range CHECK (COALESCE(theory_max_marks,0) + COALESCE(practical_max_marks,0) > 0 AND passing_marks <= COALESCE(theory_max_marks,0) + COALESCE(practical_max_marks,0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_subjects');
    }
};
