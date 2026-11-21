<?php

namespace App\Conversion\Ai;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Client;
use App\Conversion\ConversionFailed;
use App\Conversion\ConversionFailure;
use Throwable;

/**
 * Pembungkus tipis SDK resmi Anthropic untuk alat AI Konvertin.
 *
 * API key hanya dibaca dari konfigurasi server (KONVERTIN_ANTHROPIC_API_KEY)
 * dan tidak pernah dikirim ke peramban.
 */
class ClaudeClient
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function isConfigured(): bool
    {
        return trim((string) config('konvertin.ai.api_key')) !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>  $outputConfig
     *
     * @throws ConversionFailed
     */
    public function send(string $system, array $messages, array $outputConfig, int $maxTokens = 16000): BetaMessage
    {
        if (! $this->isConfigured()) {
            throw ConversionFailed::because(ConversionFailure::EngineUnavailable, 'anthropic api key missing');
        }

        try {
            $message = $this->client()->beta->messages->create(
                maxTokens: $maxTokens,
                messages: $messages,
                model: (string) config('konvertin.ai.model'),
                system: $system,
                outputConfig: $outputConfig,
                // Bila model menolak karena kebijakan, server mencoba model pengganti bawaan.
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
            );
        } catch (Throwable $exception) {
            throw new ConversionFailed(ConversionFailure::AiFailed, 'anthropic request failed: '.$exception::class, $exception);
        }

        if ($message->stopReason === 'refusal') {
            throw ConversionFailed::because(ConversionFailure::AiRefused, 'anthropic refusal');
        }

        if ($message->stopReason === 'max_tokens') {
            throw ConversionFailed::because(ConversionFailure::AiFailed, 'anthropic output truncated');
        }

        return $message;
    }

    protected function client(): Client
    {
        return new Client(apiKey: (string) config('konvertin.ai.api_key'));
    }
}
