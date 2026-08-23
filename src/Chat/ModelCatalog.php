<?php

declare(strict_types=1);

namespace MeadBotApi\Chat;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The Fireworks-hosted models MeadBot's !chat command can choose between via its --model/-m flag
 * (see /api/v1/chat's optional `model` request field, which takes one of these keys).
 *
 * Each model's pricing is a list of tiers, letting a Fireworks-announced rate change be
 * pre-populated ahead of time and picked up automatically once it takes effect, rather than
 * needing a same-day deploy: pricing() returns whichever tier has the latest `effectiveAt` that
 * isn't in the future (comparing against UTC "now" by default). Exactly one tier per model should
 * have `effectiveAt => null` -- the baseline rate applied before any dated tier's time comes, and
 * whenever the list would otherwise be empty. Tiers don't need to be listed in chronological
 * order -- pricing() sorts them itself. Update by hand -- nothing here looks Fireworks' published
 * rates up automatically.
 */
final class ModelCatalog
{
    public const DEFAULT_KEY = 'ds';

    private const MODELS = [
        'gpt' => [
            'fireworksModel' => 'accounts/fireworks/models/gpt-oss-120b',
            'pricingTiers' => [
                [
                    'effectiveAt' => null,
                    'inputPricePerMillion' => 0.15,
                    'cachedInputPricePerMillion' => 0.014,
                    'outputPricePerMillion' => 0.60,
                ],
            ],
        ],
        'ds' => [
            // Fireworks retired the unversioned deepseek-v4-flash id in favor of this official
            // 0731 release (requests to the old id now 404 with "Model not found, inaccessible,
            // and/or not deployed").
            'fireworksModel' => 'accounts/fireworks/models/deepseek-v4-flash-0731',
            'pricingTiers' => [
                [
                    'effectiveAt' => null,
                    'inputPricePerMillion' => 0.14,
                    'cachedInputPricePerMillion' => 0.028,
                    'outputPricePerMillion' => 0.28,
                ],
                // Fireworks' "DSV4 off-peak rate" -- a revision of the rate change below,
                // announced at half the originally-announced numbers after their performance
                // work on this model landed ahead of schedule. Applies 24hrs/day despite the
                // "off-peak" name (there's no separate peak-hours tier).
                [
                    'effectiveAt' => '2026-08-22T12:00:00+00:00',
                    'inputPricePerMillion' => 0.22,
                    'cachedInputPricePerMillion' => 0.007,
                    'outputPricePerMillion' => 0.66,
                ],
            ],
        ],
    ];

    public static function has(string $key): bool
    {
        return isset(self::MODELS[$key]);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODELS);
    }

    public static function fireworksModel(string $key): string
    {
        return self::MODELS[$key]['fireworksModel'];
    }

    /**
     * pricing(key, at) - the CostCalculator for whichever of key's pricing tiers is in effect at
     * `at` (defaults to now, UTC). Pass an explicit `at` to price a specific past/future moment.
     */
    public static function pricing(string $key, ?DateTimeImmutable $at = null): CostCalculator
    {
        $at ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tier = self::selectTier(self::MODELS[$key]['pricingTiers'], $at);

        return new CostCalculator(
            $tier['inputPricePerMillion'],
            $tier['cachedInputPricePerMillion'],
            $tier['outputPricePerMillion']
        );
    }

    /** @param list<array{effectiveAt: ?string}> $tiers */
    private static function selectTier(array $tiers, DateTimeImmutable $at): array
    {
        usort($tiers, fn (array $a, array $b) => self::tierTimestamp($a) <=> self::tierTimestamp($b));

        $applicable = $tiers[0];
        $atTimestamp = $at->getTimestamp();
        foreach ($tiers as $tier) {
            if (self::tierTimestamp($tier) <= $atTimestamp) {
                $applicable = $tier;
            }
        }

        return $applicable;
    }

    /** A tier with no effectiveAt is the baseline -- always in effect, so it sorts first. */
    private static function tierTimestamp(array $tier): int
    {
        return $tier['effectiveAt'] === null ? PHP_INT_MIN : (new DateTimeImmutable($tier['effectiveAt']))->getTimestamp();
    }
}
