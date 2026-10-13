<?php

namespace App\Support\Assistant;

/* The grounding check (ADR 0013, decision 3): every number in an answer must appear in a tool
   result from the same request, and an answer must cite at least one. It keeps figures honest; it
   does not prove an answer is on topic, which is the scope gate's job.

   "Appears" allows the rounding a sentence uses: a figure of 62.5 may be written 62.5, 63, or
   62.50, and a share of 0.625 may be written as 62.5 or 63 percent. Handles (S12) and term or
   level names (Term 2, EE) are not figures. Years are figures like any other: a year the tools
   did not return is as ungrounded as a made-up mean. */
final class Grounding
{
    /**
     * @param  list<mixed>  $toolResults  every tool result of the request, decoded
     */
    public static function holds(string $text, array $toolResults): bool
    {
        return self::ungrounded($text, $toolResults) === null;
    }

    /**
     * Why an answer fails the check, for the evaluation's record: null when it holds, an empty list
     * when the answer cites no figure, otherwise the cited figures no tool result contains.
     *
     * @param  list<mixed>  $toolResults
     * @return list<float>|null
     */
    public static function ungrounded(string $text, array $toolResults): ?array
    {
        $cited = self::numbersIn($text);

        if ($cited === []) {
            return [];
        }

        $known = [];
        array_walk_recursive($toolResults, function (mixed $value) use (&$known): void {
            if (is_int($value) || is_float($value)) {
                $known[] = (float) $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $known[] = (float) $value;
            }
        });

        $missing = array_values(array_filter($cited, fn (float $number) => ! self::matchesAny($number, $known)));

        return $missing === [] ? null : $missing;
    }

    /** @return list<float> */
    public static function numbersIn(string $text): array
    {
        // A handle or a term number is not a figure: drop S12, "Term 2", and "T2" first.
        $text = preg_replace('/\bS\d+\b|\bTerm\s+\d\b|\bT\d\b/i', ' ', $text) ?? $text;
        preg_match_all('/(?<![\w.])\d+(?:[.,]\d+)?/', $text, $matches);

        return array_map(fn (string $number) => (float) str_replace(',', '.', $number), $matches[0]);
    }

    /** @param  list<float>  $known */
    private static function matchesAny(float $number, array $known): bool
    {
        foreach ($known as $value) {
            foreach ([$value, $value * 100] as $candidate) {
                if (abs($candidate - $number) < 1e-9
                    || round($candidate) === $number
                    || round($candidate, 1) === $number) {
                    return true;
                }
            }
        }

        return false;
    }
}
