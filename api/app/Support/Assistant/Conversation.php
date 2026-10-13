<?php

namespace App\Support\Assistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/* A conversation's history, held by the server (ADR 0013, decision 3): the client sends only the
   new question and the id it was given, so it cannot forge an earlier assistant turn. What is kept
   is what the provider saw, in handle form, never the name-restored answer, with the handle map
   that gives those handles meaning; both expire together. A conversation belongs to one user and
   one report scope: another user's id, or the same id with different filters, starts afresh. */
final class Conversation
{
    /** How long a quiet conversation is kept. */
    public const MINUTES = 30;

    /** Question and answer pairs kept for context. */
    public const TURNS = 4;

    /** @param  list<array{question: string, answer: string}>  $turns */
    private function __construct(
        public readonly string $id,
        private readonly string $owner,
        public array $turns,
        public Handles $handles,
    ) {}

    public static function resume(?string $id, string $userId, string $scopeKey): self
    {
        $owner = $userId.'|'.$scopeKey;
        $stored = $id === null ? null : Cache::get(self::key($id));

        if (is_array($stored) && ($stored['owner'] ?? null) === $owner) {
            return new self($id, $owner, $stored['turns'], new Handles($stored['handles']));
        }

        return new self((string) Str::uuid7(), $owner, [], new Handles);
    }

    public function remember(string $question, string $answer): void
    {
        $this->turns = array_slice([...$this->turns, ['question' => $question, 'answer' => $answer]], -self::TURNS);

        Cache::put(self::key($this->id), [
            'owner' => $this->owner,
            'turns' => $this->turns,
            'handles' => $this->handles->all(),
        ], now()->addMinutes(self::MINUTES));
    }

    private static function key(string $id): string
    {
        return "assistant:conversation:{$id}";
    }
}
