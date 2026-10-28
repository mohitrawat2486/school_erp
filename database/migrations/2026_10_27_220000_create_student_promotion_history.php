<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE student_enrollments DROP CHECK enrollment_status, ADD CONSTRAINT enrollment_status CHECK (status IN ('ACTIVE','INACTIVE','WITHDRAWN','TRANSFERRED'))");
        Schema::table('student_enrollments', fn (Blueprint $t) => $t->unique(['school_id', 'student_id', 'id'], 'enrollment_student_reference'));
        Schema::create('promotion_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->uuid('operation_key');
            $t->string('request_hash', 64);
            $t->unsignedBigInteger('actor_id');
            $t->foreign(['school_id', 'actor_id'])->references(['school_id', 'id'])->on('users')->restrictOnDelete();
            $t->string('mode', 20);
            foreach (['source', 'target'] as $prefix) {
                foreach (['academic_year_id' => 'academic_years', 'class_id' => 'classes'] as $field => $table) {
                    $name = $prefix.'_'.$field;
                    $t->unsignedBigInteger($name)->nullable($prefix === 'target');
                    $t->foreign(['school_id', $name])->references(['school_id', 'id'])->on($table)->restrictOnDelete();
                }$t->unsignedBigInteger($prefix.'_section_id')->nullable($prefix === 'target');
                $t->foreign(['school_id', $prefix.'_class_id', $prefix.'_section_id'], $prefix.'_promotion_section_fk')->references(['school_id', 'class_id', 'id'])->on('sections')->restrictOnDelete();
            }
            $t->date('joined_at')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'operation_key']);
            $t->unique(['school_id', 'id']);
            $t->index(['school_id', 'created_at']);
        });
        DB::statement("ALTER TABLE promotion_batches ADD CONSTRAINT promotion_batch_shape CHECK ((mode = 'EXIT' AND target_academic_year_id IS NULL AND target_class_id IS NULL AND target_section_id IS NULL AND joined_at IS NULL) OR (mode = 'PLACEMENT' AND target_academic_year_id IS NOT NULL AND target_class_id IS NOT NULL AND target_section_id IS NOT NULL AND joined_at IS NOT NULL))");
        Schema::create('promotion_outcomes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('promotion_batch_id');
            $t->foreign(['school_id', 'promotion_batch_id'])->references(['school_id', 'id'])->on('promotion_batches')->restrictOnDelete();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('source_enrollment_id');
            $t->unsignedBigInteger('target_enrollment_id')->nullable();
            foreach (['source', 'target'] as $prefix) {
                $t->foreign(['school_id', 'student_id', $prefix.'_enrollment_id'], $prefix.'_promotion_enrollment_fk')->references(['school_id', 'student_id', 'id'])->on('student_enrollments')->restrictOnDelete();
            }
            $t->string('action', 20);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'source_enrollment_id'], 'promotion_source_once');
            $t->unique(['school_id', 'target_enrollment_id'], 'promotion_target_once');
            $t->index(['school_id', 'student_id', 'created_at']);
        });
        DB::statement("ALTER TABLE promotion_outcomes ADD CONSTRAINT promotion_outcome_shape CHECK ((action IN ('PROMOTE','REPEAT') AND target_enrollment_id IS NOT NULL AND target_enrollment_id <> source_enrollment_id) OR (action IN ('WITHDRAW','TRANSFER') AND target_enrollment_id IS NULL))");
    }

    public function down(): void
    {
        if (DB::table('student_enrollments')->whereIn('status', ['WITHDRAWN', 'TRANSFERRED'])->exists()) {
            throw new RuntimeException('Retain exit history before rolling back this migration.');
        }
        Schema::dropIfExists('promotion_outcomes');
        Schema::dropIfExists('promotion_batches');
        Schema::table('student_enrollments', fn (Blueprint $t) => $t->dropUnique('enrollment_student_reference'));
        DB::statement("ALTER TABLE student_enrollments DROP CHECK enrollment_status, ADD CONSTRAINT enrollment_status CHECK (status IN ('ACTIVE','INACTIVE'))");
    }
};
