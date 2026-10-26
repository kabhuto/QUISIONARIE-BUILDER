<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A small, safe formula language for calculated questions (FormGear's "variable" fields), e.g.
 * "{{q-makan}} + {{q-nonmakan}}", "round({{q-luas}} / 10000, 2)" or "years({{q-lahir}})".
 *
 * Numbers, "texts" (e.g. dates), answer tags, + - * / %, comparisons (giving 1 or 0), brackets and the functions below.
 * An unanswered tag counts as 0 in arithmetic, a list (e.g. a repeating block's rows) as its total;
 * a formula whose tags are all unanswered has no result.
 * Mirrors the evaluator in public/js/fill.js.
 */
class Formula
{
    /**
     * Function name => [fewest, most] arguments (null: any number).
     */
    public const FUNCTIONS = [
        'sum' => [1, null],
        'avg' => [1, null],
        'min' => [1, null],
        'max' => [1, null],
        'round' => [1, 2],
        'floor' => [1, 1],
        'ceil' => [1, 1],
        'abs' => [1, 1],
        'count' => [1, 1],
        'len' => [1, 1],
        'years' => [1, 2],
        'days' => [2, 2],
        'if' => [3, 3],
    ];

    public const FORMATS = ['number' => 'Angka', 'currency' => 'Rupiah', 'percent' => 'Persen (%)'];

    public const MAX_LENGTH = 500;

    private const TOKEN = '/\s*(?:\{\{([A-Za-z0-9_-]+)\}\}|(\d+(?:\.\d+)?)|([A-Za-z_]+)|(>=|<=|==|!=|<>|[-+*\/%(),<>=])|"([^"]*)"|\'([^\']*)\')/A';

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function tokens(string $formula): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($formula);

        while ($offset < $length) {
            if (trim(substr($formula, $offset)) === '') {
                break;
            }
            if (! preg_match(self::TOKEN, $formula, $match, 0, $offset)) {
                throw new InvalidArgumentException('memuat karakter yang tidak dikenal: "'.trim(substr($formula, $offset, 8)).'"');
            }

            $offset += strlen($match[0]);
            $tokens[] = match (true) {
                ($match[1] ?? '') !== '' => ['tag', $match[1]],
                ($match[2] ?? '') !== '' => ['num', $match[2]],
                ($match[3] ?? '') !== '' => ['name', strtolower($match[3])],
                ($match[4] ?? '') === '' => ['str', ($match[5] ?? '').($match[6] ?? '')],
                default => ['op', $match[4] === '<>' ? '!=' : ($match[4] === '=' ? '==' : $match[4])],
            };
        }

        return $tokens;
    }

    /**
     * Parses a formula into a tree, or throws with a readable reason.
     *
     * @return array<int, mixed>
     */
    public static function parse(string $formula): array
    {
        if (trim($formula) === '') {
            throw new InvalidArgumentException('belum diisi');
        }
        if (mb_strlen($formula) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('terlalu panjang');
        }

        $tokens = self::tokens($formula);
        $position = 0;
        $tree = self::comparison($tokens, $position);

        if ($position < count($tokens)) {
            throw new InvalidArgumentException('memuat "'.$tokens[$position][1].'" di tempat yang salah');
        }

        return $tree;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private static function comparison(array $tokens, int &$position): array
    {
        $left = self::additive($tokens, $position);

        while (in_array($tokens[$position][1] ?? null, ['>', '<', '>=', '<=', '==', '!='], true) && $tokens[$position][0] === 'op') {
            $operator = $tokens[$position++][1];
            $left = ['bin', $operator, $left, self::additive($tokens, $position)];
        }

        return $left;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private static function additive(array $tokens, int &$position): array
    {
        $left = self::term($tokens, $position);

        while (($tokens[$position][0] ?? null) === 'op' && in_array($tokens[$position][1], ['+', '-'], true)) {
            $operator = $tokens[$position++][1];
            $left = ['bin', $operator, $left, self::term($tokens, $position)];
        }

        return $left;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private static function term(array $tokens, int &$position): array
    {
        $left = self::factor($tokens, $position);

        while (($tokens[$position][0] ?? null) === 'op' && in_array($tokens[$position][1], ['*', '/', '%'], true)) {
            $operator = $tokens[$position++][1];
            $left = ['bin', $operator, $left, self::factor($tokens, $position)];
        }

        return $left;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     * @return array<int, mixed>
     */
    private static function factor(array $tokens, int &$position): array
    {
        [$kind, $value] = $tokens[$position++] ?? ['end', ''];

        if ($kind === 'op' && ($value === '-' || $value === '+')) {
            $operand = self::factor($tokens, $position);

            return $value === '-' ? ['neg', $operand] : $operand;
        }
        if ($kind === 'num') {
            return ['num', (float) $value];
        }
        if ($kind === 'tag') {
            return ['tag', $value];
        }
        if ($kind === 'str') {
            return ['str', $value];
        }
        if ($kind === 'op' && $value === '(') {
            $inner = self::comparison($tokens, $position);
            self::expect($tokens, $position, ')');

            return $inner;
        }
        if ($kind === 'name') {
            if (! array_key_exists($value, self::FUNCTIONS)) {
                throw new InvalidArgumentException("memakai fungsi \"{$value}\" yang tidak dikenal");
            }
            self::expect($tokens, $position, '(');
            $arguments = [];

            if (($tokens[$position][1] ?? null) !== ')') {
                do {
                    $arguments[] = self::comparison($tokens, $position);
                } while (($tokens[$position][1] ?? null) === ',' && ++$position);
            }
            self::expect($tokens, $position, ')');

            [$fewest, $most] = self::FUNCTIONS[$value];
            if (count($arguments) < $fewest || ($most !== null && count($arguments) > $most)) {
                throw new InvalidArgumentException("memanggil {$value}() dengan jumlah isian yang salah");
            }

            return ['call', $value, $arguments];
        }

        throw new InvalidArgumentException($kind === 'end' ? 'berakhir sebelum selesai' : "memuat \"{$value}\" di tempat yang salah");
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function expect(array $tokens, int &$position, string $symbol): void
    {
        if (($tokens[$position][1] ?? null) !== $symbol) {
            throw new InvalidArgumentException("kurang tanda \"{$symbol}\"");
        }
        $position++;
    }

    /**
     * Ids of the questions a formula uses.
     *
     * @return list<string>
     */
    public static function references(string $formula): array
    {
        preg_match_all('/\{\{([A-Za-z0-9_-]+)\}\}/', $formula, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * What is wrong with a formula, given the questions it may use; null when it is fine.
     *
     * @param  list<string>  $available
     */
    public static function problem(string $formula, array $available): ?string
    {
        try {
            self::parse($formula);
        } catch (InvalidArgumentException $exception) {
            return $exception->getMessage();
        }

        $unknown = array_diff(self::references($formula), $available);

        return $unknown === [] ? null : 'memakai tag jawaban dari pertanyaan yang tidak tersedia';
    }

    /**
     * The value of a formula for the given answers; null when its tags are all unanswered
     * or it cannot be worked out (e.g. division by zero).
     *
     * @param  array<string, mixed>  $answers
     */
    public static function evaluate(string $formula, array $answers, ?string $today = null): ?float
    {
        try {
            $tree = self::parse($formula);
        } catch (InvalidArgumentException) {
            return null;
        }

        $references = self::references($formula);
        if ($references !== [] && ! array_filter($references, fn (string $id): bool => self::isAnswered($answers[$id] ?? null))) {
            return null;
        }

        try {
            $value = self::number(self::run($tree, $answers, $today ?? CarbonImmutable::today()->toDateString()));
        } catch (InvalidArgumentException) {
            return null;
        }

        return is_finite($value) ? $value : null;
    }

    /**
     * The stored answer of a calculated question: its value rounded to the question's decimals.
     *
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $answers
     */
    public static function result(array $question, array $answers): ?string
    {
        $value = self::evaluate((string) ($question['formula'] ?? ''), $answers);

        return $value === null ? null : self::plain($value, (int) ($question['decimals'] ?? 0));
    }

    public static function plain(float $value, int $decimals): string
    {
        $text = number_format(round($value, $decimals), $decimals, '.', '');

        return $text === '-0' || preg_match('/^-0\.0+$/', $text) ? ltrim($text, '-') : $text;
    }

    /**
     * A calculated value for people: "Rp 1.500.000", "12,5%" or "1.234,56".
     */
    public static function display(string $value, string $format, int $decimals): string
    {
        $number = number_format((float) $value, $format === 'currency' ? 0 : $decimals, ',', '.');

        return match ($format) {
            'currency' => 'Rp '.$number,
            'percent' => $number.'%',
            default => $number,
        };
    }

    private static function isAnswered(mixed $value): bool
    {
        return is_array($value) ? $value !== [] : trim((string) $value) !== '';
    }

    /**
     * @param  array<int, mixed>  $node
     * @param  array<string, mixed>  $answers
     */
    private static function run(array $node, array $answers, string $today): mixed
    {
        return match ($node[0]) {
            'num', 'str' => $node[1],
            'tag' => $answers[$node[1]] ?? null,
            'neg' => -self::number(self::run($node[1], $answers, $today)),
            'bin' => self::binary($node[1], self::number(self::run($node[2], $answers, $today)), self::number(self::run($node[3], $answers, $today))),
            'call' => self::call($node[1], $node[2], $answers, $today),
        };
    }

    private static function binary(string $operator, float $left, float $right): float
    {
        if (in_array($operator, ['/', '%'], true) && $right == 0.0) {
            throw new InvalidArgumentException('pembagian dengan nol');
        }

        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $left / $right,
            '%' => fmod($left, $right),
            '>' => (float) ($left > $right),
            '<' => (float) ($left < $right),
            '>=' => (float) ($left >= $right),
            '<=' => (float) ($left <= $right),
            '==' => (float) (abs($left - $right) < 1e-9),
            '!=' => (float) (abs($left - $right) >= 1e-9),
        };
    }

    /**
     * @param  list<array<int, mixed>>  $arguments
     * @param  array<string, mixed>  $answers
     */
    private static function call(string $name, array $arguments, array $answers, string $today): float
    {
        if ($name === 'if') {
            return self::number(self::run($arguments[self::number(self::run($arguments[0], $answers, $today)) != 0.0 ? 1 : 2], $answers, $today));
        }

        $values = array_map(fn (array $argument): mixed => self::run($argument, $answers, $today), $arguments);
        // Lists (the rows of a repeating block) are spread out, so sum({{q-pendapatan}}) adds every row.
        $numbers = fn (): array => array_map(self::number(...), array_merge(...array_map(fn ($value): array => is_array($value) ? array_values($value) : [$value], $values)));

        return match ($name) {
            'sum' => array_sum($numbers()),
            'avg' => array_sum($numbers()) / max(1, count($numbers())),
            'min' => min($numbers()),
            'max' => max($numbers()),
            'round' => round(self::number($values[0]), (int) self::number($values[1] ?? 0)),
            'floor' => floor(self::number($values[0])),
            'ceil' => ceil(self::number($values[0])),
            'abs' => abs(self::number($values[0])),
            'count' => is_array($values[0]) ? count(array_filter($values[0], fn ($item): bool => self::isAnswered($item))) : (float) self::isAnswered($values[0]),
            'len' => is_array($values[0]) ? 0.0 : (float) mb_strlen(trim((string) $values[0])),
            'years' => self::years($values[0], $values[1] ?? $today),
            'days' => round((self::date($values[1])->getTimestamp() - self::date($values[0])->getTimestamp()) / 86400),
        };
    }

    /**
     * Full years from one date to another (e.g. age from a date of birth).
     */
    private static function years(mixed $from, mixed $to): float
    {
        $start = self::date($from);
        $end = self::date($to);
        $years = $end->year - $start->year;

        if ($end->format('md') < $start->format('md')) {
            $years--;
        }

        return (float) $years;
    }

    private static function date(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('tanggal tidak valid');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value);
    }

    private static function number(mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (is_array($value)) {
            // A list in arithmetic counts as its total, e.g. the rows of a repeating block.
            return array_sum(array_map(self::number(...), array_filter($value, fn ($item): bool => ! is_array($item))));
        }
        if ($value === null) {
            return 0.0;
        }

        $text = str_replace(',', '.', trim((string) $value));

        return is_numeric($text) ? (float) $text : 0.0;
    }
}
