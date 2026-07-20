<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use MeadBotApi\Chat\ChatAgent;
use MeadBotApi\Chat\ChatUsageException;
use MeadBotApi\Chat\FireworksClient;
use PHPUnit\Framework\TestCase;

final class ChatAgentTest extends TestCase
{
    private const EMPTY_USAGE = ['prompt_tokens' => 0, 'cached_prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];

    /**
     * @param array<int, array{status: int, body: string, headers?: array<string, string>}> $responses
     */
    private function agentWithCannedResponses(array $responses): ChatAgent
    {
        $calls = 0;
        $transport = function () use (&$calls, $responses) {
            $response = $responses[$calls] ?? end($responses);
            $calls++;
            return $response;
        };

        return new ChatAgent(new FireworksClient('fake-key', 'fake-model', $transport));
    }

    /** @param array<string, mixed> $message */
    private static function response(array $message, array $usageExtra = []): array
    {
        $usage = array_merge(['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15], $usageExtra);
        return ['status' => 200, 'body' => json_encode(['choices' => [['message' => $message]], 'usage' => $usage])];
    }

    /** @param array<string, mixed> $message */
    private static function responseWithHeaders(array $message, array $usage, array $headers): array
    {
        return [
            'status' => 200,
            'body' => json_encode(['choices' => [['message' => $message]], 'usage' => $usage]),
            'headers' => $headers,
        ];
    }

    public function testReturnsPlainReplyAndUsageWhenNoToolCallIsMade(): void
    {
        $agent = $this->agentWithCannedResponses([
            self::response(['role' => 'assistant', 'content' => 'Honey is about 79.6% sugar by weight.'], ['prompt_tokens' => 42, 'completion_tokens' => 11, 'total_tokens' => 53]),
        ]);

        $result = $agent->run([['role' => 'user', 'content' => "What's honey's sugar content?"]]);

        self::assertSame('Honey is about 79.6% sugar by weight.', $result['reply']);
        self::assertCount(2, $result['messages']); // original user message + assistant reply
        self::assertSame(['prompt_tokens' => 42, 'cached_prompt_tokens' => 0, 'completion_tokens' => 11, 'total_tokens' => 53], $result['usage']);
    }

    public function testExecutesAToolCallAndFeedsResultBackForAFinalReply(): void
    {
        $toolCallMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'get_sugar_source', 'arguments' => json_encode(['name' => 'honey'])],
                ],
            ],
        ];
        $finalMessage = ['role' => 'assistant', 'content' => "Honey's sugar content is 79.6%."];

        $agent = $this->agentWithCannedResponses([
            self::response($toolCallMessage),
            self::response($finalMessage),
        ]);

        $result = $agent->run([['role' => 'user', 'content' => "What's honey's sugar content?"]]);

        self::assertSame("Honey's sugar content is 79.6%.", $result['reply']);
        // user message, assistant tool-call message, tool-result message, final assistant message
        self::assertCount(4, $result['messages']);
        self::assertSame('tool', $result['messages'][2]['role']);
        self::assertSame('call_1', $result['messages'][2]['tool_call_id']);

        $toolResult = json_decode($result['messages'][2]['content'], true);
        self::assertFalse($toolResult['error']);
        self::assertSame(0, $toolResult['unitId']);
        self::assertSame(79.6, $toolResult['sugarSource']['percent']);
    }

    public function testAccumulatesUsageAcrossEveryToolCallRoundIncludingCachedTokens(): void
    {
        $toolCallMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_volume_units', 'arguments' => '{}']],
            ],
        ];
        $finalMessage = ['role' => 'assistant', 'content' => 'done'];

        $agent = $this->agentWithCannedResponses([
            self::responseWithHeaders(
                $toolCallMessage,
                ['prompt_tokens' => 1000, 'completion_tokens' => 20, 'total_tokens' => 1020],
                ['fireworks-cached-prompt-tokens' => '600']
            ),
            self::responseWithHeaders(
                $finalMessage,
                ['prompt_tokens' => 1100, 'completion_tokens' => 15, 'total_tokens' => 1115],
                ['fireworks-cached-prompt-tokens' => '1000']
            ),
        ]);

        $result = $agent->run([['role' => 'user', 'content' => 'list volume units']]);

        // one user-facing turn triggered two Fireworks calls — usage must be the sum of both,
        // not just the last call's numbers.
        self::assertSame([
            'prompt_tokens' => 2100,
            'cached_prompt_tokens' => 1600,
            'completion_tokens' => 35,
            'total_tokens' => 2135,
        ], $result['usage']);
    }

    public function testFeedsAnInvalidToolCallErrorBackToTheModelInsteadOfThrowing(): void
    {
        $toolCallMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'get_sugar_source', 'arguments' => json_encode([])],
                ],
            ],
        ];
        $finalMessage = ['role' => 'assistant', 'content' => 'I need a sugar source name to look that up.'];

        $agent = $this->agentWithCannedResponses([
            self::response($toolCallMessage),
            self::response($finalMessage),
        ]);

        $result = $agent->run([['role' => 'user', 'content' => 'look up a sugar source']]);

        $toolResult = json_decode($result['messages'][2]['content'], true);
        self::assertTrue($toolResult['error']);
        self::assertSame('I need a sugar source name to look that up.', $result['reply']);
    }

    public function testThrowsChatUsageExceptionCarryingAccumulatedUsageAfterExceedingMaxIterations(): void
    {
        $toolCallMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_volume_units', 'arguments' => '{}']],
            ],
        ];

        // Always responds with another tool call — the agent must eventually give up rather
        // than loop forever. Every one of those calls was still billed by Fireworks, so the
        // thrown exception must carry the total.
        $agent = $this->agentWithCannedResponses([
            self::response($toolCallMessage, ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12]),
        ]);

        try {
            $agent->run([['role' => 'user', 'content' => 'hi']]);
            self::fail('expected ChatUsageException');
        } catch (ChatUsageException $e) {
            self::assertMatchesRegularExpression('/maximum tool-calling iterations/', $e->getMessage());
            // 6 iterations of the same 10-prompt/2-completion response.
            self::assertSame(60, $e->usage['prompt_tokens']);
            self::assertSame(12, $e->usage['completion_tokens']);
        }
    }

    public function testThrowsChatUsageExceptionCarryingPartialUsageWhenALaterCallFails(): void
    {
        $toolCallMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_volume_units', 'arguments' => '{}']],
            ],
        ];

        $agent = $this->agentWithCannedResponses([
            self::response($toolCallMessage, ['prompt_tokens' => 30, 'completion_tokens' => 5, 'total_tokens' => 35]),
            ['status' => 500, 'body' => json_encode(['error' => ['message' => 'internal error']])],
        ]);

        try {
            $agent->run([['role' => 'user', 'content' => 'hi']]);
            self::fail('expected ChatUsageException');
        } catch (ChatUsageException $e) {
            self::assertMatchesRegularExpression('/HTTP 500/', $e->getMessage());
            // the first (successful, billed) call's usage must not be lost just because the
            // second call failed.
            self::assertSame(['prompt_tokens' => 30, 'cached_prompt_tokens' => 0, 'completion_tokens' => 5, 'total_tokens' => 35], $e->usage);
        }
    }

    public function testThrowsChatUsageExceptionWithEmptyUsageWhenTheVeryFirstCallFails(): void
    {
        $agent = $this->agentWithCannedResponses([
            ['status' => 401, 'body' => json_encode(['error' => ['message' => 'bad key']])],
        ]);

        try {
            $agent->run([['role' => 'user', 'content' => 'hi']]);
            self::fail('expected ChatUsageException');
        } catch (ChatUsageException $e) {
            self::assertSame(self::EMPTY_USAGE, $e->usage);
        }
    }
}
