<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tokens for the JSON API, assignments with pre-filled answers, and reference lists that
     * dropdowns can take their options from (e.g. districts and villages).
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->boolean('can_write')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->string('label')->nullable();
            $table->json('predata')->nullable();
            $table->json('locked')->nullable();
            $table->foreignId('response_id')->nullable()->constrained('survey_responses')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reference_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('columns');
            $table->json('rows');
            $table->timestamps();
        });

        Schema::table('survey_responses', function (Blueprint $table) {
            $table->string('source', 20)->default('form');
        });
    }

    public function down(): void
    {
        Schema::table('survey_responses', function (Blueprint $table) {
            $table->dropColumn('source');
        });
        Schema::dropIfExists('reference_lists');
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('api_tokens');
    }
};
