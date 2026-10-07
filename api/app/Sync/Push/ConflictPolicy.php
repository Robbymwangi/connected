<?php

namespace App\Sync\Push;

/* Who may do what to a mark conflict: the table in docs/spec/sync-protocol.md ("Conflict commands"), which is
   ADR 0002 and its 2026-10-07 amendment made executable. Pure, with no database, so it can be read in one place
   and tested against the same case file the client's policy is tested against.

   It is two questions asked at two points, because the version check sits between them. actor() depends only on who
   is asking, and is answered before the version check. state() depends on what the conflict holds now, and is
   answered only at an equal version: a device that had not yet seen the other party's proposal gets the fresh
   conflict back, not an invalid. Both return the rejection to throw, or null when the act may go ahead. */
final class ConflictPolicy
{
    /** Agreement is bounded to one proposal each (ADR 0002 rule 4). */
    public const MAX_PROPOSALS = 2;

    public static function role(string $actorId, string $sideAUserId, string $sideBUserId, bool $moderatesSubject): ConflictRole
    {
        $isParty = $actorId === $sideAUserId || $actorId === $sideBUserId;

        if ($isParty) {
            return $sideAUserId === $sideBUserId ? ConflictRole::SelfAuthor : ConflictRole::Party;
        }

        if (! $moderatesSubject) {
            return ConflictRole::None;
        }

        return $sideAUserId === $sideBUserId ? ConflictRole::ModeratorOfSelfConflict : ConflictRole::Moderator;
    }

    public static function actor(ConflictRole $role, CommandKind $kind): ?SyncRejection
    {
        return match ($role) {
            ConflictRole::None, ConflictRole::ModeratorOfSelfConflict => SyncRejection::forbidden(),
            ConflictRole::Moderator => $kind === CommandKind::Resolve ? null : SyncRejection::forbidden(),
            ConflictRole::SelfAuthor => $kind === CommandKind::Resolve ? null : SyncRejection::invalid('a conflict between your own edits is resolved, not proposed or referred'),
            ConflictRole::Party => $kind === CommandKind::Resolve ? SyncRejection::forbidden() : null,
        };
    }

    public static function state(ConflictRole $role, CommandKind $kind, ConflictState $state, string $actorId): ?SyncRejection
    {
        if ($state->resolved) {
            return SyncRejection::invalid('the conflict is already resolved');
        }

        // A moderator or a self author resolving is not bound by what the parties did.
        if ($role !== ConflictRole::Party) {
            return null;
        }

        if ($state->referred) {
            return SyncRejection::invalid('the conflict has been referred to a moderator');
        }

        $count = count($state->proposalByIds);
        $last = $count === 0 ? null : $state->proposalByIds[$count - 1];
        $ownPending = $last === $actorId;

        return match ($kind) {
            CommandKind::Propose => match (true) {
                $ownPending => SyncRejection::invalid('your proposal is waiting for the other party'),
                $count >= self::MAX_PROPOSALS => SyncRejection::invalid('each party has made a proposal; accept or refer'),
                default => null,
            },
            CommandKind::Accept => match (true) {
                $last === null => SyncRejection::invalid('there is no proposal to accept'),
                $ownPending => SyncRejection::invalid('you cannot accept your own proposal'),
                default => null,
            },
            CommandKind::Refer => $ownPending && $count >= self::MAX_PROPOSALS
                ? SyncRejection::invalid('your counter is waiting for the original proposer')
                : null,
            CommandKind::Resolve => null,
        };
    }

    /* The command a resolution's wire kind stands for: an acceptance of a pending proposal, or a direct resolution.
       `auto` is written only by the server (both sides held the same mark), so a device that sends it is wrong. */
    public static function commandFor(mixed $kind): CommandKind|SyncRejection
    {
        return match ($kind) {
            'agreed' => CommandKind::Accept,
            'self', 'moderated' => CommandKind::Resolve,
            'auto' => SyncRejection::invalid('auto is written only by the server'),
            default => SyncRejection::invalid('resolution kind must be agreed, self, or moderated'),
        };
    }

    /* A direct resolution's kind must fit who is resolving: self is the author of a conflict between their own
       edits, moderated is a moderator who is not a party. Reached only after actor() has let the role resolve at all,
       so a party sending either was already forbidden. */
    public static function resolutionFits(ConflictRole $role, string $kind): ?SyncRejection
    {
        return match (true) {
            $kind === 'self' && $role !== ConflictRole::SelfAuthor => SyncRejection::invalid('only the author of a conflict between their own edits resolves it as self'),
            $kind === 'moderated' && $role !== ConflictRole::Moderator => SyncRejection::invalid('only a moderator who is not a party resolves a conflict as moderated'),
            default => null,
        };
    }

    /* Computed from the stored proposals, never taken from the device. The bound is a count held in the record,
       so it needs no clock (ADR 0002 rule 4). */
    public static function referralReason(int $proposalCount): string
    {
        return $proposalCount >= self::MAX_PROPOSALS ? 'rounds' : 'party';
    }
}
