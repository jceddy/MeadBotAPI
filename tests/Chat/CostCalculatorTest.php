<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use MeadBotApi\Chat\CostCalculator;
use PHPUnit\Framework\TestCase;

final class CostCalculatorTest extends TestCase
{
    // gpt-oss-120b's Fireworks pricing: $0.15 / $0.01 / $0.60 per 1M tokens (input/cached input/output).
    private function calculator(): CostCalculator
    {
        return new CostCalculator(0.15, 0.01, 0.60);
    }

    public function testChargesTheRegularInputRateWhenNothingWasCached(): void
    {
        $cost = $this->calculator()->costUsd(['prompt_tokens' => 1_000_000, 'cached_prompt_tokens' => 0, 'completion_tokens' => 0]);
        self::assertEqualsWithDelta(0.15, $cost, 1e-9);
    }

    public function testChargesTheCachedRateForCachedTokensAndRegularRateForTheRest(): void
    {
        // 600k cached, 400k uncached.
        $cost = $this->calculator()->costUsd(['prompt_tokens' => 1_000_000, 'cached_prompt_tokens' => 600_000, 'completion_tokens' => 0]);
        $expected = (400_000 / 1_000_000) * 0.15 + (600_000 / 1_000_000) * 0.01;
        self::assertEqualsWithDelta($expected, $cost, 1e-9);
    }

    public function testChargesTheOutputRateForCompletionTokens(): void
    {
        $cost = $this->calculator()->costUsd(['prompt_tokens' => 0, 'cached_prompt_tokens' => 0, 'completion_tokens' => 1_000_000]);
        self::assertEqualsWithDelta(0.60, $cost, 1e-9);
    }

    public function testCombinesAllThreeRates(): void
    {
        $cost = $this->calculator()->costUsd(['prompt_tokens' => 1000, 'cached_prompt_tokens' => 400, 'completion_tokens' => 200]);
        $expected = (600 / 1_000_000) * 0.15 + (400 / 1_000_000) * 0.01 + (200 / 1_000_000) * 0.60;
        self::assertEqualsWithDelta($expected, $cost, 1e-9);
    }

    public function testClampsCachedTokensToPromptTokensRatherThanGoingNegative(): void
    {
        // Defensive: cached_prompt_tokens should never exceed prompt_tokens, but if a malformed
        // usage payload somehow reports more cached than total, don't let uncachedTokens go
        // negative and produce a nonsensical (negative) cost.
        $cost = $this->calculator()->costUsd(['prompt_tokens' => 100, 'cached_prompt_tokens' => 500, 'completion_tokens' => 0]);
        $expected = (100 / 1_000_000) * 0.01;
        self::assertEqualsWithDelta($expected, $cost, 1e-9);
    }

    public function testMissingKeysDefaultToZero(): void
    {
        self::assertSame(0.0, $this->calculator()->costUsd([]));
    }
}
