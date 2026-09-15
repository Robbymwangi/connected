/* Static stand-in for the mutation outbox. pendingCount is the number of queued
   mutations; lastSyncedAt is when the outbox was last drained. */
export const syncState = {
  pendingCount: 3,
  lastSyncedAt: new Date(Date.now() - 2 * 60 * 60 * 1000),
}
