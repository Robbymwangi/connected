<?php

namespace App\Support\Anthropic;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/* The three calls comment generation makes to the Message Batches API, on Laravel's HTTP
   client with no SDK (ADR 0007, decision 5): create a batch, retrieve it, and read its
   results. A 429 or 5xx is retried here with backoff; anything else is thrown, and the
   job that called decides what an exception means. Results come back in any order, so
   callers key them by custom_id, never by position. Nothing here holds a transaction. */
final class Batches
{
    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl,
        private readonly string $version,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('services.anthropic');

        return new self((string) $config['key'], rtrim((string) $config['base_url'], '/'), (string) $config['version']);
    }

    /**
     * @param  list<array{custom_id: string, params: array<string, mixed>}>  $requests
     * @return string the batch id
     */
    public function create(array $requests): string
    {
        $batch = $this->client()->post('/v1/messages/batches', ['requests' => $requests])->throw()->json();

        return (string) ($batch['id'] ?? throw new RuntimeException('the batch API returned no batch id'));
    }

    /** @return array{processing_status: string, results_url: ?string} */
    public function retrieve(string $batchId): array
    {
        $batch = $this->client()->get('/v1/messages/batches/'.rawurlencode($batchId))->throw()->json();

        return [
            'processing_status' => (string) ($batch['processing_status'] ?? ''),
            'results_url' => $batch['results_url'] ?? null,
        ];
    }

    /**
     * One decoded result per line of the results file, which is JSON Lines.
     *
     * @return list<array<string, mixed>>
     */
    public function results(string $resultsUrl): array
    {
        // results_url is absolute; the client's base URL applies only to relative paths.
        $body = $this->client()->get($resultsUrl)->throw()->body();
        $results = [];

        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            if ($line !== '') {
                $results[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $results;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['x-api-key' => $this->key, 'anthropic-version' => $this->version])
            ->acceptJson()
            ->timeout(30)
            ->retry(3, fn (int $attempt) => $attempt * 2000, function (\Throwable $exception): bool {
                if (! $exception instanceof RequestException) {
                    return true;
                }

                $status = $exception->response->status();

                return $status === 429 || $status >= 500;
            }, throw: false);
    }
}
