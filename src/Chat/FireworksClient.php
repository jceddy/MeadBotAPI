<?php

declare(strict_types=1);

namespace MeadBotApi\Chat;

use RuntimeException;

/**
 * A minimal client for Fireworks AI's OpenAI-compatible chat completions endpoint
 * (https://docs.fireworks.ai/api-reference/post-chatcompletions). Uses cURL directly rather than
 * an HTTP client library, matching this project's zero-runtime-deps style.
 */
final class FireworksClient
{
    private const ENDPOINT = 'https://api.fireworks.ai/inference/v1/chat/completions';

    private string $apiKey;
    private string $model;
    /** @var callable(string, array<int, string>, string): array{status: int, body: string} */
    private $transport;

    /**
     * @param callable(string, array<int, string>, string): array{status: int, body: string}|null $transport
     *   Injectable HTTP transport for testing — takes (url, headers, jsonBody), returns
     *   {status, body}. Defaults to a real cURL POST.
     */
    public function __construct(string $apiKey, string $model, ?callable $transport = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /**
     * chatCompletion(messages, tools) - send one chat-completions request with the given
     * conversation and available tools (OpenAI function-calling "tools" array), auto-selecting
     * whether to call a tool. Returns the decoded response body.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    public function chatCompletion(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode Fireworks request payload.');
        }

        $response = ($this->transport)(
            self::ENDPOINT,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            $encoded
        );

        $decoded = json_decode($response['body'], true);

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? self::errorMessage($decoded['error'] ?? null) : $response['body'];
            throw new RuntimeException("Fireworks request failed (HTTP {$response['status']}): {$message}");
        }

        return $decoded;
    }

    private static function errorMessage(mixed $error): string
    {
        if (is_array($error) && isset($error['message'])) {
            return (string) $error['message'];
        }
        if (is_string($error)) {
            return $error;
        }
        return json_encode($error) ?: 'unknown error';
    }

    /** @return array{status: int, body: string} */
    private static function curlTransport(string $url, array $headers, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Fireworks request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $responseBody];
    }
}
