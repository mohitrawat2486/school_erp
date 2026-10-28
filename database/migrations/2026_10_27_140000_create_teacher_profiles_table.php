<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('employee_id')->unique();
            $table->foreign(['school_id', 'employee_id'])->references(['school_id', 'id'])->on('employees')->restrictOnDelete();
            $table->text('qualification')->nullable();
            $table->string('specialization')->nullable();
            $table->decimal('experience', 4, 2)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'status']);
        });
        DB::statement("ALTER TABLE teacher_profiles ADD CONSTRAINT teacher_profile_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT teacher_profile_experience CHECK (experience IS NULL OR experience >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
