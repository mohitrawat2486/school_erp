<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['academic_year_id', 'class_id', 'exam_id', 'grading_scheme_id'] as $f) {
                $t->unsignedBigInteger($f);
            }
            $t->foreign(['school_id', 'academic_year_id', 'class_id', 'exam_id'], 'result_batch_exam_fk')->references(['school_id', 'academic_year_id', 'class_id', 'id'])->on('exams')->restrictOnDelete();
            $t->foreign(['school_id', 'grading_scheme_id'])->references(['school_id', 'id'])->on('grading_schemes')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->unsignedInteger('lock_version')->default(1);
            $t->string('status', 20)->default('GENERATED');
            $t->unsignedBigInteger('active_exam_id')->nullable()->storedAs("CASE WHEN status IN ('GENERATED','APPROVED','PUBLISHED') THEN exam_id ELSE NULL END");
            $t->unique(['school_id', 'active_exam_id']);
            $t->unique(['school_id', 'exam_id', 'revision']);
            $t->unique(['school_id', 'id']);
            $t->string('source_hash', 64);
            $t->string('payload_hash', 64);
            $t->json('calculation_context');
            foreach (['generated', 'approved', 'published', 'returned'] as $action) {
                $t->unsignedBigInteger($action.'_by')->nullable();
                $t->foreign(['school_id', $action.'_by'])->references(['school_id', 'id'])->on('users')->restrictOnDelete();
                $t->timestamp($action.'_at')->nullable();
            }
            $t->string('return_reason', 1000)->nullable();
            $t->timestamps();
            $t->index(['school_id', 'status']);
        });
        Schema::create('result_drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('result_batch_id');
            $t->foreign(['school_id', 'result_batch_id'])->references(['school_id', 'id'])->on('result_batches')->restrictOnDelete();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('enrollment_id');
            $t->foreign(['school_id', 'student_id', 'enrollment_id'], 'result_draft_enrollment_fk')->references(['school_id', 'student_id', 'id'])->on('student_enrollments')->restrictOnDelete();
            $t->json('payload');
            $t->timestamps();
            $t->unique(['school_id', 'result_batch_id', 'enrollment_id']);
        });
        DB::statement("ALTER TABLE result_batches ADD CONSTRAINT result_batch_status CHECK (status IN ('GENERATED','APPROVED','PUBLISHED','RETURNED')), ADD CONSTRAINT result_batch_revision CHECK (revision > 0 AND lock_version > 0), ADD CONSTRAINT result_batch_generated CHECK (generated_by IS NOT NULL AND generated_at IS NOT NULL), ADD CONSTRAINT result_batch_approved CHECK (status NOT IN ('APPROVED','PUBLISHED') OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)), ADD CONSTRAINT result_batch_published CHECK (status != 'PUBLISHED' OR (published_by IS NOT NULL AND published_at IS NOT NULL)), ADD CONSTRAINT result_batch_returned CHECK (status != 'RETURNED' OR (returned_by IS NOT NULL AND returned_at IS NOT NULL AND return_reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('result_drafts');
        Schema::dropIfExists('result_batches');
    }
};
