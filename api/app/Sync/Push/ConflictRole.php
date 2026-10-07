<?php

namespace App\Sync\Push;

/* What the acting user is to one conflict. A party who also moderates the subject is a Party: ADR 0002
   rule 3 says neither party may resolve alone, and rule 5 assumes an impartial moderator. */
enum ConflictRole
{
    /** Neither a party nor a moderator of the subject. */
    case None;

    /** Not a party, moderates the subject, on a conflict between two teachers. */
    case Moderator;

    /** Not a party, moderates the subject, on someone else's conflict with themself. */
    case ModeratorOfSelfConflict;

    /** The author of both sides. */
    case SelfAuthor;

    /** One of two different authors. */
    case Party;
}
