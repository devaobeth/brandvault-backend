<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookService
{
    public const EVENT_AI_TAG_SAVED = 'ai.tag_suggestion.saved';

    public const EVENT_ASSET_RESTORED = 'asset.restored';

    public const EVENT_BRAND_UPDATED = 'brand.updated';

    /**
     * Fire-and-forget POST to n8n (or any webhook URL). Never throws to callers.
     */
    public function dispatch(
        string $event,
        ?int $assetId,
        ?int $brandId,
        string $userEmail,
    ): void {
        $url = trim((string) config('services.n8n.webhook_url', ''));

        if ($url === '') {
            return;
        }

        $payload = [
            'event' => $event,
            'asset_id' => $assetId,
            'brand_id' => $brandId,
            'user_email' => $userEmail,
            'timestamp' => now()->toIso8601String(),
        ];

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        try {
            $response = Http::timeout(5)
                ->withHeaders($headers)
                ->post($url, $payload);

            if ($response->failed()) {
                Log::warning('n8n webhook request failed', [
                    'event' => $event,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('n8n webhook request error', [
                'event' => $event,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * True when the request looks like an AI suggestion being persisted.
     *
     * @param  array<string, mixed>  $data
     */
    public function looksLikeAiTagSave(array $data): bool
    {
        if (! array_key_exists('tags', $data)) {
            return false;
        }

        return array_key_exists('description', $data)
            || array_key_exists('usage_suggestion', $data);
    }
}
