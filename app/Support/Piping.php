<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Answer tags in a question's text ("Berapa umur {{q-nama}}?"): live on the respondent form,
 * the answer itself on a filled sheet, and the question number everywhere else.
 */
class Piping
{
    /**
     * Escaped text where each tag becomes a placeholder that public/js/fill.js fills in.
     */
    public static function html(string $text): HtmlString
    {
        return new HtmlString((string) preg_replace_callback(
            SurveyLogic::MENTION,
            fn (array $match): string => '<span class="fill-pipe" data-pipe="'.e($match[1]).'" data-fallback="'.e($match[2] ?? '').'">'.e(($match[2] ?? '') !== '' ? $match[2] : '…').'</span>',
            e($text),
        ));
    }

    /**
     * The text with each tag shown as the number of the question it quotes, e.g. "Berapa umur [101]?".
     *
     * @param  array<string, string>  $numbers
     */
    public static function text(string $text, array $numbers): string
    {
        return (string) preg_replace_callback(
            SurveyLogic::MENTION,
            fn (array $match): string => '['.($numbers[$match[1]] ?? '…').']',
            $text,
        );
    }

    /**
     * The text with each tag replaced by an answer, or its fallback.
     *
     * @param  callable(string): string  $answerText
     */
    public static function resolved(string $text, callable $answerText): string
    {
        return (string) preg_replace_callback(SurveyLogic::MENTION, function (array $match) use ($answerText): string {
            $answer = $answerText($match[1]);

            return $answer !== '' ? $answer : (($match[2] ?? '') !== '' ? $match[2] : '……');
        }, $text);
    }
}
