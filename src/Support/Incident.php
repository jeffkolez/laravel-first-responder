<?php

declare(strict_types=1);

namespace JeffKolez\FirstResponder\Support;

use Throwable;

/**
 * Everything the package knows about one thing going wrong.
 *
 * This is the seam of the design. A native Laravel exception and a Sentry
 * webhook payload arrive looking nothing alike; both become an Incident, and
 * every stage after that (redaction, diagnosis, notification) is written
 * against this one shape. Adding a third source later means writing one
 * normaliser and touching nothing else.
 *
 * Serialisable to and from an array because it crosses a queue boundary, and
 * queueing a raw Throwable is a well-known way to produce unserializable jobs.
 */
final class Incident
{
    /**
     * @param  Frame[]                $frames
     * @param  array<string, mixed>   $context  Arbitrary extra (request data, user, tags).
     */
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly array $frames = [],
        public readonly ?string $url = null,
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
        public readonly string $level = 'error',
        public readonly array $context = [],
        public readonly ?string $externalId = null,
        public readonly ?string $externalUrl = null,
    ) {
    }

    public static function fromThrowable(Throwable $e, array $context = []): self
    {
        $frames = [new Frame($e->getFile(), $e->getLine(), null, true)];

        foreach ($e->getTrace() as $t) {
            if (! isset($t['file'])) {
                // Internal/closure frames with no file are not actionable and
                // just crowd out the ones that are.
                continue;
            }

            $frames[] = new Frame(
                (string) $t['file'],
                (int) ($t['line'] ?? 0),
                isset($t['function']) ? (string) $t['function'] : null,
                true,
            );
        }

        return new self(
            $e::class,
            $e->getMessage(),
            $frames,
            null,
            null,
            null,
            'error',
            $context,
        );
    }

    /** Short human title, e.g. "RuntimeException: Connection refused". */
    public function title(): string
    {
        $message = trim($this->message);

        return $message === '' ? $this->type : "{$this->type}: {$message}";
    }

    /**
     * The frames worth showing, innermost first.
     *
     * Two things happen here:
     *
     *  - vendor/framework frames are dropped, because "the error happened in
     *    Illuminate\Routing\Router" is true of almost every Laravel exception
     *    and tells nobody anything;
     *  - what remains is capped, because past three frames nobody is reading
     *    and every extra frame is tokens on the diagnosis bill.
     *
     * If filtering leaves nothing (a crash entirely inside vendor code) the
     * unfiltered frames are returned instead. A less useful report beats a
     * report with no location in it at all.
     *
     * @return Frame[]
     */
    public function significantFrames(int $limit = 3): array
    {
        $inApp = array_values(array_filter($this->frames, static fn (Frame $f) => $f->inApp));

        $frames = $inApp === [] ? $this->frames : $inApp;

        return array_slice($frames, 0, max(1, $limit));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'message' => $this->message,
            'frames' => array_map(static fn (Frame $f) => $f->toArray(), $this->frames),
            'url' => $this->url,
            'environment' => $this->environment,
            'release' => $this->release,
            'level' => $this->level,
            'context' => $this->context,
            'external_id' => $this->externalId,
            'external_url' => $this->externalUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['type'] ?? 'Error'),
            (string) ($data['message'] ?? ''),
            array_map(
                static fn (array $f) => Frame::fromArray($f),
                (array) ($data['frames'] ?? [])
            ),
            $data['url'] ?? null,
            $data['environment'] ?? null,
            $data['release'] ?? null,
            (string) ($data['level'] ?? 'error'),
            (array) ($data['context'] ?? []),
            $data['external_id'] ?? null,
            $data['external_url'] ?? null,
        );
    }
}
