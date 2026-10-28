<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_pdf_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('school_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->foreign(['school_id', 'user_id'])->references(['school_id', 'id'])->on('users')->restrictOnDelete();
            $t->string('label', 255);
            $t->json('snapshot_ids');
            $t->unsignedInteger('student_count');
            $t->string('status', 20)->default('QUEUED');
            $t->string('path')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'user_id', 'created_at']);
            $t->index('expires_at');
        });
        DB::statement("ALTER TABLE report_pdf_requests ADD CONSTRAINT report_pdf_status CHECK (status IN ('QUEUED','PROCESSING','READY','FAILED')), ADD CONSTRAINT report_pdf_count CHECK (student_count > 0), ADD CONSTRAINT report_pdf_ready CHECK (status != 'READY' OR (path IS NOT NULL AND finished_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_pdf_requests');
    }
};
