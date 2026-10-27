<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            // When the respondent opened the form, and the device that sent it.
            $table->timestamp('started_at')->nullable()->after('drive');
            $table->string('ip_address', 45)->nullable()->after('started_at');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'ip_address', 'user_agent']);
        });
    }
};
