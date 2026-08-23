<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use DateTimeImmutable;
use MeadBotApi\Chat\ModelCatalog;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase
{
    public function testDefaultKeyIsDs(): void
    {
        self::assertSame('ds', ModelCatalog::DEFAULT_KEY);
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
        self::assertSame('accounts/fireworks/models/deepseek-v4-flash-0731', ModelCatalog::fireworksModel('ds'));
    }

    /**
     * Pins `at` to just before the ds 2026-08-22T12:00Z rate change (rather than relying on
     * pricing()'s real-clock default) so this test doesn't start silently asserting different
     * numbers once that moment actually passes in real time.
     */
    public function testPricingMatchesEachModelsBaselinePublishedFireworksRates(): void
    {
        $usage = ['prompt_tokens' => 1_000_000, 'cached_prompt_tokens' => 0, 'completion_tokens' => 1_000_000];
        $beforeDsRateChange = new DateTimeImmutable('2026-08-22T11:59:59Z');

        // gpt-oss-120b: $0.15 input / $0.014 cached input / $0.60 output per 1M tokens. Only one
        // pricing tier exists for this model, so `at` doesn't affect the result.
        self::assertEqualsWithDelta(0.15 + 0.60, ModelCatalog::pricing('gpt', $beforeDsRateChange)->costUsd($usage), 1e-9);

        // DeepSeek-V4-Flash-0731, baseline tier (before 2026-08-22T12:00Z): $0.14 input / $0.028
        // cached input / $0.28 output per 1M tokens.
        self::assertEqualsWithDelta(0.14 + 0.28, ModelCatalog::pricing('ds', $beforeDsRateChange)->costUsd($usage), 1e-9);
    }

    /**
     * The 2026-08-22T12:00Z tier was pre-populated ahead of that moment specifically so it
     * starts being used automatically once reached, with no code change/deploy needed that day
     * -- this pins `at` to (and just after) that moment to prove it actually takes effect.
     */
    public function testPricingSwitchesDsToFireworksAnnouncedRatesOnAndAfterTheEffectiveDate(): void
    {
        $usage = ['prompt_tokens' => 1_000_000, 'cached_prompt_tokens' => 0, 'completion_tokens' => 1_000_000];

        // Fireworks' "DSV4 off-peak rate" (24hrs/day, despite the name): $0.22 input / $0.007
        // cached input / $0.66 output per 1M tokens.
        $expected = 0.22 + 0.66;
        self::assertEqualsWithDelta($expected, ModelCatalog::pricing('ds', new DateTimeImmutable('2026-08-22T12:00:00Z'))->costUsd($usage), 1e-9);
        self::assertEqualsWithDelta($expected, ModelCatalog::pricing('ds', new DateTimeImmutable('2026-09-01T00:00:00Z'))->costUsd($usage), 1e-9);
    }

    /**
     * selectTier() (private) is exercised directly against synthetic tiers -- rather than only
     * through pricing()'s real MODELS data -- so this can assert the effective-dating logic
     * itself (boundary inclusivity, out-of-order input, picking the *latest* applicable tier
     * among several) without depending on real future Fireworks pricing being populated yet.
     */
    private static function selectTier(array $tiers, DateTimeImmutable $at): array
    {
        $method = new \ReflectionMethod(ModelCatalog::class, 'selectTier');
        $method->setAccessible(true);
        return $method->invoke(null, $tiers, $at);
    }

    public function testSelectTierUsesTheBaselineBeforeADatedTiersEffectiveMoment(): void
    {
        $tiers = [
            ['effectiveAt' => null, 'inputPricePerMillion' => 1.0, 'cachedInputPricePerMillion' => 0.5, 'outputPricePerMillion' => 2.0],
            ['effectiveAt' => '2026-08-21T00:00:00+00:00', 'inputPricePerMillion' => 3.0, 'cachedInputPricePerMillion' => 1.5, 'outputPricePerMillion' => 6.0],
        ];

        $tier = self::selectTier($tiers, new DateTimeImmutable('2026-08-20T23:59:59Z'));
        self::assertSame(1.0, $tier['inputPricePerMillion']);
    }

    public function testSelectTierSwitchesToTheDatedTierAtTheExactEffectiveMomentInclusive(): void
    {
        $tiers = [
            ['effectiveAt' => null, 'inputPricePerMillion' => 1.0, 'cachedInputPricePerMillion' => 0.5, 'outputPricePerMillion' => 2.0],
            ['effectiveAt' => '2026-08-21T00:00:00+00:00', 'inputPricePerMillion' => 3.0, 'cachedInputPricePerMillion' => 1.5, 'outputPricePerMillion' => 6.0],
        ];

        $tier = self::selectTier($tiers, new DateTimeImmutable('2026-08-21T00:00:00Z'));
        self::assertSame(3.0, $tier['inputPricePerMillion']);

        $tier = self::selectTier($tiers, new DateTimeImmutable('2026-08-22T00:00:00Z'));
        self::assertSame(3.0, $tier['inputPricePerMillion']);
    }

    public function testSelectTierPicksTheLatestApplicableTierAmongSeveralRegardlessOfInputOrder(): void
    {
        // Deliberately unsorted, so this also proves selectTier() sorts internally rather than
        // trusting the caller's ordering.
        $tiers = [
            ['effectiveAt' => '2026-08-21T00:00:00+00:00', 'inputPricePerMillion' => 3.0, 'cachedInputPricePerMillion' => 1.5, 'outputPricePerMillion' => 6.0],
            ['effectiveAt' => null, 'inputPricePerMillion' => 1.0, 'cachedInputPricePerMillion' => 0.5, 'outputPricePerMillion' => 2.0],
            ['effectiveAt' => '2026-01-01T00:00:00+00:00', 'inputPricePerMillion' => 2.0, 'cachedInputPricePerMillion' => 1.0, 'outputPricePerMillion' => 4.0],
        ];

        // Between the 2026-01-01 tier and the 2026-08-21 tier -- should land on the former, not
        // fall back to the baseline nor jump ahead to the not-yet-effective later tier.
        $tier = self::selectTier($tiers, new DateTimeImmutable('2026-03-01T00:00:00Z'));
        self::assertSame(2.0, $tier['inputPricePerMillion']);
    }
}
