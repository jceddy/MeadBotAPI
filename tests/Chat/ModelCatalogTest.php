<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use MeadBotApi\Chat\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function testDefaultKeyIsGpt(): void
    {
        self::assertSame('gpt', ModelCatalog::DEFAULT_KEY);
    }

    public function testHasRecognizesBothBuiltInKeysButNotAnUnknownOne(): void
    {
        self::assertTrue(ModelCatalog::has('gpt'));
        self::assertTrue(ModelCatalog::has('ds'));
        self::assertFalse(ModelCatalog::has('unknown'));
    }

    public function testKeysListsBothBuiltInModels(): void
    {
        self::assertSame(['gpt', 'ds'], ModelCatalog::keys());
    }

    public function testFireworksModelMapsEachKeyToItsAccountModelId(): void
    {
        self::assertSame('accounts/fireworks/models/gpt-oss-120b', ModelCatalog::fireworksModel('gpt'));
        self::assertSame('accounts/fireworks/models/deepseek-v4-flash', ModelCatalog::fireworksModel('ds'));
    }

    public function testPricingMatchesEachModelsPublishedFireworksRates(): void
    {
        $usage = ['prompt_tokens' => 1_000_000, 'cached_prompt_tokens' => 0, 'completion_tokens' => 1_000_000];

        // gpt-oss-120b: $0.15 input / $0.014 cached input / $0.60 output per 1M tokens.
        self::assertEqualsWithDelta(0.15 + 0.60, ModelCatalog::pricing('gpt')->costUsd($usage), 1e-9);

        // DeepSeek-V4-Flash: $0.14 input / $0.028 cached input / $0.28 output per 1M tokens.
        self::assertEqualsWithDelta(0.14 + 0.28, ModelCatalog::pricing('ds')->costUsd($usage), 1e-9);
    }
}
