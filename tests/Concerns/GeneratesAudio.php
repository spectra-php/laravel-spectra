<?php

namespace Spectra\Tests\Concerns;

trait GeneratesAudio
{
    protected function generateSilentWav(int $seconds = 1, int $sampleRate = 8000): string
    {
        $channels = 1;
        $bitsPerSample = 16;
        $bytesPerSample = intdiv($bitsPerSample, 8);
        $dataSize = $seconds * $sampleRate * $channels * $bytesPerSample;
        $byteRate = $sampleRate * $channels * $bytesPerSample;
        $blockAlign = $channels * $bytesPerSample;

        return 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('VvvVVvv', 16, 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            .'data'
            .pack('V', $dataSize)
            .str_repeat("\x00", $dataSize);
    }
}
