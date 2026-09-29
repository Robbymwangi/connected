<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/* Thrown when an update or soft delete targets a row whose version no
   longer matches what was read: another write already advanced it. ADR
   0001, AGENTS.md: version is how a stale write is detected, never a
   wall-clock comparison. This guards the model layer's own writes against
   two server-side processes racing on the same row; it is not the sync
   protocol's client-facing conflict flow (docs/spec/sync-protocol.md, #35,
   ADR 0002), which is a separate, later decision about what a device sees
   when its own base_version is stale. */
class StaleVersionException extends RuntimeException
{
    public function __construct(public readonly Model $model)
    {
        parent::__construct(sprintf(
            '%s [%s] was modified by another write before this one could be saved.',
            $model::class,
            $model->getKey(),
        ));
    }
}
