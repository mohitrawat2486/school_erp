<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('class_id');
            $table->foreign(['school_id', 'class_id'])->references(['school_id', 'id'])->on('classes')->restrictOnDelete();
            $table->string('name', 50);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['school_id', 'class_id', 'name']);
            $table->unique(['school_id', 'class_id', 'id']);
            $table->index(['school_id', 'status', 'class_id']);
        });
        DB::statement("ALTER TABLE sections ADD CONSTRAINT section_status CHECK (status IN ('ACTIVE','INACTIVE')), ADD CONSTRAINT section_capacity CHECK (capacity IS NULL OR capacity > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
