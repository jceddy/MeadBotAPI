<?php

declare(strict_types=1);

namespace MeadBotApi\Chat;

/**
 * The Fireworks-hosted models MeadBot's !chat command can choose between via its --model/-m flag
 * (see /api/v1/chat's optional `model` request field, which takes one of these keys). Pricing is
 * a point-in-time snapshot of Fireworks' published per-1M-token rates for each model -- update it
 * by hand if Fireworks changes them, nothing here looks them up automatically.
 */
final class ModelCatalog
{
    public const DEFAULT_KEY = 'gpt';

    private const MODELS = [
        'gpt' => [
            'fireworksModel' => 'accounts/fireworks/models/gpt-oss-120b',
            'inputPricePerMillion' => 0.15,
            'cachedInputPricePerMillion' => 0.014,
            'outputPricePerMillion' => 0.60,
        ],
        'ds' => [
            'fireworksModel' => 'accounts/fireworks/models/deepseek-v4-flash',
            'inputPricePerMillion' => 0.14,
            'cachedInputPricePerMillion' => 0.028,
            'outputPricePerMillion' => 0.28,
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

    public static function pricing(string $key): CostCalculator
    {
        $model = self::MODELS[$key];
        return new CostCalculator($model['inputPricePerMillion'], $model['cachedInputPricePerMillion'], $model['outputPricePerMillion']);
    }
}
