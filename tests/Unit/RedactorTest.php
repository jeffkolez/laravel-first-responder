<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Tests\Unit;

use JeffKolez\FirstResponder\Support\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Redaction is the reason this package can be adopted at all: it sends real
 * source code to a third party, and every one of these assertions is the
 * difference between that being acceptable and being a breach.
 */
class RedactorTest extends TestCase
{
    private function redactor(array $env = []): Redactor
    {
        return new Redactor([], [], $env + [
            'APP_KEY' => 'base64:Zm9vYmFyYmF6cXV4MTIzNDU2Nzg5MA==',
            'DB_PASSWORD' => 'sup3rSecretDbPassw0rd',
            'APP_ENV' => 'local',
            'MAIL_FROM_NAME' => 'They Will Kill You',
        ]);
    }

    /**
     * The strongest layer, and the one no regex can replicate: we know the
     * literal value, so it does not matter what shape it has.
     */
    public function test_it_masks_exact_values_of_sensitive_environment_variables(): void
    {
        $out = $this->redactor()->redact('$pdo = connect("sup3rSecretDbPassw0rd");');

        $this->assertStringNotContainsString('sup3rSecretDbPassw0rd', $out);
        $this->assertStringContainsString(Redactor::MASK, $out);
    }

    /**
     * Without a length floor and a denylist, APP_ENV=local turns every
     * occurrence of "local" in your source into [redacted] — including in the
     * lines the model needs to read.
     */
    public function test_it_does_not_mask_short_or_common_environment_values(): void
    {
        $this->assertStringContainsString('local', $this->redactor()->redact('the local disk'));
    }

    public function test_it_does_not_mask_values_of_non_sensitive_variables(): void
    {
        $this->assertStringContainsString(
            'They Will Kill You',
            $this->redactor()->redact('from They Will Kill You')
        );
    }

    /*
     * The ATTRIBUTE, not the @dataProvider docblock annotation.
     *
     * PHPUnit 12 removed annotation-based providers entirely. Because PHPUnit
     * 12 requires PHP 8.3, the annotation kept working on the 8.2 leg and
     * silently stopped on 8.3 and 8.4 — where the test was called with no
     * arguments and died with ArgumentCountError. The attribute is understood
     * by PHPUnit 10, 11 and 12 alike.
     */
    #[DataProvider('tokenShapes')]
    public function test_it_masks_known_token_shapes(string $secret): void
    {
        $this->assertStringNotContainsString(
            $secret,
            $this->redactor()->redact("value is {$secret} here")
        );
    }

    public static function tokenShapes(): array
    {
        return [
            'openai' => ['sk-abcdefghijklmnopqrstuvwxyz012345'],
            'anthropic' => ['sk-ant-api03-abcdefghijklmnopqrstuvwxyz'],
            'github pat' => ['ghp_abcdefghijklmnopqrstuvwxyz0123'],
            'slack' => ['xoxb-123456789012-abcdefghijkl'],
            'aws' => ['AKIAIOSFODNN7EXAMPLE'],
            'sentry' => ['sntrys_abcdefghijklmnopqrstuvwxyz'],
            'laravel app key' => ['base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dg=='],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcdefghijk'],
        ];
    }

    /**
     * Anthropic keys start with the OpenAI prefix. If the looser rule runs
     * first it matches `sk-` and leaves `ant-` dangling in the output — a
     * partial mask that also proves the ordering is wrong.
     */
    public function test_the_anthropic_rule_runs_before_the_openai_rule(): void
    {
        $out = $this->redactor()->redact('sk-ant-api03-abcdefghijklmnopqrstuvwxyz');

        $this->assertStringNotContainsString('ant-', $out);
    }

    /**
     * Masking the whole match throws away the clue. "password => [redacted]"
     * still tells the model a password is involved, which is often the answer.
     */
    public function test_it_preserves_the_identifying_part_while_masking_the_value(): void
    {
        $r = $this->redactor();

        $assignment = $r->redact("'password' => 'letmein123',");
        $this->assertStringContainsString('password', $assignment);
        $this->assertStringNotContainsString('letmein123', $assignment);

        $header = $r->redact('Authorization: Bearer abcdef1234567890abcdef');
        $this->assertStringContainsString('Bearer', $header);
        $this->assertStringNotContainsString('abcdef1234', $header);

        $dsn = $r->redact('mysql://root:hunter2pass@db.internal/app');
        $this->assertStringContainsString('mysql://root', $dsn);
        $this->assertStringNotContainsString('hunter2pass', $dsn);

        $dotenv = $r->redact('STRIPE_SECRET=sk_live_zzzzzzzzzzzz');
        $this->assertStringContainsString('STRIPE_SECRET', $dotenv);
        $this->assertStringNotContainsString('sk_live_z', $dotenv);
    }

    public function test_it_masks_private_key_blocks_including_the_body(): void
    {
        $pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIsecret\n-----END RSA PRIVATE KEY-----";

        $this->assertStringNotContainsString('MIIsecret', $this->redactor()->redact($pem));
    }

    public function test_arrays_are_masked_by_key_name_whatever_the_value_looks_like(): void
    {
        $out = $this->redactor()->redactArray([
            'api_key' => 'an ordinary looking string',
            'name' => 'Ted',
            'nested' => ['DB_PASSWORD' => 'x', 'ok' => 'y'],
        ]);

        $this->assertSame(Redactor::MASK, $out['api_key']);
        $this->assertSame('Ted', $out['name']);
        $this->assertSame(Redactor::MASK, $out['nested']['DB_PASSWORD']);
        $this->assertSame('y', $out['nested']['ok']);
    }

    /** A user's broken custom regex must not blank the text it was applied to. */
    public function test_a_malformed_custom_pattern_is_skipped_safely(): void
    {
        $r = new Redactor(['/[unclosed/'], [], []);

        $this->assertSame('hello world', $r->redact('hello world'));
    }

    public function test_emails_can_be_disabled(): void
    {
        $on = new Redactor([], [], [], true);
        $off = new Redactor([], [], [], false);

        $this->assertStringNotContainsString('jeff@example.com', $on->redact('user jeff@example.com'));
        $this->assertStringContainsString('jeff@example.com', $off->redact('user jeff@example.com'));
    }

    public function test_custom_literals_are_masked(): void
    {
        $r = new Redactor([], ['my-internal-codename'], []);

        $this->assertStringNotContainsString(
            'my-internal-codename',
            $r->redact('deploying my-internal-codename now')
        );
    }
}
