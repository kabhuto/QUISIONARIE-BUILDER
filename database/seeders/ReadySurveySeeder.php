<?php

namespace Database\Seeders;

/**
 * Ready-to-use questionnaires (e.g. the interns' daily attendance), published without responses,
 * so a new installation or a hosting has them at once: php artisan db:seed --class=ReadySurveySeeder.
 */
class ReadySurveySeeder extends DummySurveySeeder
{
    protected string $folder = 'seeders/surveys';
}
