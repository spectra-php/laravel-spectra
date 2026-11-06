<?php

declare(strict_types=1);

namespace Spectra\Enums;

use Illuminate\Support\Str;

/**
 * What kind of output a tracked model produces, stored in `model_type`.
 *
 * Handlers set it from the endpoint they matched; otherwise it comes from the
 * model's catalog `type` (fromPricingType()). The catalog has a single
 * `audio` type for both directions, so audio models are split into TTS and
 * STT by their capabilities and, failing that, by their id (fromAudioSlug()).
 */
enum ModelType: string
{
    case Text = 'text';
    case Embedding = 'embedding';
    case Image = 'image';
    case Video = 'video';
    case Tts = 'tts';
    case Stt = 'stt';

    /**
     * Get the model type's name as the dashboard shows it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Embedding => 'Embedding',
            self::Image => 'Image',
            self::Video => 'Video',
            self::Tts => 'Text-to-Speech',
            self::Stt => 'Speech-to-Text',
        };
    }

    /**
     * Get the model type for a catalog `type`.
     *
     * Null for `audio`, which the caller splits into TTS or STT, and for any
     * other value: `ModelDefinition::type()` takes free text, so a custom
     * catalog may use a type Spectra doesn't know.
     */
    public static function fromPricingType(?string $type): ?self
    {
        return match ($type) {
            'text' => self::Text,
            'embedding' => self::Embedding,
            'image' => self::Image,
            'video' => self::Video,
            'audio' => null,
            default => null,
        };
    }

    /**
     * Guess TTS or STT from an audio model id, for models whose catalog
     * capabilities don't tell. An id that names neither counts as STT, so
     * every audio model still gets a type.
     */
    public static function fromAudioSlug(string $slug): self
    {
        if (Str::contains($slug, ['tts', 'speech'], ignoreCase: true)) {
            return self::Tts;
        }

        return self::Stt;
    }
}
