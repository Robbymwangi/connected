<?php

namespace App\Support\Comments;

use App\Support\PerformanceLevel;

/* The one place a comment prompt is built (docs/spec/workflow.md, Comments). It reads only a
   CommentFacts, which carries no name, admission number, school, percentage, or position, and it
   refers to "the student"; the name goes back on our side when the card is rendered. This is the
   first boundary to revisit if the privacy position changes. The same facts give the templated
   stand-in used when there is no API key, labelled as such. */
final class CommentPrompt
{
    public const STAND_IN = '[Stand-in] ';

    private const SYSTEM = <<<'TEXT'
        You write the class teacher's remark on a Kenyan primary school report card under the
        Competency Based Curriculum. You are given, for one term, the student's performance level in
        each subject and how it compares with earlier terms. The levels are Exceeding Expectations,
        Meeting Expectations, Approaching Expectations, and Below Expectations.

        Write two or three sentences in plain British English, addressed to the student's family and
        referring to the student as "the student". Name a strength, name one area to work on, and
        end with an encouraging, specific next step. Mention movement between terms only where it is
        given. Use only the facts given: invent no marks, percentages, rankings, behaviour, or events,
        and do not mention a class position. Reply with the remark alone.
        TEXT;

    /** The Messages API parameters for one comment, as a batch request's params. */
    public static function params(CommentFacts $facts): array
    {
        return [
            'model' => config('services.anthropic.comment_model'),
            'max_tokens' => config('services.anthropic.comment_max_tokens'),
            // A remark is short and routine; low effort keeps thinking, which counts toward
            // max_tokens, from crowding out the text.
            'output_config' => ['effort' => 'low'],
            'system' => trim(self::SYSTEM),
            'messages' => [['role' => 'user', 'content' => self::describe($facts)]],
        ];
    }

    /** The facts as the model reads them. */
    public static function describe(CommentFacts $facts): string
    {
        $lines = ["Term {$facts->term} of {$facts->year}."];

        foreach ($facts->bySubject() as $subject) {
            if ($subject['now'] === null) {
                continue;
            }

            $line = "{$subject['subject']}: {$subject['now']->label()}";
            $previous = $subject['before'] === [] ? null : $subject['before'][array_key_last($subject['before'])];

            if ($previous !== null) {
                $line .= ' ('.self::movement($previous['level'], $subject['now'])
                    ." since Term {$previous['term']} of {$previous['year']}, when it was {$previous['level']->label()})";
            }

            $lines[] = $line.'.';
        }

        return implode("\n", $lines);
    }

    /** A remark templated from the same facts, for development and CI, labelled as a stand-in. */
    public static function standIn(CommentFacts $facts): string
    {
        $subjects = array_values(array_filter($facts->bySubject(), fn (array $subject) => $subject['now'] !== null));

        if ($subjects === []) {
            return self::STAND_IN."No results were recorded for the student in Term {$facts->term}.";
        }

        usort($subjects, fn (array $a, array $b) => $b['now']->rank() <=> $a['now']->rank() ?: strcmp($a['subject'], $b['subject']));
        $strongest = $subjects[0];
        $weakest = $subjects[array_key_last($subjects)];

        $text = "The student is {$strongest['now']->label()} in {$strongest['subject']}.";

        if ($weakest['subject'] !== $strongest['subject']) {
            $text .= " {$weakest['subject']} ({$weakest['now']->label()}) is the subject to focus on next term.";
        }

        return self::STAND_IN.$text;
    }

    private static function movement(PerformanceLevel $from, PerformanceLevel $to): string
    {
        return match ($to->rank() <=> $from->rank()) {
            1 => 'up',
            -1 => 'down',
            default => 'unchanged',
        };
    }
}
