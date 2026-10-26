<?php

namespace App\Support;

use App\Models\ReferenceList;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Options a dropdown takes from elsewhere (FormGear's sourceAPI / offline lookup): a reference list
 * uploaded to this app, or an outside JSON API, optionally filtered by an earlier answer so that
 * choices cascade (kecamatan → desa).
 */
class Lookup
{
    public const SOURCES = ['reference' => 'Data referensi', 'api' => 'API (URL)'];

    public const MAX_OPTIONS = 5000;

    /**
     * @param  array<string, mixed>  $question
     */
    public static function isLookup(array $question): bool
    {
        return ($question['type'] ?? null) === 'dropdown' && in_array($question['lookup']['source'] ?? null, array_keys(self::SOURCES), true);
    }

    /**
     * The answer of the question this lookup depends on, as the value to filter by.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $known
     */
    public static function parentValue(array $question, array $known): ?string
    {
        $parent = (string) ($question['lookup']['depends_on'] ?? '');

        if ($parent === '') {
            return null;
        }

        $value = $known[$parent] ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : '';
    }

    /**
     * The options for the given answers: a list of {value, label}, or null when the outside API
     * cannot be reached (any answer is then accepted rather than blocking the respondent).
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $known
     * @return list<array{value: string, label: string}>|null
     */
    public static function options(array $question, array $known): ?array
    {
        $parent = self::parentValue($question, $known);

        // A dependent dropdown stays empty until the question it depends on is answered.
        if ($parent === '') {
            return [];
        }

        return self::optionsFor($question, $parent);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return list<array{value: string, label: string}>|null
     */
    public static function optionsFor(array $question, ?string $parent): ?array
    {
        $lookup = $question['lookup'] ?? [];

        if (($lookup['source'] ?? null) === 'reference') {
            $list = ReferenceList::query()->where('slug', (string) ($lookup['reference'] ?? ''))->first();

            return $list?->options(
                (string) ($lookup['value_column'] ?? ''),
                (string) (($lookup['label_column'] ?? '') ?: ($lookup['value_column'] ?? '')),
                $parent === null ? null : (string) ($lookup['filter_column'] ?? ''),
                $parent,
            ) ?? [];
        }

        return self::fromApi($lookup, $parent);
    }

    /**
     * @param  array<string, mixed>  $lookup
     * @return list<array{value: string, label: string}>|null
     */
    private static function fromApi(array $lookup, ?string $parent): ?array
    {
        $url = (string) ($lookup['url'] ?? '');

        if (! preg_match('/^https?:\/\//i', $url)) {
            return null;
        }

        $param = trim((string) ($lookup['param'] ?? ''));
        if ($parent !== null && $param !== '') {
            // "{value}" in the URL stands for the parent answer (sub-resources); otherwise it is a query parameter.
            $url = str_contains($url, '{value}')
                ? str_replace('{value}', rawurlencode($parent), $url)
                : $url.(str_contains($url, '?') ? '&' : '?').rawurlencode($param).'='.rawurlencode($parent);
        } elseif ($parent !== null && str_contains($url, '{value}')) {
            $url = str_replace('{value}', rawurlencode($parent), $url);
        }

        try {
            $payload = Cache::remember('lookup:'.sha1($url), now()->addMinutes(10), fn () => Http::timeout(6)->acceptJson()->get($url)->throw()->json());
        } catch (Throwable) {
            return null;
        }

        $items = ($lookup['data_path'] ?? '') === '' ? $payload : Arr::get((array) $payload, (string) $lookup['data_path']);

        if (! is_array($items)) {
            return null;
        }

        $options = [];
        $seen = [];
        foreach (array_slice(array_values($items), 0, self::MAX_OPTIONS) as $item) {
            $value = is_array($item) ? Arr::get($item, (string) ($lookup['value_key'] ?? 'id')) : $item;
            $label = is_array($item) ? Arr::get($item, (string) (($lookup['label_key'] ?? '') ?: ($lookup['value_key'] ?? 'id'))) : $item;

            if (! is_scalar($value) || (string) $value === '' || isset($seen[(string) $value])) {
                continue;
            }

            $seen[(string) $value] = true;
            $options[] = ['value' => (string) $value, 'label' => is_scalar($label) && (string) $label !== '' ? (string) $label : (string) $value];
        }

        return $options;
    }

    /**
     * The label of a chosen value, if the options are known.
     *
     * @param  list<array{value: string, label: string}>|null  $options
     */
    public static function labelOf(?array $options, mixed $value): ?string
    {
        foreach ($options ?? [] as $option) {
            if ($option['value'] === (string) $value) {
                return $option['label'];
            }
        }

        return null;
    }
}
