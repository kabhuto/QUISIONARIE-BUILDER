<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('surveys', function (Blueprint $table) {
            $table->renameColumn('questions', 'blocks');
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->string('form_code', 40)->nullable()->after('title');
            $table->string('subtitle', 500)->nullable()->after('form_code');
            $table->json('settings')->nullable()->after('activity');
        });

        DB::table('surveys')->orderBy('id')->each(function (object $survey) {
            $questions = json_decode($survey->blocks, true) ?? [];

            DB::table('surveys')->where('id', $survey->id)->update([
                'blocks' => json_encode([[
                    'id' => 'b-legacy',
                    'title' => 'KETERANGAN UMUM',
                    'questions' => array_map(
                        fn (array $question): array => $question + ['code_length' => 0, 'options' => []],
                        $questions,
                    ),
                ]]),
                'settings' => json_encode([
                    'paper_size' => 'A4',
                    'orientation' => 'portrait',
                    'period_type' => 'triwulan',
                    'period_value' => 1,
                ]),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('surveys')->orderBy('id')->each(function (object $survey) {
            $blocks = json_decode($survey->blocks, true) ?? [];

            DB::table('surveys')->where('id', $survey->id)->update([
                'blocks' => json_encode(array_merge([], ...array_column($blocks, 'questions'))),
            ]);
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->dropColumn(['form_code', 'subtitle', 'settings']);
        });

        Schema::table('surveys', function (Blueprint $table) {
            $table->renameColumn('blocks', 'questions');
        });
    }
};
