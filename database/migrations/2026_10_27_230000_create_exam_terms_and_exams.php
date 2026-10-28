<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_terms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('academic_year_id');
            $t->foreign(['school_id', 'academic_year_id'])->references(['school_id', 'id'])->on('academic_years')->restrictOnDelete();
            $t->string('name', 100);
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['school_id', 'academic_year_id', 'name']);
            $t->unique(['school_id', 'academic_year_id', 'id'], 'exam_term_year_reference');
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'status', 'academic_year_id']);
        });
        Schema::create('exams', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('academic_year_id');
            $t->unsignedBigInteger('exam_term_id');
            $t->unsignedBigInteger('class_id');
            $t->foreign(['school_id', 'academic_year_id'])->references(['school_id', 'id'])->on('academic_years')->restrictOnDelete();
            $t->foreign(['school_id', 'academic_year_id', 'exam_term_id'], 'exam_term_context_fk')->references(['school_id', 'academic_year_id', 'id'])->on('exam_terms')->restrictOnDelete();
            $t->foreign(['school_id', 'class_id'])->references(['school_id', 'id'])->on('classes')->restrictOnDelete();
            $t->string('name', 100);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 20)->default('DRAFT');
            $t->timestamps();
            $t->unique(['school_id', 'academic_year_id', 'exam_term_id', 'class_id', 'name'], 'exam_name_unique');
            $t->unique(['school_id', 'id']);
            $t->unique(['school_id', 'academic_year_id', 'class_id', 'id'], 'exam_context_reference');
            $t->index(['school_id', 'academic_year_id', 'class_id', 'status'], 'exam_listing_index');
            $t->index(['school_id', 'exam_term_id', 'status']);
        });
        DB::statement("ALTER TABLE exam_terms ADD CONSTRAINT exam_term_status CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE exams ADD CONSTRAINT exam_status CHECK (status IN ('DRAFT','OPEN','MARKS_ENTRY','VERIFICATION','APPROVED','PUBLISHED','CLOSED')), ADD CONSTRAINT exam_dates CHECK (end_date >= start_date)");
    }

    public function down(): void
    {
        Schema::dropIfExists('exams');
        Schema::dropIfExists('exam_terms');
    }
};
