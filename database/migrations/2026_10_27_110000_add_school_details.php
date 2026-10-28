<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            foreach (['short_name', 'logo_path', 'affiliation_number', 'board', 'email', 'phone', 'alternate_phone', 'address_line_1', 'address_line_2', 'city', 'state', 'country', 'postal_code'] as $field) {
                $table->string($field)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['short_name', 'logo_path', 'affiliation_number', 'board', 'email', 'phone', 'alternate_phone', 'address_line_1', 'address_line_2', 'city', 'state', 'country', 'postal_code']);
        });
    }
};
