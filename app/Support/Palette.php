<?php

namespace App\Support;

/**
 * Colours worked out from one accent colour chosen by an admin: text that stays readable on it,
 * and the admin look re-coloured from its original teal to that accent (light and dark theme).
 * Works in OKLCH so lightness stays as designed while the hue follows the accent.
 */
class Palette
{
    public const HEX_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * The admin look's own accent; choosing it means "no custom colour".
     */
    public const DEFAULT_ACCENT = '#087e78';

    /**
     * Ready-made choices for the colour picker; any other colour can be picked too.
     */
    public const PRESETS = [
        '#087e78' => 'Tosca',
        '#2f9e44' => 'Hijau',
        '#1f63b0' => 'Biru',
        '#6a4bb0' => 'Ungu',
        '#d6336c' => 'Pink',
        '#c92a2a' => 'Merah',
        '#e8590c' => 'Oranye',
        '#b08800' => 'Kuning',
        '#475569' => 'Abu',
    ];

    /**
     * Hue range (degrees) of the original teal family; those colours follow the accent.
     */
    private const BRAND_HUES = [160, 215];

    /**
     * Raise when accentCss() changes, so browsers fetch the new style sheets.
     */
    private const CSS_REVISION = 2;

    /**
     * Part of the style sheet's address; changes with this class and with theme.css.
     */
    public static function cssVersion(): string
    {
        return substr(md5(self::CSS_REVISION.'-'.@filemtime(public_path('css/theme.css'))), 0, 8);
    }

    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HEX_PATTERN, $value) === 1;
    }

    public static function normalize(string $hex): string
    {
        return strtolower($hex);
    }

    /**
     * White or near-black, whichever reads better on the colour.
     */
    public static function ink(string $hex): string
    {
        $luminance = self::luminance($hex);
        $onWhite = 1.05 / ($luminance + 0.05);
        $onDark = ($luminance + 0.05) / (self::luminance('#1a1a1a') + 0.05);

        // White reads better on saturated colours, so it wins near a tie.
        return $onWhite + 0.5 >= $onDark ? '#ffffff' : '#1a1a1a';
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Style sheet that re-colours the admin look in the accent: the semantic tokens of ui.css and
     * every teal-family token of theme.css, for both themes.
     */
    public static function accentCss(string $accent): string
    {
        $accent = self::normalize($accent);
        [$accentL, $accentC, $accentH] = self::oklch($accent);
        // How saturated the accent is compared with the original teal; tints scale with it.
        [, $baseC] = self::oklch(self::DEFAULT_ACCENT);
        $scale = $accentC / $baseC;
        $recolor = fn (string $hex): string => self::recolor($hex, $scale, $accentH);

        $light = [];
        $dark = [];
        foreach (self::themeTokens() as $name => [$lightHex, $darkHex]) {
            $lightFamily = self::isBrandFamily($lightHex);
            $darkFamily = self::isBrandFamily($darkHex);
            if ($lightFamily) {
                $light[] = "--k-{$name}:".$recolor($lightHex).self::alpha($lightHex);
            }
            // The light rule also matches in the dark theme, so a re-coloured token always gets its dark value back.
            if ($darkFamily || $lightFamily) {
                $dark[] = "--k-{$name}:".($darkFamily ? $recolor($darkHex).self::alpha($darkHex) : $darkHex);
            }
        }

        $strong = self::fromOklch(max(0.2, $accentL - 0.09), $accentC, $accentH);
        $darkBrand = self::fromOklch(max(0.7, $accentL), $accentC, $accentH);
        [$red, $green, $blue] = self::rgb($accent);
        [$darkRed, $darkGreen, $darkBlue] = self::rgb($darkBrand);

        $light[] = "--brand:{$accent};--brand-strong:{$strong};--brand-ink:".self::ink($accent);
        $light[] = '--brand-soft:'.$recolor('#e1f2ee');
        $light[] = "--focus:0 0 0 3px rgb({$red} {$green} {$blue} / .28)";
        $light[] = '--hero:linear-gradient(150deg,'.$recolor('#0b7a73').' 0%,'.$recolor('#0a5562').' 55%,'.$recolor('#14393d').' 100%)';
        $light[] = '--bg:'.$recolor('#f2f6f5').';--surface-2:'.$recolor('#f5f9f8').';--border:'.$recolor('#dbe6e3').';--border-strong:'.$recolor('#c3d4cf');
        $light[] = '--text:'.$recolor('#132e2c').';--text-2:'.$recolor('#46625e').';--text-3:'.$recolor('#7a8f8b');

        $dark[] = "--brand:{$darkBrand};--brand-strong:".self::fromOklch(min(0.9, max(0.7, $accentL) + 0.08), $accentC, $accentH).';--brand-ink:'.self::ink($darkBrand);
        $dark[] = '--brand-soft:'.$recolor('#16383a');
        $dark[] = "--focus:0 0 0 3px rgb({$darkRed} {$darkGreen} {$darkBlue} / .35)";
        $dark[] = '--hero:linear-gradient(150deg,'.$recolor('#0b4c49').' 0%,'.$recolor('#0b3540').' 55%,'.$recolor('#0d2326').' 100%)';
        $dark[] = '--bg:'.$recolor('#0c1416').';--surface:'.$recolor('#131e21').';--surface-2:'.$recolor('#192629').';--border:'.$recolor('#253538').';--border-strong:'.$recolor('#33474b');
        $dark[] = '--text:'.$recolor('#e4eeed').';--text-2:'.$recolor('#a7bab7').';--text-3:'.$recolor('#7d9390');

        return "/* Accent {$accent} */\n"
            .':root:root[data-accent]{'.implode(';', $light)."}\n"
            .'@media screen{:root:root[data-accent][data-theme="dark"]{'.implode(';', $dark)."}}\n";
    }

    /**
     * theme.css tokens as name => [light hex, dark hex] (alpha kept as the last two digits).
     *
     * @return array<string, array{string, string}>
     */
    public static function themeTokens(): array
    {
        static $tokens = null;
        if ($tokens !== null) {
            return $tokens;
        }

        $css = (string) @file_get_contents(public_path('css/theme.css'));
        $parts = preg_split('/\[data-theme="dark"\]/', $css, 2);
        $read = function (string $part): array {
            preg_match_all('/--k-([0-9a-f]+):([^;}]+)/i', $part, $matches, PREG_SET_ORDER);

            return array_filter(array_map(self::toHex(...), array_column($matches, 2, 1)));
        };
        $light = $read($parts[0] ?? '');
        $dark = $read($parts[1] ?? '');

        $tokens = [];
        foreach ($light as $name => $value) {
            $tokens[$name] = [strtolower($value), strtolower($dark[$name] ?? $value)];
        }

        return $tokens;
    }

    /**
     * A theme.css value (#hex, hsl(h s% l% / a) or rgb(r g b / a)) as #rrggbb or #rrggbbaa; null when unknown.
     */
    private static function toHex(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/', $value)) {
            return $value;
        }
        if (! preg_match('/^(hsl|rgb)\(\s*([\d.]+)\s+([\d.]+)%?\s+([\d.]+)%?\s*(?:\/\s*([\d.]+))?\s*\)$/', $value, $match)) {
            return null;
        }

        if ($match[1] === 'rgb') {
            $rgb = [(float) $match[2], (float) $match[3], (float) $match[4]];
        } else {
            [$hue, $saturation, $lightness] = [(float) $match[2], (float) $match[3] / 100, (float) $match[4] / 100];
            $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
            $channel = function (float $offset) use ($hue, $chroma, $lightness): float {
                $k = fmod($offset + $hue / 30, 12);

                return 255 * ($lightness - $chroma / 2 * max(-1, min($k - 3, 9 - $k, 1)));
            };
            $rgb = [$channel(0), $channel(8), $channel(4)];
        }

        $hex = '#'.implode('', array_map(fn (float $channel): string => str_pad(dechex((int) round(max(0, min(255, $channel)))), 2, '0', STR_PAD_LEFT), $rgb));
        $alpha = isset($match[5]) && $match[5] !== '' ? str_pad(dechex((int) round(min(1, (float) $match[5]) * 255)), 2, '0', STR_PAD_LEFT) : '';

        return $hex.$alpha;
    }

    private static function isBrandFamily(string $hex): bool
    {
        [, $chroma, $hue] = self::oklch(substr($hex, 0, 7));

        return $chroma >= 0.006 && $hue >= self::BRAND_HUES[0] && $hue <= self::BRAND_HUES[1];
    }

    /**
     * The colour with the accent's hue, its own lightness, and chroma scaled like the accent's.
     */
    private static function recolor(string $hex, float $scale, float $hue): string
    {
        [$lightness, $chroma] = self::oklch(substr($hex, 0, 7));

        return self::fromOklch($lightness, min(0.37, $chroma * $scale), $hue);
    }

    private static function alpha(string $hex): string
    {
        return strlen($hex) === 9 ? substr($hex, 7) : '';
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    /**
     * @return array{float, float, float} lightness 0–1, chroma, hue in degrees
     */
    public static function oklch(string $hex): array
    {
        $linear = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));
        [$r, $g, $b] = $linear;

        $l = (0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b) ** (1 / 3);
        $m = (0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b) ** (1 / 3);
        $s = (0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $bAxis = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $hue = rad2deg(atan2($bAxis, $a));

        return [$lightness, sqrt($a ** 2 + $bAxis ** 2), $hue < 0 ? $hue + 360 : $hue];
    }

    /**
     * Back to hex, lowering the chroma until the colour fits in sRGB.
     */
    public static function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        $lightness = max(0.0, min(1.0, $lightness));
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $rgb = self::oklchToLinear($lightness, $chroma, $hue);
            if (min($rgb) >= -0.0005 && max($rgb) <= 1.0005) {
                break;
            }
            $chroma *= 0.92;
        }

        return '#'.implode('', array_map(function (float $value): string {
            $value = max(0.0, min(1.0, $value));
            $encoded = $value <= 0.0031308 ? 12.92 * $value : 1.055 * $value ** (1 / 2.4) - 0.055;

            return str_pad(dechex((int) round($encoded * 255)), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    /**
     * @return array{float, float, float}
     */
    private static function oklchToLinear(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076609057 * $s,
        ];
    }
}
