<?php

namespace App\Http\Controllers;

use App\Sync\InvalidSyncCursor;
use App\Sync\SyncCursor;
use App\Sync\SyncPull;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/* GET /sync?since=&limit= (docs/spec/sync-protocol.md; ADR 0010). `since` has
   three meanings and only one of them is a first pull:
     omitted      a bootstrap: the live tables, from the start;
     an integer   a log pull after that seq, 0 included, so an institution
                  whose log is still empty does not re-bootstrap forever;
     anything else  a bootstrap continuation, or a 422. */
class SyncController extends Controller
{
    private const DEFAULT_LIMIT = 500;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $pull = new SyncPull($request->user()->id);
        $since = $validated['since'] ?? null;

        if ($since === null) {
            return response()->json($pull->snapshot(null, $limit));
        }

        if (ctype_digit($since) && strlen($since) <= 18) {
            return response()->json($pull->fromLog((int) $since, $limit));
        }

        try {
            $cursor = SyncCursor::decode($since, array_keys(SyncPull::modelsByTable()));
        } catch (InvalidSyncCursor) {
            throw ValidationException::withMessages(['since' => 'The since cursor is not valid.']);
        }

        return response()->json($pull->snapshot($cursor, $limit));
    }
}
