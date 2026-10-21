<?php

namespace App\Http\Controllers;

use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/* GET /api/reports/{report}/download: a short-lived presigned URL for a report card's PDF
   (docs/spec/workflow.md, Report cards). Online only: nothing about the PDF is cached ahead of a
   request, and the device fetches the PDF straight from storage, not through the API. Any
   authenticated user of the institution may ask, as for every read (docs/spec/access-model.md); a
   report of another school is a 404 through the institution scope on the binding.

   The URL is signed by the s3-public disk, which is the s3 disk with the endpoint a browser can
   reach. In production the two are the same; under Sail the API reaches RustFS as rustfs:9000 while
   the browser reaches it as localhost:9000, and a presigned URL is signed for its host, so rewriting
   the host afterwards would break the signature. Presigning is local: it makes no request. */
class ReportsController extends Controller
{
    private const MINUTES = 10;

    public function download(Report $report): JsonResponse
    {
        $expiresAt = now()->addMinutes(self::MINUTES);

        return response()->json(['data' => [
            'url' => Storage::disk('s3-public')->temporaryUrl($report->s3_key, $expiresAt),
            'expiresAt' => $expiresAt->toIso8601ZuluString(),
        ]]);
    }
}
