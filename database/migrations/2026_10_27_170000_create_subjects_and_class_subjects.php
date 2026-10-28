<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->string('code', 50);
            $t->string('name', 100);
            $t->string('type', 20)->default('CORE');
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['school_id', 'code']);
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'status', 'name']);
        });
        Schema::create('class_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            foreach (['academic_year_id' => 'academic_years', 'class_id' => 'classes', 'subject_id' => 'subjects'] as $field => $parent) {
                $t->unsignedBigInteger($field);
                $t->foreign(['school_id', $field])->references(['school_id', 'id'])->on($parent)->restrictOnDelete();
            }
            foreach (['theory_max_marks', 'practical_max_marks', 'passing_marks'] as $field) {
                $t->decimal($field, 6, 2)->nullable();
            }
            $t->string('status', 20)->default('ACTIVE');
            $t->timestamps();
            $t->unique(['school_id', 'academic_year_id', 'class_id', 'subject_id'], 'class_subject_allocation_unique');
            $t->unique(['school_id', 'academic_year_id', 'class_id', 'id'], 'class_subject_context_unique');
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'status', 'academic_year_id', 'class_id'], 'class_subject_filter_index');
        });
        DB::statement("ALTER TABLE subjects ADD CONSTRAINT subject_type CHECK (type IN ('CORE','OPTIONAL')), ADD CONSTRAINT subject_status CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE class_subjects ADD CONSTRAINT class_subject_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT class_subject_nonnegative_marks CHECK ((theory_max_marks IS NULL OR theory_max_marks >= 0) AND (practical_max_marks IS NULL OR practical_max_marks >= 0) AND (passing_marks IS NULL OR passing_marks >= 0)), ADD CONSTRAINT class_subject_marks_range CHECK ((theory_max_marks IS NULL AND practical_max_marks IS NULL AND passing_marks IS NULL) OR (COALESCE(theory_max_marks,0) + COALESCE(practical_max_marks,0) > 0 AND (passing_marks IS NULL OR passing_marks <= COALESCE(theory_max_marks,0) + COALESCE(practical_max_marks,0))))");
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('subjects');
    }
};
