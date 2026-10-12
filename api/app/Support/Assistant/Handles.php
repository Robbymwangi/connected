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

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->byStudent;
    }
}
