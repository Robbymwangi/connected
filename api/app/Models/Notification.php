<?php

namespace App\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/* Written by the server (a conflict raised, a report ready), read by a
   device by pull; unread is the one field a device pushes back, matching
   AGENTS.md's frontend rule that notifications are synced job-status rows,
   not push infrastructure. Not Laravel's own notification system: this is a
   plain domain table. docs/spec/data-model.md (#34). */
#[Fillable(['id', 'institution_id', 'user_id', 'kind', 'tone', 'title', 'body', 'unread'])]
class Notification extends Model
{
    use Syncable;

    protected function casts(): array
    {
        return [
            'unread' => 'boolean',
        ];
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
