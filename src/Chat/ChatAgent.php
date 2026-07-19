<?php

declare(strict_types=1);

namespace MeadBotApi\Chat;

use RuntimeException;
use Throwable;

/**
 * Runs a chat-completions tool-calling loop against a FireworksClient: sends the conversation
 * plus Tools::definitions(), and whenever the model responds with tool_calls, executes them via
 * Tools::call() and feeds the results back as role=tool messages, repeating until the model
 * replies with plain content (or the iteration cap is hit).
 */
final class ChatAgent
{
    private const MAX_TOOL_ITERATIONS = 6;

    private FireworksClient $client;

    public function __construct(FireworksClient $client)
    {
        $this->client = $client;
    }

    /**
     * run(messages) - given a conversation so far (OpenAI-style {role, content} messages, e.g.
     * starting with a system message and the user's latest message), runs the tool-calling loop
     * and returns the final assistant reply plus the full updated message list (including any
     * assistant tool-call turns and tool-result turns), so the caller can pass it back in on the
     * next request to continue the conversation.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{reply: string, messages: array<int, array<string, mixed>>}
     */
    public function run(array $messages): array
    {
        $messages = array_values($messages);
        $tools = Tools::definitions();

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $response = $this->client->chatCompletion($messages, $tools);
            $message = $response['choices'][0]['message'] ?? null;
            if (!is_array($message)) {
                throw new RuntimeException('Fireworks response had no message.');
            }

            $messages[] = $message;
            $toolCalls = $message['tool_calls'] ?? [];

            if (!is_array($toolCalls) || $toolCalls === []) {
                return ['reply' => (string) ($message['content'] ?? ''), 'messages' => $messages];
            }

            foreach ($toolCalls as $toolCall) {
                $messages[] = $this->executeToolCall($toolCall);
            }
        }

        throw new RuntimeException('Exceeded maximum tool-calling iterations (' . self::MAX_TOOL_ITERATIONS . ').');
    }

    /**
     * @param array<string, mixed> $toolCall
     * @return array<string, mixed>
     */
    private function executeToolCall(array $toolCall): array
    {
        $name = (string) ($toolCall['function']['name'] ?? '');
        $rawArguments = $toolCall['function']['arguments'] ?? '{}';
        $arguments = json_decode((string) $rawArguments, true);
        if (!is_array($arguments)) {
            $arguments = [];
        }

        try {
            $result = Tools::call($name, $arguments);
        } catch (Throwable $e) {
            $result = ['error' => true, 'errorMessage' => $e->getMessage()];
        }

        return [
            'role' => 'tool',
            'tool_call_id' => (string) ($toolCall['id'] ?? ''),
            'content' => json_encode($result, JSON_UNESCAPED_SLASHES) ?: '{"error":true,"errorMessage":"failed to encode tool result"}',
        ];
    }
}
