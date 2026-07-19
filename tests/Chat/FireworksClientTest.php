<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use MeadBotApi\Chat\FireworksClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FireworksClientTest extends TestCase
{
    public function testSendsModelMessagesAndToolsAndReturnsTheDecodedResponse(): void
    {
        $capturedUrl = null;
        $capturedHeaders = null;
        $capturedBody = null;

        $transport = function (string $url, array $headers, string $body) use (&$capturedUrl, &$capturedHeaders, &$capturedBody) {
            $capturedUrl = $url;
            $capturedHeaders = $headers;
            $capturedBody = $body;
            return ['status' => 200, 'body' => json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => 'hi']]]])];
        };

        $client = new FireworksClient('secret-key', 'accounts/fireworks/models/gpt-oss-120b', $transport);
        $messages = [['role' => 'user', 'content' => 'hello']];
        $tools = [['type' => 'function', 'function' => ['name' => 'noop']]];

        $result = $client->chatCompletion($messages, $tools);

        self::assertSame('hi', $result['choices'][0]['message']['content']);
        self::assertSame('https://api.fireworks.ai/inference/v1/chat/completions', $capturedUrl);
        self::assertContains('Authorization: Bearer secret-key', $capturedHeaders);

        $payload = json_decode($capturedBody, true);
        self::assertSame('accounts/fireworks/models/gpt-oss-120b', $payload['model']);
        self::assertSame($messages, $payload['messages']);
        self::assertSame('auto', $payload['tool_choice']);
    }

    public function testMergesCachedPromptTokensFromTheResponseHeaderIntoUsage(): void
    {
        $transport = fn () => [
            'status' => 200,
            'body' => json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120],
            ]),
            'headers' => ['fireworks-prompt-tokens' => '100', 'fireworks-cached-prompt-tokens' => '80'],
        ];
        $client = new FireworksClient('key', 'model', $transport);

        $result = $client->chatCompletion([], []);

        self::assertSame(100, $result['usage']['prompt_tokens']);
        self::assertSame(20, $result['usage']['completion_tokens']);
        self::assertSame(80, $result['usage']['cached_prompt_tokens']);
    }

    public function testDefaultsCachedPromptTokensToZeroWhenTheHeaderIsAbsent(): void
    {
        // e.g. an older-style stub transport that only returns {status, body} (no headers key
        // at all), or a real response where nothing in the prompt was cache-eligible.
        $transport = fn () => [
            'status' => 200,
            'body' => json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'hi']]],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10, 'total_tokens' => 60],
            ]),
        ];
        $client = new FireworksClient('key', 'model', $transport);

        $result = $client->chatCompletion([], []);

        self::assertSame(0, $result['usage']['cached_prompt_tokens']);
    }

    public function testThrowsWithTheApiErrorMessageOnANonSuccessStatus(): void
    {
        $transport = fn () => ['status' => 401, 'body' => json_encode(['error' => ['message' => 'Invalid API key']])];
        $client = new FireworksClient('bad-key', 'some-model', $transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401.*Invalid API key/s');
        $client->chatCompletion([], []);
    }

    public function testThrowsOnAMalformedResponseBody(): void
    {
        $transport = fn () => ['status' => 200, 'body' => 'not json'];
        $client = new FireworksClient('key', 'model', $transport);

        $this->expectException(RuntimeException::class);
        $client->chatCompletion([], []);
    }
}
