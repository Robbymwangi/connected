<?php

namespace App\Http\Controllers;

use App\Sync\Push\SyncPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* POST /sync (docs/spec/sync-protocol.md): a batch of outbox entries, one result
   each, in the order sent. The controller only checks the shape of the batch and
   hands each entry to SyncPush; it opens no transaction, because each entry is its
   own and a batch must never be one. At most 100 entries: each is its own
   transaction, so the limit does not lengthen any lock, it bounds a request's
   duration and how much a dropped response costs on a poor connection. */
class SyncPushController extends Controller
{
    private const MAX_ENTRIES = 100;

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'entries' => ['present', 'array', 'list', 'max:'.self::MAX_ENTRIES],
            'entries.*' => ['array'],
        ]);

        $push = new SyncPush($request->user());

        return response()->json([
            'results' => array_map(fn (array $raw) => $push->process($raw)->toArray(), $request->input('entries')),
        ]);
    }
}
