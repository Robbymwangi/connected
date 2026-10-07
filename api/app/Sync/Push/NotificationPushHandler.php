<?php

namespace App\Sync\Push;

use App\Models\Notification;
use Illuminate\Database\Eloquent\Model;

/* Notifications over POST /sync: a user marks their own notification read or unread. The server
   writes notifications and devices pull them; `unread` is the one field pushed back
   (docs/spec/sync-protocol.md, Scope; docs/spec/data-model.md, notifications).

   A notification is a personal feed, not institution-wide grading data, so find() is scoped to
   the user on every path: lookup, lock, and a replay's `current`. Another user's notification, or
   another institution's, is therefore indistinguishable from an unknown id, and nothing about it
   is revealed. A create is refused for the same reason: the server writes them.

   No merge groups and no identity fields. Two devices of one user marking it read is the
   equal-value no-op; one reading while another un-reads it is a conflict, answered with the
   current row and no record (a conflict record is a mark conflict, ADR 0002 amendment). */
final class NotificationPushHandler extends PushHandler
{
    public function columns(): array
    {
        return ['unread' => 'unread'];
    }

    public function refused(): array
    {
        return ['deletedAt' => 'a notification cannot be deleted from a device'];
    }

    public function find(string $recordId, bool $lock = false): ?Model
    {
        $query = Notification::withTrashed()->where('user_id', $this->user->id);

        return ($lock ? $query->lockForUpdate() : $query)->find($recordId);
    }

    public function authorize(?Model $record): bool
    {
        return $record === null || $record->user_id === $this->user->id;
    }

    public function check(PushEntry $entry, ?Model $record): void
    {
        // Reached at base 0 for any id the user cannot see: a colleague's, another institution's,
        // or none at all. The same answer for all of them, so nothing leaks.
        if ($record === null) {
            throw SyncRejection::invalid('notifications are written by the server');
        }
    }

    public function incoming(PushEntry $entry, Model $record): array
    {
        $this->validate($entry->fields);

        return ['unread' => $entry->fields['unread']];
    }

    public function resulting(PushEntry $entry, ?Model $record): Model
    {
        if ($record->trashed()) {
            throw SyncRejection::invalid('the notification has been deleted');
        }

        $this->validate($entry->fields);

        $record->fill(['unread' => $entry->fields['unread']]);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function validate(array $fields): void
    {
        if (! is_bool($fields['unread'])) {
            throw SyncRejection::invalid('unread must be true or false');
        }
    }
}
