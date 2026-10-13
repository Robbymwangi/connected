<?php

namespace App\Support\Assistant;

/* Students as handles (S1, S2, ...) for the length of one conversation (ADR 0013, decision 4):
   the model sees a handle where a student id would be, never a name or an admission number, and
   the server puts names back in the answer before it reaches the screen. The mapping lives with
   the conversation's history and expires with it, so a handle names the same student on every
   retained turn. */
final class Handles
{
    /** @param  array<string, string>  $byStudent  student id => handle */
    public function __construct(private array $byStudent = []) {}

    public function for(string $studentId): string
    {
        return $this->byStudent[$studentId] ??= 'S'.(count($this->byStudent) + 1);
    }

    public function studentFor(string $handle): ?string
    {
        $studentId = array_search($handle, $this->byStudent, true);

        return $studentId === false ? null : $studentId;
    }

    /**
     * Handles back to names, for the screen only. Longest handles first, so S12 is never read
     * as S1 followed by a 2; a handle the conversation never issued is left as it is.
     *
     * @param  array<string, string>  $names  student id => name
     */
    public function restore(string $text, array $names): string
    {
        $byHandle = [];
        foreach ($this->byStudent as $studentId => $handle) {
            $byHandle[$handle] = $names[$studentId] ?? $handle;
        }

        return preg_replace_callback('/\bS\d+\b/', fn (array $match) => $byHandle[$match[0]] ?? $match[0], $text) ?? $text;
    }

    /**
     * Names to handles, for what the teacher typed: the question is the one place a name could
     * reach the provider. A student of the report's roster is replaced when their full name of
     * two words or more appears in any case, or any part of it of three letters or more that no
     * other student on the roster shares, written with its capital as on the roster ("How is
     * Amina doing?"). Parts match only with their capital so that a student called Mark or Grace
     * does not take the words "mark" and "grace" out of a question. A shared part ("Kamau" for
     * two students) is left as typed, since naming one of them would be a guess; so is a name the
     * roster does not hold. Longest names first, so "Amina Wanjiru" is one handle and never
     * "S1 Wanjiru".
     *
     * @param  array<string, string>  $roster  student id => name
     */
    public function conceal(string $text, array $roster): string
    {
        $owners = [];
        foreach ($roster as $studentId => $name) {
            if (preg_match('/\s/u', trim($name)) === 1) {
                $owners['i:'.mb_strtolower(trim($name))][$studentId] = true;
            }
            foreach (preg_split('/[^\p{L}\p{M}\'’-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (mb_strlen($part) >= 3) {
                    $owners['c:'.$part][$studentId] = true;
                }
            }
        }

        $unique = [];
        foreach ($owners as $key => $ids) {
            [$mode, $name] = explode(':', (string) $key, 2);
            if ($name !== '' && count($ids) === 1) {
                $unique[] = [$name, $mode === 'i' ? 'iu' : 'u', (string) array_key_first($ids)];
            }
        }
        usort($unique, fn (array $a, array $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        foreach ($unique as [$name, $flags, $studentId]) {
            $pattern = '/(?<![\p{L}\p{M}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{M}\p{N}])/'.$flags;
            if (preg_match($pattern, $text) === 1) {
                $text = preg_replace($pattern, $this->for($studentId), $text) ?? $text;
            }
        }

        return $text;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->byStudent;
    }
}
