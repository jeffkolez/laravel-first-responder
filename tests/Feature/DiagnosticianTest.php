<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JeffKolez\FirstResponder\Contracts\Diagnostician;
use JeffKolez\FirstResponder\Diagnosticians\AnthropicDiagnostician;
use JeffKolez\FirstResponder\Diagnosticians\NullDiagnostician;
use JeffKolez\FirstResponder\Diagnosticians\OpenAiDiagnostician;
use JeffKolez\FirstResponder\Support\Frame;
use JeffKolez\FirstResponder\Support\Incident;
use JeffKolez\FirstResponder\Support\PromptBuilder;
use JeffKolez\FirstResponder\Tests\TestCase;

class DiagnosticianTest extends TestCase
{
    private function incident(): Incident
    {
        return new Incident('TypeError', 'null given', [
            new Frame('app/X.php', 10, 'show', true, ['$a = 1;'], 'return $a->b;', ['}']),
        ]);
    }

    public function test_the_null_driver_is_the_default(): void
    {
        $this->assertInstanceOf(NullDiagnostician::class, app(Diagnostician::class));
    }

    public function test_the_container_builds_the_configured_driver(): void
    {
        $this->withConfig([
            'first-responder.diagnostician' => 'openai',
            'first-responder.diagnosticians.openai.key' => 'test-key',
        ]);

        $this->assertInstanceOf(OpenAiDiagnostician::class, app(Diagnostician::class));

        $this->withConfig([
            'first-responder.diagnostician' => 'anthropic',
            'first-responder.diagnosticians.anthropic.key' => 'test-key',
        ]);

        $this->assertInstanceOf(AnthropicDiagnostician::class, app(Diagnostician::class));
    }

    public function test_openai_sends_a_bearer_token_and_reads_the_answer(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Line 10 dereferences null.']]],
            ]),
        ]);

        $d = new OpenAiDiagnostician('test-key', 'gpt-4o-mini', new PromptBuilder());
        $result = $d->diagnose($this->incident());

        $this->assertNotNull($result);
        $this->assertSame('Line 10 dereferences null.', $result->summary);
        $this->assertTrue($result->confident);

        Http::assertSent(function (Request $r) {
            return $r->hasHeader('Authorization', 'Bearer test-key')
                && $r['model'] === 'gpt-4o-mini'
                && $r['messages'][0]['role'] === 'system'
                && str_contains($r['messages'][1]['content'], 'return $a->b;');
        });
    }

    /**
     * Anthropic differs in three ways that fail quietly if you copy the OpenAI
     * shape: x-api-key instead of a bearer token, a required version header,
     * and `system` as a TOP-LEVEL field rather than a message. Passing the
     * system prompt as a message is accepted and then largely ignored, which
     * degrades answers with nothing in any log to explain why.
     */
    public function test_anthropic_uses_its_own_header_and_top_level_system_field(): void
    {
        Http::fake([
            '*/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Guard the null at line 10.']],
            ]),
        ]);

        $d = new AnthropicDiagnostician('test-key', 'claude-haiku-4-5-20251001', new PromptBuilder());
        $result = $d->diagnose($this->incident());

        $this->assertSame('Guard the null at line 10.', $result?->summary);

        Http::assertSent(function (Request $r) {
            return $r->hasHeader('x-api-key', 'test-key')
                && $r->hasHeader('anthropic-version')
                && ! $r->hasHeader('Authorization')
                && is_string($r['system'])
                && $r['messages'][0]['role'] === 'user';
        });
    }

    public function test_an_unsure_answer_is_marked_low_confidence(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'UNSURE: need the query log.']]],
            ]),
        ]);

        $result = (new OpenAiDiagnostician('k', 'm', new PromptBuilder()))->diagnose($this->incident());

        $this->assertFalse($result?->confident);
        $this->assertSame('need the query log.', $result?->summary);
    }

    /**
     * The contract says a diagnostician never throws. A provider outage must
     * degrade the report, not lose it.
     */
    public function test_a_provider_error_returns_null_rather_than_throwing(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->assertNull(
            (new OpenAiDiagnostician('k', 'm', new PromptBuilder()))->diagnose($this->incident())
        );
    }

    public function test_a_connection_failure_returns_null_rather_than_throwing(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->assertNull(
            (new OpenAiDiagnostician('k', 'm', new PromptBuilder()))->diagnose($this->incident())
        );
    }

    public function test_an_empty_key_short_circuits_without_a_request(): void
    {
        Http::fake();

        $this->assertNull(
            (new OpenAiDiagnostician('', 'm', new PromptBuilder()))->diagnose($this->incident())
        );

        Http::assertNothingSent();
    }

    /**
     * base_url is what lets this same driver point at Ollama or Azure, which is
     * the answer for teams whose code may not leave the network.
     */
    public function test_the_base_url_is_configurable_for_openai_compatible_hosts(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        (new OpenAiDiagnostician('k', 'm', new PromptBuilder(), null, 'http://localhost:11434/v1'))
            ->diagnose($this->incident());

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://localhost:11434/v1'));
    }
}
