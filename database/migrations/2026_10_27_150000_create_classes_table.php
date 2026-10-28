<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['school_id', 'code']);
            $table->unique(['school_id', 'name']);
            $table->unique(['school_id', 'id']);
            $table->index(['school_id', 'sort_order', 'name']);
            $table->index(['school_id', 'status', 'sort_order']);
        });
        DB::statement("ALTER TABLE classes ADD CONSTRAINT school_class_status CHECK (status IN ('ACTIVE','INACTIVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
