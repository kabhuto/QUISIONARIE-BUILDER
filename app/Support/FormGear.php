<?php

namespace App\Support;

use App\Models\Survey;
use Illuminate\Validation\ValidationException;

/**
 * Exchange with FormGear (the engine behind BPS FASIH). Filled in by the FormGear stage.
 */
class FormGear
{
    public static function isTemplate(mixed $data): bool
    {
        return is_array($data) && isset($data['components'], $data['dataKey']) && is_array($data['components']);
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, mixed>|null  $validation
     * @param  array<string, mixed>|null  $preset
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function import(array $template, ?array $validation = null, ?array $preset = null): array
    {
        throw ValidationException::withMessages(['template' => 'Impor template FormGear belum tersedia.']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function export(Survey $survey): array
    {
        return [];
    }
}
