<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use MeadBotApi\Chat\ChatAgent;
use MeadBotApi\Chat\FireworksClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChatAgentTest extends TestCase
{
    /**
     * @param array<int, array{status: int, body: string}> $responses
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

    public function testReturnsPlainReplyWhenNoToolCallIsMade(): void
    {
        $agent = $this->agentWithCannedResponses([
            [
                'status' => 200,
                'body' => json_encode([
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Honey is about 79.6% sugar by weight.']]],
                ]),
            ],
        ]);

        $result = $agent->run([['role' => 'user', 'content' => "What's honey's sugar content?"]]);

        self::assertSame('Honey is about 79.6% sugar by weight.', $result['reply']);
        self::assertCount(2, $result['messages']); // original user message + assistant reply
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
            ['status' => 200, 'body' => json_encode(['choices' => [['message' => $toolCallMessage]]])],
            ['status' => 200, 'body' => json_encode(['choices' => [['message' => $finalMessage]]])],
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
            ['status' => 200, 'body' => json_encode(['choices' => [['message' => $toolCallMessage]]])],
            ['status' => 200, 'body' => json_encode(['choices' => [['message' => $finalMessage]]])],
        ]);

        $result = $agent->run([['role' => 'user', 'content' => 'look up a sugar source']]);

        $toolResult = json_decode($result['messages'][2]['content'], true);
        self::assertTrue($toolResult['error']);
        self::assertSame('I need a sugar source name to look that up.', $result['reply']);
    }

    public function testThrowsAfterExceedingMaxToolIterations(): void
    {
        $toolCallMessage = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'list_volume_units', 'arguments' => '{}'],
                ],
            ],
        ];

        // Always responds with another tool call — the agent must eventually give up rather
        // than loop forever.
        $agent = $this->agentWithCannedResponses([
            ['status' => 200, 'body' => json_encode(['choices' => [['message' => $toolCallMessage]]])],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/maximum tool-calling iterations/');
        $agent->run([['role' => 'user', 'content' => 'hi']]);
    }
}
