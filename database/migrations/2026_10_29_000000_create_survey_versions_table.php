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
        // A fixed copy of the instrument each time it is published or changed while published.
        Schema::create('survey_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title');
            $table->json('blocks');
            $table->json('changes')->nullable();
            $table->timestamps();
            $table->unique(['survey_id', 'number']);
        });

        Schema::table('survey_responses', function (Blueprint $table) {
            // The instrument version the answers belong to, and the sender's own id for the
            // submission so a resend (after a lost connection) is not stored twice.
            $table->unsignedInteger('survey_version')->nullable()->after('survey_id');
            $table->string('submission_id', 64)->nullable()->after('survey_version');
            $table->unique(['survey_id', 'submission_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropUnique(['survey_id', 'submission_id']);
            $table->dropColumn(['survey_version', 'submission_id']);
        });
        Schema::dropIfExists('survey_versions');
    }
};
