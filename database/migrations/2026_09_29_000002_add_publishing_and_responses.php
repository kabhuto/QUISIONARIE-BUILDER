<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->after('id');
            $table->timestamp('published_at')->nullable()->after('blocks');
        });

        DB::table('surveys')->whereNull('slug')->orderBy('id')->each(function (object $survey) {
            DB::table('surveys')->where('id', $survey->id)->update(['slug' => Str::lower(Str::random(10))]);
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->unique('slug');
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained()->cascadeOnDelete();
            $table->json('answers');
            $table->timestamps();

            $table->index(['survey_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('survey_responses');

        Schema::table('surveys', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'published_at']);
        });
    }
};
