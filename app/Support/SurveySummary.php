<?php

namespace App\Support;

use App\Models\Survey;
use App\Models\SurveyResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Per-question aggregates for the responses dashboard. Each summary carries a chart that suits the
 * question's data: a pie for one choice, bars for several, a timeline for dates, a histogram for
 * numbers, size ranges for uploads, and a map for locations.
 *
 * A chart is ['type' => pie|bar|column|line|stacked, 'categories' => [...], 'series' => [['name', 'data']], 'unit' => '...'].
 */
class SurveySummary
{
    /**
     * A pie shows at most this many slices; the rest are folded into one.
     */
    private const MAX_SLICES = 7;

    /**
     * Upload size ranges, upper bound in KB (null = no bound).
     */
    private const FILE_SIZE_BUCKETS = [
        '1–100 KB' => 100,
        '101–250 KB' => 250,
        '251–500 KB' => 500,
        '501 KB–1 MB' => 1024,
        '1–2 MB' => 2048,
        '2–5 MB' => 5120,
        '> 5 MB' => null,
    ];

    /**
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array<string, mixed>>
     */
    public static function build(Survey $survey, Collection $responses): array
    {
        return array_map(function (array $question) use ($survey, $responses): array {
            $answered = $responses->filter(fn (SurveyResponse $response): bool => $response->displayAnswer($question) !== '');
            // In a repeating block every row's answer counts.
            $answers = $answered->flatMap(fn (SurveyResponse $response): array => ! empty($question['in_roster'])
                ? array_values(array_filter((array) $response->answerTo($question['id']), fn ($value): bool => $value !== '' && $value !== [] && $value !== null))
                : [$response->answerTo($question['id'])]);

            $summary = [
                'question' => $question,
                'answered' => $answered->count(),
                'kind' => 'samples',
                'chart' => null,
            ];
            $type = $question['type'];

            if (in_array($type, Survey::CHOICE_TYPES, true)) {
                $counts = array_fill_keys($question['options'], 0);
                foreach ($answers as $answer) {
                    foreach ((array) $answer as $option) {
                        if (array_key_exists($option, $counts)) {
                            $counts[$option]++;
                        }
                    }
                }

                $largest = max([1, ...array_values($counts)]);

                return array_merge($summary, [
                    'kind' => 'choices',
                    'choices' => array_map(fn (string $option, int $count): array => [
                        'label' => $option,
                        'count' => $count,
                        'percent' => $answers->count() ? round($count / $answers->count() * 100) : 0,
                        'width' => round($count / $largest * 100),
                    ], array_keys($counts), array_values($counts)),
                    // One answer each: shares of a whole. Several each: the shares overlap, so bars.
                    'chart' => $answers->isEmpty() ? null : ($type === 'multiple'
                        ? self::chart('bar', array_keys($counts), array_values($counts), 'jawaban')
                        : self::pie($counts, 'jawaban')),
                ]);
            }

            if ($type === 'checkbox') {
                return array_merge($summary, [
                    'kind' => 'chart',
                    'chart' => $responses->isEmpty() ? null : self::pie([
                        'Dicentang' => $answered->count(),
                        'Tidak dicentang' => $responses->count() - $answered->count(),
                    ], 'respons'),
                ]);
            }

            if (in_array($type, Survey::NUMERIC_TYPES, true) && $answers->isNotEmpty()) {
                $numbers = $answers->map(fn ($answer): float => (float) $answer)->values();
                $format = $type === 'currency'
                    ? fn (float $value): string => SurveyResponse::formatRupiah($value)
                    : fn (float $value): string => (string) round($value, 2);

                return array_merge($summary, [
                    'kind' => 'numbers',
                    'average' => $format($numbers->avg()),
                    'median' => $format((float) $numbers->median()),
                    'min' => $format($numbers->min()),
                    'max' => $format($numbers->max()),
                    'chart' => in_array($type, ['rating', 'scale'], true)
                        ? self::rangeChart($question, $numbers)
                        : self::histogram($numbers, $type === 'currency'),
                ]);
            }

            if ($type === 'date' && $answers->isNotEmpty()) {
                return array_merge($summary, ['kind' => 'chart', 'chart' => self::dateChart($answers)]);
            }

            if ($type === 'time' && $answers->isNotEmpty()) {
                $hours = $answers->map(fn ($answer): ?int => preg_match('/^(\d{2}):\d{2}/', (string) $answer, $match) ? (int) $match[1] : null)->filter(fn (?int $hour): bool => $hour !== null);

                return array_merge($summary, ['kind' => 'chart', 'chart' => $hours->isEmpty() ? null : self::hourChart($hours)]);
            }

            if ($type === 'file' && $answers->isNotEmpty()) {
                return array_merge($summary, self::files($answers));
            }

            if ($type === 'matrix' && $answers->isNotEmpty()) {
                return array_merge($summary, ['kind' => 'chart', 'chart' => self::matrixChart($question, $answers)]);
            }

            if ($type === 'location' && empty($question['in_roster'])) {
                return array_merge($summary, ['kind' => 'points', 'points' => self::points($survey, $question, $responses)]);
            }

            return $summary + [
                'samples' => $answered->take(3)->map(fn (SurveyResponse $response): string => $response->displayAnswer($question))->values()->all(),
            ];
        }, $survey->questions());
    }

    /**
     * Charts about the responses themselves: when they came in and from what devices.
     *
     * @param  Collection<int, SurveyResponse>  $responses
     * @return array{timeline: ?array<string, mixed>, hours: ?array<string, mixed>, devices: ?array<string, mixed>, average_duration: string}
     */
    public static function overview(Collection $responses): array
    {
        if ($responses->isEmpty()) {
            return ['timeline' => null, 'hours' => null, 'devices' => null, 'average_duration' => ''];
        }

        $timezone = config('app.timezone');
        $times = $responses->map(fn (SurveyResponse $response): Carbon => $response->created_at->copy()->timezone($timezone));
        $devices = $responses->map(fn (SurveyResponse $response): string => explode(' · ', $response->deviceLabel())[0] ?: 'Tidak tercatat')->countBy()->sortDesc()->all();
        $durations = $responses->map(fn (SurveyResponse $response): ?int => $response->durationSeconds())->filter(fn (?int $seconds): bool => $seconds !== null);

        return [
            'timeline' => self::dateChart($times->map(fn (Carbon $time): string => $time->format('Y-m-d')), 'respons', true),
            'hours' => self::hourChart($times->map(fn (Carbon $time): int => (int) $time->format('G')), 'respons'),
            'devices' => self::pie($devices, 'respons'),
            'average_duration' => $durations->isEmpty() ? '' : self::duration((int) round($durations->avg())),
        ];
    }

    /**
     * @param  list<string>  $categories
     * @param  list<int|float>  $data
     * @return array<string, mixed>
     */
    private static function chart(string $type, array $categories, array $data, string $unit, string $name = 'Jumlah'): array
    {
        return ['type' => $type, 'categories' => array_values($categories), 'series' => [['name' => $name, 'data' => array_values($data)]], 'unit' => $unit];
    }

    /**
     * Slices largest first after the first few, the rest folded into "Lainnya".
     *
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private static function pie(array $counts, string $unit): array
    {
        $counts = array_filter($counts, fn (int $count): bool => $count > 0);
        if (count($counts) > self::MAX_SLICES) {
            arsort($counts);
            $kept = array_slice($counts, 0, self::MAX_SLICES - 1, true);
            $rest = array_slice($counts, self::MAX_SLICES - 1, null, true);
            $counts = $kept + ['Lainnya ('.count($rest).' pilihan)' => array_sum($rest)];
        }

        return self::chart('pie', array_map('strval', array_keys($counts)), array_values($counts), $unit);
    }

    /**
     * One bar per value a rating or scale offers.
     *
     * @param  array<string, mixed>  $question
     * @param  Collection<int, float>  $numbers
     * @return array<string, mixed>
     */
    private static function rangeChart(array $question, Collection $numbers): array
    {
        $values = Survey::rangeValues($question);
        $counts = array_map(fn (int $value): int => $numbers->filter(fn (float $number): bool => (int) round($number) === $value)->count(), $values);
        $labels = array_map(fn (int $value): string => $question['type'] === 'rating' ? $value.' ★' : (string) $value, $values);

        return self::chart('column', $labels, $counts, 'jawaban');
    }

    /**
     * Counts in up to eight equal ranges with round bounds.
     *
     * @param  Collection<int, float>  $numbers
     * @return array<string, mixed>
     */
    private static function histogram(Collection $numbers, bool $money): array
    {
        $min = (float) $numbers->min();
        $max = (float) $numbers->max();
        $label = fn (float $value): string => $money ? self::shortRupiah($value) : self::shortNumber($value);

        if ($min === $max) {
            return self::chart('column', [$label($min)], [$numbers->count()], 'jawaban');
        }

        // A "nice" width (1, 2 or 5 × 10^n) giving at most eight ranges.
        $raw = ($max - $min) / 8;
        $magnitude = 10 ** floor(log10($raw));
        $width = collect([1, 2, 5, 10])->map(fn (int $step): float => $step * $magnitude)->first(fn (float $step): bool => $step >= $raw);
        // Whole-number answers get whole-number ranges.
        $whole = $numbers->every(fn (float $number): bool => floor($number) === $number);
        if ($whole) {
            $width = max(1, ceil($width));
        }
        $start = floor($min / $width) * $width;
        $count = max(1, (int) ceil(($max - $start + ($whole ? 1 : 0)) / $width));
        $counts = array_fill(0, $count, 0);
        foreach ($numbers as $number) {
            $counts[min($count - 1, (int) floor(($number - $start) / $width))]++;
        }

        $labels = [];
        for ($index = 0; $index < $count; $index++) {
            $from = $start + $index * $width;
            $to = $whole ? $from + $width - 1 : $from + $width;
            $labels[] = $from === $to ? $label($from) : $label($from).'–'.$label($to);
        }

        return self::chart('column', $labels, $counts, 'jawaban');
    }

    /**
     * Answers per day; per month past two months, per year past three years, and per span of
     * years when that is still too many bars (e.g. dates of birth).
     *
     * @param  Collection<int, mixed>  $dates
     * @return array<string, mixed>|null
     */
    private static function dateChart(Collection $dates, string $unit = 'jawaban', bool $fillGaps = false): ?array
    {
        $days = $dates->map(fn ($date): string => (string) $date)->filter(fn (string $date): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1)->sort()->values();
        if ($days->isEmpty()) {
            return null;
        }

        $first = Carbon::parse($days->first());
        $last = Carbon::parse($days->last());
        $spanDays = $first->diffInDays($last);
        $period = match (true) {
            $spanDays <= 62 => 'hari',
            $spanDays <= 3 * 366 => 'bulan',
            default => 'tahun',
        };

        if ($period === 'tahun') {
            $firstYear = (int) $first->format('Y');
            $lastYear = (int) $last->format('Y');
            // At most about ten bars: 1, 2, 5 or 10 years each.
            $step = collect([1, 2, 5, 10])->first(fn (int $years): bool => ($lastYear - $firstYear + 1) / $years <= 10) ?? 10;
            $start = intdiv($firstYear, $step) * $step;
            $counts = [];
            for ($year = $start; $year <= $lastYear; $year += $step) {
                $counts[$step === 1 ? (string) $year : $year.'–'.($year + $step - 1)] = 0;
            }
            foreach ($days as $day) {
                $year = intdiv((int) substr($day, 0, 4) - $start, $step) * $step + $start;
                $counts[$step === 1 ? (string) $year : $year.'–'.($year + $step - 1)]++;
            }

            return self::chart('column', array_map('strval', array_keys($counts)), array_values($counts), $unit) + ['period' => $step === 1 ? 'tahun' : $step.' tahun'];
        }

        $monthly = $period === 'bulan';
        $key = fn (Carbon $date): string => $monthly ? $date->format('Y-m') : $date->format('Y-m-d');
        $counts = $days->map(fn (string $day): string => $key(Carbon::parse($day)))->countBy()->all();

        // A timeline shows the quiet days too; answered dates only show the dates given.
        if ($fillGaps || $monthly) {
            $filled = [];
            for ($date = $monthly ? $first->copy()->startOfMonth() : $first->copy(); $date->lte($last); $monthly ? $date->addMonth() : $date->addDay()) {
                $filled[$key($date)] = $counts[$key($date)] ?? 0;
            }
            $counts = $filled;
        }

        $labels = array_map(fn (string $value): string => $monthly
            ? Carbon::createFromFormat('Y-m-d', $value.'-01')->translatedFormat('M Y')
            : Carbon::parse($value)->translatedFormat('d M'), array_map('strval', array_keys($counts)));

        return self::chart(count($counts) > 14 ? 'line' : 'column', $labels, array_values($counts), $unit) + ['period' => $period];
    }

    /**
     * Counts per hour of the day, from the earliest to the latest hour given.
     *
     * @param  Collection<int, int>  $hours
     * @return array<string, mixed>
     */
    private static function hourChart(Collection $hours, string $unit = 'jawaban'): array
    {
        $counts = $hours->countBy()->all();
        $range = range((int) $hours->min(), (int) $hours->max());

        return self::chart('column', array_map(fn (int $hour): string => sprintf('%02d.00', $hour), $range), array_map(fn (int $hour): int => $counts[$hour] ?? 0, $range), $unit);
    }

    /**
     * Each matrix row's answers split by the column chosen.
     *
     * @param  array<string, mixed>  $question
     * @param  Collection<int, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function matrixChart(array $question, Collection $answers): array
    {
        $rows = $question['matrix']['rows'];

        return [
            'type' => 'stacked',
            'categories' => array_values($rows),
            'series' => array_map(fn (string $column): array => [
                'name' => $column,
                'data' => array_map(fn (int $row): int => $answers->filter(fn ($answer): bool => is_array($answer) && ($answer[$row] ?? null) === $column)->count(), array_keys($rows)),
            ], array_values($question['matrix']['columns'])),
            'unit' => 'jawaban',
        ];
    }

    /**
     * Uploaded files by size range, with their total and average size.
     *
     * @param  Collection<int, mixed>  $answers
     * @return array<string, mixed>
     */
    private static function files(Collection $answers): array
    {
        $disk = Storage::disk('public');
        $sizes = $answers->filter(fn ($path): bool => Survey::isUploadPath($path) && $disk->exists($path))->map(fn (string $path): int => (int) $disk->size($path))->values();

        if ($sizes->isEmpty()) {
            return ['kind' => 'files', 'chart' => null, 'files' => ['count' => 0, 'total' => '', 'average' => '', 'largest' => '']];
        }

        $counts = array_fill_keys(array_keys(self::FILE_SIZE_BUCKETS), 0);
        foreach ($sizes as $bytes) {
            $kilobytes = $bytes / 1024;
            foreach (self::FILE_SIZE_BUCKETS as $label => $upper) {
                if ($upper === null || $kilobytes <= $upper) {
                    $counts[$label]++;
                    break;
                }
            }
        }

        return [
            'kind' => 'files',
            'chart' => self::chart('column', array_keys($counts), array_values($counts), 'berkas'),
            'files' => [
                'count' => $sizes->count(),
                'total' => self::bytes((int) $sizes->sum()),
                'average' => self::bytes((int) round($sizes->avg())),
                'largest' => self::bytes((int) $sizes->max()),
            ],
        ];
    }

    public static function bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 0, ',', '.').' KB';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', '.').' MB';
    }

    private static function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return $minutes >= 60 ? intdiv($minutes, 60).' jam '.($minutes % 60).' mnt' : ($minutes ? $minutes.' mnt ' : '').($seconds % 60).' dtk';
    }

    private static function shortNumber(float $value): string
    {
        return match (true) {
            abs($value) >= 1_000_000_000 => round($value / 1_000_000_000, 1).' M',
            abs($value) >= 1_000_000 => round($value / 1_000_000, 1).' jt',
            abs($value) >= 10_000 => round($value / 1000, 1).' rb',
            default => (string) round($value, 2),
        };
    }

    private static function shortRupiah(float $value): string
    {
        return 'Rp '.self::shortNumber($value);
    }

    /**
     * Every response's point for a location question, for the map on the responses page. Each point
     * carries the response's number in the table (newest first, so the newest has the highest) and
     * the first short answer (usually the respondent's name) to tell the points apart.
     *
     * @param  array<string, mixed>  $question
     * @param  Collection<int, SurveyResponse>  $responses
     * @return list<array{id: int, number: int, lat: float, lng: float, accuracy: ?int, label: string, time: string, url: string}>
     */
    private static function points(Survey $survey, array $question, Collection $responses): array
    {
        $nameQuestion = collect($survey->questions())->first(fn (array $candidate): bool => $candidate['type'] === 'short' && empty($candidate['in_roster']));
        $points = [];
        foreach ($responses->values() as $index => $response) {
            if (! preg_match(Survey::LOCATION_PATTERN, trim((string) $response->answerTo($question['id'])), $match)) {
                continue;
            }
            $accuracy = $response->answers[$question['id'].Survey::ACCURACY_SUFFIX] ?? null;
            $number = $responses->count() - $index;
            $points[] = [
                'id' => $response->id,
                'number' => $number,
                'lat' => (float) $match[1],
                'lng' => (float) $match[2],
                'accuracy' => is_numeric($accuracy) ? (int) round((float) $accuracy) : null,
                'label' => ($nameQuestion ? $response->displayAnswer($nameQuestion) : '') ?: 'Respons #'.$number,
                'time' => $response->created_at->translatedFormat('d M Y, H:i'),
                'url' => route('surveys.responses.show', [$survey, $response]),
            ];
        }

        return $points;
    }
}
