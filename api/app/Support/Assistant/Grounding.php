<?php

namespace App\Support\Assistant;

/* The grounding check (ADR 0013, decision 3): every number in an answer must appear in a tool
   result from the same request, and an answer must cite at least one. It keeps figures honest; it
   does not prove an answer is on topic, which is the scope gate's job.

   "Appears" allows the rounding a sentence uses: a figure written to n decimals matches a returned
   value within half a unit of its last place, so 62.5 may be written 62.5, 63, or 62, 51.25 may
   be 51.2 or 51.3, and a share of 0.625 may be 62.5 or 63 percent. A difference a tool returned
   may be written without its sign, as "6.2 points behind" for -6.2. Nothing else is allowed: a
   difference, total, or count the model worked out itself is ungrounded, however simple.

   Names are not figures. A number glued to letters (S12, 4W, 5A) is a label, as are Term 2 and T3,
   and so is any name the tools themselves returned that carries a digit, such as the assessment
   "CAT 1": its 1 is part of the name. A date the tools returned grounds its day, month, and year,
   so 2026-02-16 may be written 16 Feb 2026. Years are otherwise figures like any other: a year
   the tools did not return is as ungrounded as a made-up mean. */
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
        $known = [];
        $labels = [];
        array_walk_recursive($toolResults, function (mixed $value) use (&$known, &$labels): void {
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $known[] = (float) $value;
            } elseif (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date)) {
                array_push($known, (float) $date[1], (float) $date[2], (float) $date[3]);
            } elseif (is_string($value) && preg_match('/\d/', $value)) {
                $labels[] = $value;
            }
        });

        // Longest first, so a label that contains a shorter one is taken whole.
        usort($labels, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        foreach ($labels as $label) {
            $text = str_ireplace($label, ' ', $text);
        }

        $cited = self::figuresIn($text);

        if ($cited === []) {
            return [];
        }

        $missing = array_values(array_map(
            fn (array $figure) => $figure[0],
            array_filter($cited, fn (array $figure) => ! self::matchesAny($figure[0], $figure[1], $known)),
        ));

        return $missing === [] ? null : $missing;
    }

    /** @return list<float> */
    public static function numbersIn(string $text): array
    {
        return array_map(fn (array $figure) => $figure[0], self::figuresIn($text));
    }

    /**
     * Each figure in the text with the number of decimals it was written to.
     *
     * @return list<array{0: float, 1: int}>
     */
    private static function figuresIn(string $text): array
    {
        // A term number is not a figure: drop "Term 2" first, and list numbering, "1)" or a "1." that
        // starts a line. A number glued to letters (S12, 4W, T3) is a label, so a figure must not run
        // into a letter or digit on either side.
        $text = preg_replace(['/\bTerm\s+\d\b/i', '/(?<![\w.(])\d+\)/', '/^\s*\d+\.(?=\s)/m'], ' ', $text) ?? $text;
        preg_match_all('/(?<![\w.])\d+(?:[.,]\d+)?(?![\w])/', $text, $matches);

        return array_map(function (string $number): array {
            $decimals = preg_match('/[.,](\d+)$/', $number, $part) ? strlen($part[1]) : 0;

            return [(float) str_replace(',', '.', $number), $decimals];
        }, $matches[0]);
    }

    /** @param  list<float>  $known */
    private static function matchesAny(float $number, int $decimals, array $known): bool
    {
        $tolerance = 0.5 * 10 ** -$decimals + 1e-9;

        foreach ($known as $value) {
            foreach ([$value, $value * 100, abs($value)] as $candidate) {
                if (abs($candidate - $number) <= $tolerance) {
                    return true;
                }
            }
        }

        return false;
    }
}
