<?php

namespace App\Sync\Push;

/* What rule 4 decides for an entry whose base version is behind the record's. */
enum StaleVerdict
{
    /** The fields it touches did not move since its base: apply, increment, log. */
    case Merged;

    /** Nothing it touches differs from the record and nothing it touches moved: nothing to do. */
    case Unchanged;

    /** What it touches moved since its base, but already holds the value it sent: the no-op. */
    case UnchangedAfterCollision;

    /** A field it touches moved, to a different value than it sent. */
    case ConflictOverlap;

    /** The record was deleted above its base, and the entry is not the delete. */
    case ConflictDeleted;

    /** The history above its base is not whole, so nothing can safely be merged from it. */
    case ConflictIncomplete;

    /** The record is deleted but nothing above the base deleted it: not specified, refused. */
    case TrashedBase;
}
