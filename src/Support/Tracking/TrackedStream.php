<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Closure;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * A response body that lets Spectra see the bytes as the application reads
 * them, without reading anything itself.
 *
 * The middleware wraps streamed (and non-rewindable) response bodies in this
 * decorator. Every chunk the application reads is passed to `$onRead`, and
 * `$onEnd` runs once when the body is fully read, closed, or garbage
 * collected — so a stream is tracked even when the application stops early.
 * Bytes that are read again after a rewind are not passed on twice.
 *
 * Tracking callbacks never throw into the application.
 */
class TrackedStream implements StreamInterface
{
    /** Bytes from the start of the body that were already passed to onRead. */
    protected int $fed = 0;

    /** Current read position, counted from the start of the body. */
    protected int $position = 0;

    protected bool $ended = false;

    /**
     * Create a new tracked stream around a provider response body.
     *
     * @param  Closure(string): void  $onRead
     * @param  Closure(): void  $onEnd
     */
    public function __construct(
        protected StreamInterface $stream,
        protected Closure $onRead,
        protected Closure $onEnd,
    ) {
        try {
            $this->position = $this->fed = $stream->tell();
        } catch (Throwable) {
            $this->position = $this->fed = 0;
        }
    }

    /**
     * Read bytes from the stream, feeding them to the tracker and finishing it at the end.
     */
    public function read(int $length): string
    {
        $bytes = $this->stream->read($length);
        $this->observe($bytes);

        if ($this->stream->eof()) {
            $this->end();
        }

        return $bytes;
    }

    /**
     * Read the rest of the stream through read(), so every byte is tracked.
     */
    public function getContents(): string
    {
        $contents = '';

        while (! $this->stream->eof()) {
            $chunk = $this->read(8192);

            if ($chunk === '') {
                break;
            }

            $contents .= $chunk;
        }

        $this->end();

        return $contents;
    }

    /**
     * Read the whole stream from the start, returning an empty string on failure.
     */
    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Finish tracking and close the stream.
     */
    public function close(): void
    {
        $this->end();
        $this->stream->close();
    }

    /**
     * Finish tracking and detach the underlying resource.
     */
    public function detach()
    {
        $this->end();

        return $this->stream->detach();
    }

    /**
     * Get the size of the stream, if known.
     */
    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    /**
     * Get the position of the read pointer.
     */
    public function tell(): int
    {
        return $this->stream->tell();
    }

    /**
     * Determine if the stream is at its end.
     */
    public function eof(): bool
    {
        return $this->stream->eof();
    }

    /**
     * Determine if the stream is seekable.
     */
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    /**
     * Seek the stream, keeping the read position so re-read bytes are not tracked twice.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);

        try {
            $this->position = $this->stream->tell();
        } catch (Throwable) {
            $this->position = $offset;
        }
    }

    /**
     * Seek to the start of the stream.
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Determine if the stream is writable.
     */
    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    /**
     * Write to the underlying stream.
     */
    public function write(string $string): int
    {
        return $this->stream->write($string);
    }

    /**
     * Determine if the stream is readable.
     */
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * Get the underlying stream's metadata.
     */
    public function getMetadata(?string $key = null)
    {
        return $this->stream->getMetadata($key);
    }

    /**
     * Finish tracking when the stream is released without being read to the end.
     */
    public function __destruct()
    {
        $this->end();
    }

    /**
     * Pass on only the part of a chunk that hasn't been seen yet.
     */
    protected function observe(string $bytes): void
    {
        $start = $this->position;
        $this->position += strlen($bytes);

        if ($bytes === '' || $this->position <= $this->fed) {
            return;
        }

        $fresh = $start >= $this->fed ? $bytes : substr($bytes, $this->fed - $start);
        $this->fed = $this->position;

        $this->safely($this->onRead, $fresh);
    }

    /**
     * Run the end callback once, however the stream ends.
     */
    protected function end(): void
    {
        if ($this->ended) {
            return;
        }

        $this->ended = true;

        $this->safely($this->onEnd);
    }

    /**
     * Run a tracking callback, reporting its failure instead of throwing into the app.
     */
    protected function safely(Closure $callback, mixed ...$arguments): void
    {
        try {
            $callback(...$arguments);
        } catch (Throwable $e) {
            // The application may be shutting down; tracking is best effort.
            rescue(fn () => report($e), report: false);
        }
    }
}
