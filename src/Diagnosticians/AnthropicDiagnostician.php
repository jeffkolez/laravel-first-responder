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
 * Diagnoses via the Anthropic Messages API.
 *
 * Shape differs from OpenAI's in three ways that are easy to get wrong:
 *
 *  - auth is `x-api-key`, not a bearer token;
 *  - `anthropic-version` is a required header, not optional;
 *  - the system prompt is a top-level field, not a message with role=system.
 *    Passing it as a message is accepted and then largely ignored, which fails
 *    silently and produces worse answers for no visible reason.
 */
final class AnthropicDiagnostician implements Diagnostician
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly PromptBuilder $prompts,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $baseUrl = 'https://api.anthropic.com/v1',
        private readonly int $timeout = 20,
    ) {
    }

    public function diagnose(Incident $incident): ?Diagnosis
    {
        if ($this->apiKey === '') {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
                ->timeout($this->timeout)
                ->retry(1, 500, throw: false)
                ->post(rtrim($this->baseUrl, '/') . '/messages', [
                    'model' => $this->model,
                    'max_tokens' => 300,
                    'system' => $this->prompts->system(),
                    'messages' => [
                        ['role' => 'user', 'content' => $this->prompts->user($incident)],
                    ],
                ]);

            if (! $response->successful()) {
                $this->logger?->warning('first-responder: Anthropic diagnosis failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $text = (string) ($response->json('content.0.text') ?? '');

            if (trim($text) === '') {
                return null;
            }

            [$summary, $confident] = $this->prompts->interpret($text);

            return Diagnosis::make($summary, $confident, $this->model);
        } catch (Throwable $e) {
            $this->logger?->warning('first-responder: Anthropic diagnosis errored.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
