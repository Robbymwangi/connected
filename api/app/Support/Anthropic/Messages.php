<?php

namespace App\Support\Anthropic;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/* One live call to the Messages API, for the report assistant (ADR 0013, decision 5): a teacher is
   waiting, so unlike the batch client it retries a 429 or 5xx only once, briefly, and has a short
   timeout. Laravel's HTTP client, no SDK (ADR 0007, decision 5). Anything else is thrown; the
   caller decides what a failure means, and for the assistant it always means the fixed reply. */
final class Messages
{
    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl,
        private readonly string $version,
        private readonly int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('services.anthropic');

        return new self((string) $config['key'], rtrim((string) $config['base_url'], '/'), (string) $config['version'], (int) $config['assistant_timeout']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the decoded message
     */
    public function create(array $params): array
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['x-api-key' => $this->key, 'anthropic-version' => $this->version])
            ->acceptJson()
            ->timeout($this->timeoutSeconds)
            ->retry(2, 1000, function (\Throwable $exception): bool {
                return $exception instanceof RequestException
                    && ($exception->response->status() === 429 || $exception->response->status() >= 500);
            }, throw: false)
            ->post('/v1/messages', $params)
            ->throw()
            ->json();
    }
}
