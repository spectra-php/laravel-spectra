<?php

namespace Spectra\Enums;

enum ModelType: string
{
    case Text = 'text';
    case Embedding = 'embedding';
    case Image = 'image';
    case Video = 'video';
    case Tts = 'tts';
    case Stt = 'stt';

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

    public static function fromPricingType(?string $type): ?self
    {
        return match ($type) {
            'text' => self::Text,
            'embedding' => self::Embedding,
            'image' => self::Image,
            'audio' => null, // Audio needs model name inspection to distinguish TTS vs STT
            'video' => self::Video,
            default => null,
        };
    }

    public static function fromAudioSlug(string $slug): self
    {
        $lower = strtolower($slug);

        if (str_contains($lower, 'tts') || str_contains($lower, 'speech')) {
            return self::Tts;
        }

        if (str_contains($lower, 'whisper') || str_contains($lower, 'transcri')) {
            return self::Stt;
        }

        // Default audio to STT
        return self::Stt;
    }
}
