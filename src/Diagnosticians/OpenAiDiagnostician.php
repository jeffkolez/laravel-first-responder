<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Diagnosticians;

use Illuminate\Support\Facades\Http;
use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Support\Diagnosis;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\PromptBuilder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Diagnoses via the OpenAI chat completions API.
 *
 * Uses Laravel's HTTP client instead of an SDK: this is one endpoint with a
 * stable request shape, and taking a hard dependency on a fast-moving vendor
 * package to send one JSON body would be a poor trade for consumers of this
 * library. It also means Http::fake() works in your tests for free.
 *
 * `base_url` is configurable so this same driver serves anything speaking the
 * OpenAI wire format: Azure OpenAI, OpenRouter, Groq, or a local Ollama. The
 * last of those is the answer for teams that cannot send source code
 * off-premises at all.
 */
final class OpenAiDiagnostician implements Diagnostician
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly PromptBuilder $prompts,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $baseUrl = 'https://api.openai.com/v1',
        private readonly int $timeout = 20,
    ) {
    }

    public function diagnose(Incident $incident): ?Diagnosis
    {
        if ($this->apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                // One retry only. This runs on a queue behind a throttle, and a
                // provider outage should degrade the report, not pile up
                // workers retrying into a wall.
                ->retry(1, 500, throw: false)
                ->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->prompts->system()],
                        ['role' => 'user', 'content' => $this->prompts->user($incident)],
                    ],
                    'max_completion_tokens' => 300,
                ]);

            if (! $response->successful()) {
                $this->logger?->warning('first-responder: OpenAI diagnosis failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $text = (string) ($response->json('choices.0.message.content') ?? '');

            if (trim($text) === '') {
                return null;
            }

            [$summary, $confident] = $this->prompts->interpret($text);

            return Diagnosis::make($summary, $confident, $this->model);
        } catch (Throwable $e) {
            // Contractually must not throw. See Diagnostician.
            $this->logger?->warning('first-responder: OpenAI diagnosis errored.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
