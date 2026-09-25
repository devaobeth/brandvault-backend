<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GeminiClient
{
    /**
     * @var list<string>
     */
    private const FALLBACK_MODELS = [
        'gemini-3-flash-preview',
        'gemini-3.5-flash-lite',
    ];

    private const PER_MODEL_TIMEOUT_SECONDS = 45;

    private const HIGH_DEMAND_RETRIES = 3;

    /**
     * @param  array{mime: string, data: string}|null  $image  Base64-encoded image bytes (no data: prefix)
     */
    public function generateJson(string $systemPrompt, string $userPrompt, ?array $image = null): array
    {
        $apiKey = config('services.gemini.key');
        $base = rtrim((string) config('services.gemini.base'), '/');

        if (blank($apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        if (function_exists('ini_set')) {
            ini_set('max_execution_time', '0');
        }

        $preferred = (string) config('services.gemini.model', 'gemini-3-flash-preview');
        $models = array_values(array_unique(array_filter([
            $preferred,
            ...self::FALLBACK_MODELS,
        ])));

        $lastMessage = 'No Gemini model succeeded.';

        foreach ($models as $model) {
            for ($attempt = 1; $attempt <= self::HIGH_DEMAND_RETRIES; $attempt++) {
                try {
                    return $this->requestJson($base, $apiKey, $model, $systemPrompt, $userPrompt, $image);
                } catch (RuntimeException $exception) {
                    $lastMessage = $exception->getMessage();

                    if ($this->isHighDemand($lastMessage) && $attempt < self::HIGH_DEMAND_RETRIES) {
                        usleep(400_000 * $attempt);

                        continue;
                    }

                    if ($this->shouldTryNextModel($lastMessage)) {
                        break;
                    }

                    throw $exception;
                }
            }
        }

        throw new RuntimeException($lastMessage);
    }

    /**
     * @param  array{mime: string, data: string}|null  $image
     */
    private function requestJson(
        string $base,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        ?array $image,
    ): array {
        $url = "{$base}/models/{$model}:generateContent";

        $parts = [];
        if ($image && filled($image['data'] ?? null) && filled($image['mime'] ?? null)) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => $image['mime'],
                    'data' => $image['data'],
                ],
            ];
        }
        $parts[] = ['text' => $userPrompt];

        try {
            $response = Http::timeout(self::PER_MODEL_TIMEOUT_SECONDS)
                ->connectTimeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
                ])
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $systemPrompt],
                        ],
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => $parts,
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json',
                    ],
                ])
                ->throw()
                ->json();
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Gemini request failed: timed out talking to '.$model.'.',
                0,
                $exception
            );
        } catch (RequestException $exception) {
            $body = $exception->response?->json();
            $message = data_get($body, 'error.message')
                ?: $exception->getMessage();

            throw new RuntimeException('Gemini request failed: '.$message, 0, $exception);
        }

        $text = data_get($response, 'candidates.0.content.parts.0.text');

        if (! is_string($text) || blank($text)) {
            throw new RuntimeException('Gemini returned an empty response from '.$model.'.');
        }

        return $this->decodeJsonPayload($text);
    }

    private function isHighDemand(string $message): bool
    {
        $haystack = Str::lower($message);

        return Str::contains($haystack, [
            'high demand',
            'try again later',
            'overloaded',
            'resource_exhausted',
            'unavailable',
            '503',
        ]);
    }

    private function shouldTryNextModel(string $message): bool
    {
        $haystack = Str::lower($message);

        return $this->isHighDemand($message)
            || Str::contains($haystack, [
                'no longer available',
                'not found',
                'is not found',
                'not supported',
                'quota',
                'timed out',
                'timeout',
                'cURL error 28',
                '429',
            ]);
    }

    private function decodeJsonPayload(string $text): array
    {
        $trimmed = trim($text);

        if (Str::startsWith($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
            $trimmed = trim($trimmed);
        }

        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini response was not valid JSON.');
        }

        return $decoded;
    }
}
