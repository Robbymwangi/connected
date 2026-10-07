<?php

namespace App\Sync\Push;

/* The four things a device can do to a conflict (docs/spec/sync-protocol.md, "Conflict commands"). */
enum CommandKind: string
{
    case Propose = 'propose';
    case Accept = 'accept';
    case Refer = 'refer';
    case Resolve = 'resolve';
}
