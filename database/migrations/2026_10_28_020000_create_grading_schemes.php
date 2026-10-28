<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_schemes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->string('name', 100);
            $t->string('status', 20)->default('INACTIVE');
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['school_id', 'name']);
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'status']);
        });
        Schema::create('grade_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('grading_scheme_id');
            $t->foreign(['school_id', 'grading_scheme_id'])->references(['school_id', 'id'])->on('grading_schemes')->restrictOnDelete();
            $t->decimal('min_percentage', 5, 2);
            $t->decimal('max_percentage', 5, 2);
            $t->string('grade', 20);
            $t->decimal('grade_point', 5, 2)->nullable();
            $t->string('remarks', 500)->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'grading_scheme_id', 'grade'], 'grade_rule_label_unique');
            $t->index(['school_id', 'grading_scheme_id', 'min_percentage'], 'grade_rule_range_index');
        });
        DB::statement("ALTER TABLE grading_schemes ADD CONSTRAINT grading_scheme_status CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement('ALTER TABLE grade_rules ADD CONSTRAINT grade_rule_range CHECK (min_percentage >= 0 AND max_percentage <= 100 AND min_percentage <= max_percentage), ADD CONSTRAINT grade_rule_point CHECK (grade_point IS NULL OR grade_point >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_rules');
        Schema::dropIfExists('grading_schemes');
    }
};
