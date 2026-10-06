<?php

namespace App\Sync;

use InvalidArgumentException;

/* A bootstrap continuation token that does not decode to a valid position. The
   endpoint answers 422 rather than guessing where to resume. */
final class InvalidSyncCursor extends InvalidArgumentException {}
