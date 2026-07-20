<?php

declare(strict_types=1);

namespace MeadBotApi\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use MeadBotApi\Calculator\BatchCalculator;
use MeadBotApi\Calculator\BlendCalculator;
use MeadBotApi\Calculator\CalculatorApi;
use MeadBotApi\Calculator\Constants;
use MeadBotApi\Calculator\GravityCalculator;
use MeadBotApi\Calculator\NutrientCalculator;

/**
 * The MeadBotAPI calculation/lookup operations, one method per REST endpoint under /api/v1 (named
 * to match that endpoint's operationId in public/docs/openapi.yaml). Each takes the merged
 * path/body/query params for its endpoint — or no params, for the few endpoints that take none —
 * and returns the same {error, ...} envelope the endpoint responds with.
 *
 * public/index.php calls these as its REST route handlers, and Chat\Tools calls them as the
 * tool-calling handlers exposed to the chat agent, so both surfaces share one implementation of
 * "parse/default these params, then call the calculator" instead of maintaining it twice.
 */
final class Operations
{
    // ---- shared param-parsing helpers ----

    /** Fetch a required param, throwing a 400-mappable exception if it's missing. */
    private static function requireParam(array $params, string $name): mixed
    {
        if (!array_key_exists($name, $params) || $params[$name] === null || $params[$name] === '') {
            throw new InvalidArgumentException("Missing required parameter: {$name}");
        }
        return $params[$name];
    }

    private static function optionalParam(array $params, string $name, mixed $default = null): mixed
    {
        if (!array_key_exists($name, $params) || $params[$name] === null || $params[$name] === '') {
            return $default;
        }
        return $params[$name];
    }

    private static function requireNumeric(array $params, string $name): float
    {
        $value = self::requireParam($params, $name);
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Parameter '{$name}' must be numeric.");
        }
        return (float) $value;
    }

    private static function optionalNumeric(array $params, string $name): ?float
    {
        $value = self::optionalParam($params, $name);
        if ($value === null) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Parameter '{$name}' must be numeric.");
        }
        return (float) $value;
    }

    private static function requireDate(array $params, string $name): DateTimeImmutable
    {
        $value = self::requireParam($params, $name);
        $date = date_create_immutable((string) $value);
        if ($date === false) {
            throw new InvalidArgumentException("Parameter '{$name}' must be a parseable date/time string.");
        }
        return $date;
    }

    /** Wrap a nullable-int lookup result in the same {error, ...} envelope the calculation methods use. */
    private static function lookupResult(?int $id, string $notFoundMessage, array $extra = []): array
    {
        if ($id === null) {
            return [
                'error' => true,
                'errorMessage' => $notFoundMessage,
                'errorType' => Constants::ERROR_INVALID_ARGUMENTS,
                'errorTypeLabel' => 'invalid arguments',
            ];
        }
        return array_merge(['error' => false, 'unitId' => $id], $extra);
    }

    private static function parseGravityUnits(?string $value): int
    {
        return match ($value) {
            null, 'sg' => Constants::GRAVITY_UNIT_SG,
            'brix' => Constants::GRAVITY_UNIT_BRIX,
            'baume' => Constants::GRAVITY_UNIT_BAUME,
            default => throw new InvalidArgumentException("Unknown gravity units: {$value}"),
        };
    }

    private static function parseAbvUnits(?string $value): int
    {
        return match ($value) {
            null, 'abv' => Constants::ABV_UNIT_ABV,
            'abw' => Constants::ABV_UNIT_ABW,
            default => throw new InvalidArgumentException("Unknown abv units: {$value}"),
        };
    }

    private static function parseBlendField(string $value): int
    {
        return match ($value) {
            'value1' => Constants::BLEND_FIELD_VALUE1,
            'value2' => Constants::BLEND_FIELD_VALUE2,
            'blended_value' => Constants::BLEND_FIELD_BLENDED_VALUE,
            'volume1' => Constants::BLEND_FIELD_VOLUME1,
            'volume2' => Constants::BLEND_FIELD_VOLUME2,
            'total_volume' => Constants::BLEND_FIELD_TOTAL_VOLUME,
            default => throw new InvalidArgumentException("Unknown fieldToCalculate: {$value}"),
        };
    }

    private static function parseUnits(?string $value): int
    {
        return match ($value) {
            null, 'us' => Constants::UNITS_US,
            'metric' => Constants::UNITS_METRIC,
            'imperial' => Constants::UNITS_IMPERIAL,
            default => throw new InvalidArgumentException("Unknown units: {$value}"),
        };
    }

    private static function parseYanRequirement(?string $value): int
    {
        return match ($value) {
            'very_low' => Constants::YAN_REQUIREMENT_VERY_LOW,
            null, 'medium' => Constants::YAN_REQUIREMENT_MEDIUM,
            'low' => Constants::YAN_REQUIREMENT_LOW,
            'high' => Constants::YAN_REQUIREMENT_HIGH,
            'kveik' => Constants::YAN_REQUIREMENT_KVEIK,
            default => throw new InvalidArgumentException("Unknown yanRequirement: {$value}"),
        };
    }

    private static function parseNutrientRegimen(?string $value): int
    {
        return match ($value) {
            'tosna' => Constants::NUTRIENT_REGIMEN_TOSNA,
            'k_dap' => Constants::NUTRIENT_REGIMEN_K_DAP,
            null, 'blount_elliott', 'blount_elliot' => Constants::NUTRIENT_REGIMEN_BLOUNT_ELLIOTT,
            'tosna_k' => Constants::NUTRIENT_REGIMEN_TOSNA_K,
            'o_k' => Constants::NUTRIENT_REGIMEN_O_K,
            'advanced' => Constants::NUTRIENT_REGIMEN_ADVANCED,
            default => throw new InvalidArgumentException("Unknown nutrientRegimen: {$value}"),
        };
    }

    private static function parseVolumeUnits(string $value): int
    {
        $id = CalculatorApi::getVolumeUnit($value);
        if ($id === null) {
            throw new InvalidArgumentException("Unknown volume units: {$value}");
        }
        return $id;
    }

    private static function parseTemperatureUnits(string $value): int
    {
        return match ($value) {
            'c', 'celsius', 'celcius' => Constants::TEMPERATURE_UNIT_CELSIUS,
            'f', 'fahrenheit' => Constants::TEMPERATURE_UNIT_FAHRENHEIT,
            default => throw new InvalidArgumentException("Unknown temperature units: {$value}"),
        };
    }

    /**
     * Validates and normalizes an optional array of additional-sugar specifications for
     * calculate-mead into the shape BatchCalculator::calculateMead expects. Each entry's `type`
     * and `quantityUnits` are resolved via the same lookups as the sugar-sources/honey-units
     * endpoints; `sugarContent`/`yanMultiplier` default to that sugar source's known values when
     * omitted (unlike the MeadBot command, which always defaults sugar_content to honey's
     * regardless of type unless explicitly overridden — a quirk not worth reproducing on a fresh
     * endpoint).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function parseAdditionalSugars(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('additionalSugars must be an array.');
        }

        $sugars = [];
        foreach (array_values($value) as $i => $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException("additionalSugars[{$i}] must be an object.");
            }
            $typeName = (string) self::requireParam($entry, 'type');
            $type = CalculatorApi::getSugarSourceIdentifier($typeName);
            if ($type === null) {
                throw new InvalidArgumentException("additionalSugars[{$i}]: unknown sugar type: {$typeName}");
            }
            $quantityUnitsName = (string) self::requireParam($entry, 'quantityUnits');
            $quantityUnits = CalculatorApi::getHoneyUnit($quantityUnitsName);
            if ($quantityUnits === null) {
                throw new InvalidArgumentException("additionalSugars[{$i}]: unknown quantityUnits: {$quantityUnitsName}");
            }

            $quantityAmount = self::optionalNumeric($entry, 'quantityAmount');
            $sugarContent = self::optionalNumeric($entry, 'sugarContent') ?? Constants::SUGAR_SOURCE_INFO[$type]['percent'];
            $yanMultiplier = self::optionalNumeric($entry, 'yanMultiplier') ?? Constants::SUGAR_SOURCE_INFO[$type]['yan'];
            $additive = filter_var(self::optionalParam($entry, 'additive', false), FILTER_VALIDATE_BOOLEAN);

            if ($additive && $quantityAmount === null) {
                throw new InvalidArgumentException("additionalSugars[{$i}]: quantityAmount is required when additive is true.");
            }

            $sugars[] = [
                'type' => $type,
                'quantity_amount' => $quantityAmount ?? 0.0,
                'quantity_amount_specified' => $quantityAmount !== null,
                'quantity_units' => $quantityUnits,
                'sugar_content' => $sugarContent,
                'yan_multiplier' => $yanMultiplier,
                'additive' => $additive,
            ];
        }
        return $sugars;
    }

    /**
     * Validates an optional SNA schedule: each element must be the string "pitch" (only as the
     * first element), the string "break", or a number in [1, 500].
     *
     * @return array<int, int|string>|null
     */
    private static function parseSnaSchedule(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('snaScheduleOverride must be an array.');
        }

        $schedule = [];
        foreach (array_values($value) as $i => $part) {
            if ($part === 'pitch') {
                if ($i !== 0) {
                    throw new InvalidArgumentException('"pitch" can only be the first item in snaScheduleOverride.');
                }
                $schedule[] = 'pitch';
            } elseif ($part === 'break') {
                $schedule[] = 'break';
            } elseif (is_numeric($part)) {
                $num = (int) $part;
                if ($num < 1 || $num > 500) {
                    throw new InvalidArgumentException("snaScheduleOverride value out of range: {$num}");
                }
                $schedule[] = $num;
            } else {
                throw new InvalidArgumentException('Invalid snaScheduleOverride value: ' . json_encode($part));
            }
        }
        return $schedule;
    }

    // ---- operations ----

    public static function health(): array
    {
        return ['error' => false, 'status' => 'ok'];
    }

    public static function calculateCalories(array $p): array
    {
        return CalculatorApi::calculateCalories(
            self::requireParam($p, 'percentAlcohol'),
            self::requireParam($p, 'fg'),
            self::requireParam($p, 'bottleVolume'),
            self::requireParam($p, 'servingVolume')
        );
    }

    public static function calculateABV(array $p): array
    {
        $og = self::requireParam($p, 'og');
        $fg = self::optionalParam($p, 'fg');
        return CalculatorApi::calculateABV($og, $fg);
    }

    public static function convertGravityDropToABV(array $p): array
    {
        $sgDelta = self::requireNumeric($p, 'sgDelta');
        return ['error' => false, 'sgDelta' => $sgDelta, 'abv' => CalculatorApi::convertGravityDropToABV($sgDelta)];
    }

    public static function estimateDryFG(array $p): array
    {
        $og = self::requireNumeric($p, 'og');
        return ['error' => false, 'og' => $og, 'fg' => CalculatorApi::estimateDryFG($og)];
    }

    public static function listVolumeUnits(): array
    {
        return ['error' => false, 'volumeUnits' => CalculatorApi::listVolumeUnits()];
    }

    public static function getVolumeUnit(array $p): array
    {
        $name = (string) self::requireParam($p, 'name');
        return self::lookupResult(CalculatorApi::getVolumeUnit($name), 'Unknown volume unit: ' . $name);
    }

    public static function convertVolume(array $p): array
    {
        return CalculatorApi::convertVolume(
            self::requireParam($p, 'amount'),
            (string) self::requireParam($p, 'fromUnit'),
            (string) self::requireParam($p, 'toUnit')
        );
    }

    public static function getHoneyUnit(array $p): array
    {
        $name = (string) self::requireParam($p, 'name');
        return self::lookupResult(CalculatorApi::getHoneyUnit($name), 'Unknown honey unit: ' . $name);
    }

    public static function convertHoneyUnits(array $p): array
    {
        return CalculatorApi::convertHoneyUnits(
            self::requireParam($p, 'amount'),
            (string) self::requireParam($p, 'fromUnit'),
            (string) self::requireParam($p, 'toUnit')
        );
    }

    public static function convertTemperature(array $p): array
    {
        return CalculatorApi::convertTemperature(
            self::requireParam($p, 'fromTemperature'),
            (string) self::requireParam($p, 'fromUnit')
        );
    }

    public static function convertSGToBrix(array $p): array
    {
        $sg = self::requireNumeric($p, 'sg');
        return ['error' => false, 'sg' => $sg, 'brix' => CalculatorApi::convertSGToBrix($sg)];
    }

    public static function computeDelle(array $p): array
    {
        return CalculatorApi::computeDelle(self::requireParam($p, 'abv'), self::requireParam($p, 'sg'));
    }

    public static function potentialAlcohol(array $p): array
    {
        $gravityUnits = self::parseGravityUnits(self::optionalParam($p, 'gravityUnits'));
        $abvUnits = self::parseAbvUnits(self::optionalParam($p, 'abvUnits'));
        $og = self::optionalParam($p, 'og');
        $fg = self::optionalParam($p, 'fg');
        $abv = self::optionalParam($p, 'abv');

        if ($og === null && $fg === null && $abv === null) {
            throw new InvalidArgumentException('At least one of og, fg, or abv must be specified.');
        }
        foreach (['og' => $og, 'fg' => $fg, 'abv' => $abv] as $name => $value) {
            if ($value !== null && !is_numeric($value)) {
                throw new InvalidArgumentException("Parameter '{$name}' must be numeric.");
            }
        }

        return GravityCalculator::potentialAlcohol(
            $gravityUnits,
            $abvUnits,
            $og === null ? null : (float) $og,
            $fg === null ? null : (float) $fg,
            $abv === null ? null : (float) $abv
        );
    }

    public static function calculateBlend(array $p): array
    {
        return BlendCalculator::calculateBlend(
            self::parseBlendField((string) self::requireParam($p, 'fieldToCalculate')),
            self::optionalNumeric($p, 'value1'),
            self::optionalNumeric($p, 'value2'),
            self::optionalNumeric($p, 'blendedValue'),
            self::optionalNumeric($p, 'volume1'),
            self::optionalNumeric($p, 'volume2'),
            self::optionalNumeric($p, 'totalVolume')
        );
    }

    public static function calculateNutrients(array $p): array
    {
        $units = self::parseUnits(self::optionalParam($p, 'units'));
        $volume = self::optionalNumeric($p, 'volume') ?? ($units === Constants::UNITS_US ? 5.0 : 18.9);

        return NutrientCalculator::calculateNutrients([
            'units' => $units,
            'volume' => $volume,
            'yan' => self::optionalNumeric($p, 'yan') ?? 175.0,
            'fermOEffectiveness' => self::optionalNumeric($p, 'fermOEffectiveness') ?? 2.6,
            'enforceLimits' => filter_var(self::optionalParam($p, 'enforceLimits', true), FILTER_VALIDATE_BOOLEAN),
            'dapLimit' => self::optionalNumeric($p, 'dapLimit') ?? 0.96,
            'fermKLimit' => self::optionalNumeric($p, 'fermKLimit') ?? 0.5,
            'fermOLimit' => self::optionalNumeric($p, 'fermOLimit') ?? 0.45,
            'yanRatioDap' => self::optionalNumeric($p, 'yanRatioDap') ?? 35.0,
            'yanRatioFermK' => self::optionalNumeric($p, 'yanRatioFermK') ?? 25.0,
            'yanRatioFermO' => self::optionalNumeric($p, 'yanRatioFermO') ?? 40.0,
            'fermKYan' => self::optionalNumeric($p, 'fermKYan') ?? 134.0,
            'fillFkFirst' => filter_var(self::optionalParam($p, 'fillFkFirst', true), FILTER_VALIDATE_BOOLEAN),
            'gofermYan' => self::optionalNumeric($p, 'gofermYan') ?? 77.0,
            'gofermGrams' => self::optionalNumeric($p, 'gofermGrams') ?? 0.0,
        ]) + ['error' => false];
    }

    public static function buildBatch(array $p): array
    {
        $units = self::parseUnits(self::optionalParam($p, 'units'));
        $volume = self::optionalNumeric($p, 'volume') ?? ($units === Constants::UNITS_US ? 5.0 : 18.9);

        return BatchCalculator::buildBatch([
            'units' => $units,
            'volume' => $volume,
            'yeastAbv' => self::optionalNumeric($p, 'yeastAbv') ?? 18.0,
            'residualSugar' => self::optionalNumeric($p, 'residualSugar') ?? 1.02,
            'yanRequirement' => self::parseYanRequirement(self::optionalParam($p, 'yanRequirement')),
            'nutrientRegimen' => self::parseNutrientRegimen(self::optionalParam($p, 'nutrientRegimen')),
            'ogOverride' => self::optionalNumeric($p, 'ogOverride') ?? 0.0,
            'pitchRateOverride' => self::optionalNumeric($p, 'pitchRateOverride') ?? 0.0,
            'fruitSg' => self::optionalNumeric($p, 'fruitSg') ?? 0.0,
            'yanOverride' => self::optionalNumeric($p, 'yanOverride') ?? 0.0,
            'fermOEffectiveness' => self::optionalNumeric($p, 'fermOEffectiveness') ?? 2.6,
            'enforceLimits' => filter_var(self::optionalParam($p, 'enforceLimits', true), FILTER_VALIDATE_BOOLEAN),
            'dapLimit' => self::optionalNumeric($p, 'dapLimit') ?? 0.96,
            'fermKLimit' => self::optionalNumeric($p, 'fermKLimit') ?? 0.5,
            'fermOLimit' => self::optionalNumeric($p, 'fermOLimit') ?? 0.45,
            'yanRatioDap' => self::optionalNumeric($p, 'yanRatioDap') ?? 35.0,
            'yanRatioFermK' => self::optionalNumeric($p, 'yanRatioFermK') ?? 25.0,
            'yanRatioFermO' => self::optionalNumeric($p, 'yanRatioFermO') ?? 40.0,
            'fermKYan' => self::optionalNumeric($p, 'fermKYan') ?? 134.0,
            'gofermYan' => self::optionalNumeric($p, 'gofermYan') ?? 77.0,
            'fillFkFirst' => filter_var(self::optionalParam($p, 'fillFkFirst', true), FILTER_VALIDATE_BOOLEAN),
            'hot' => filter_var(self::optionalParam($p, 'hot', false), FILTER_VALIDATE_BOOLEAN),
            'snaScheduleOverride' => self::parseSnaSchedule(self::optionalParam($p, 'snaScheduleOverride')),
        ]);
    }

    public static function calculateMead(array $p): array
    {
        $units = self::parseUnits(self::optionalParam($p, 'units'));

        return BatchCalculator::calculateMead([
            'units' => $units,
            'mustTemperature' => self::optionalNumeric($p, 'mustTemperature'),
            'mustTemperatureUnits' => isset($p['mustTemperatureUnits']) ? self::parseTemperatureUnits((string) $p['mustTemperatureUnits']) : null,
            'targetGravity' => self::optionalNumeric($p, 'targetGravity'),
            'targetGravityUnits' => isset($p['targetGravityUnits']) ? self::parseGravityUnits((string) $p['targetGravityUnits']) : null,
            'targetAbv' => self::optionalNumeric($p, 'targetAbv'),
            'targetAbvUnits' => isset($p['targetAbvUnits']) ? self::parseAbvUnits((string) $p['targetAbvUnits']) : null,
            'targetVolume' => self::optionalNumeric($p, 'targetVolume'),
            'targetVolumeUnits' => isset($p['targetVolumeUnits']) ? self::parseVolumeUnits((string) $p['targetVolumeUnits']) : null,
            'additionalSugars' => self::parseAdditionalSugars(self::optionalParam($p, 'additionalSugars')),
            'currentGravity' => self::optionalNumeric($p, 'currentGravity'),
            'currentGravityUnits' => isset($p['currentGravityUnits']) ? self::parseGravityUnits((string) $p['currentGravityUnits']) : null,
            'currentVolume' => self::optionalNumeric($p, 'currentVolume'),
            'currentVolumeUnits' => isset($p['currentVolumeUnits']) ? self::parseVolumeUnits((string) $p['currentVolumeUnits']) : null,
            'targetStepFeedGravity' => self::optionalNumeric($p, 'targetStepFeedGravity'),
            'yeastAbv' => self::optionalNumeric($p, 'yeastAbv') ?? 18.0,
            'yanRequirement' => self::parseYanRequirement(self::optionalParam($p, 'yanRequirement')),
            'hot' => filter_var(self::optionalParam($p, 'hot', false), FILTER_VALIDATE_BOOLEAN),
            'calculateAdditiveHoney' => filter_var(self::optionalParam($p, 'calculateAdditiveHoney', false), FILTER_VALIDATE_BOOLEAN),
            'fermOEffectiveness' => self::optionalNumeric($p, 'fermOEffectiveness') ?? 2.6,
            'enforceLimits' => filter_var(self::optionalParam($p, 'enforceLimits', true), FILTER_VALIDATE_BOOLEAN),
            'dapLimit' => self::optionalNumeric($p, 'dapLimit') ?? 0.96,
            'fermKLimit' => self::optionalNumeric($p, 'fermKLimit') ?? 0.5,
            'fermOLimit' => self::optionalNumeric($p, 'fermOLimit') ?? 0.45,
            'yanRatioDap' => self::optionalNumeric($p, 'yanRatioDap') ?? 35.0,
            'yanRatioFermK' => self::optionalNumeric($p, 'yanRatioFermK') ?? 25.0,
            'yanRatioFermO' => self::optionalNumeric($p, 'yanRatioFermO') ?? 40.0,
            'fermKYan' => self::optionalNumeric($p, 'fermKYan') ?? 134.0,
            'gofermYan' => self::optionalNumeric($p, 'gofermYan') ?? 77.0,
            'fillFkFirst' => filter_var(self::optionalParam($p, 'fillFkFirst', true), FILTER_VALIDATE_BOOLEAN),
            'useGoferm' => filter_var(self::optionalParam($p, 'useGoferm', true), FILTER_VALIDATE_BOOLEAN),
            'yeastPackGrams' => self::optionalNumeric($p, 'yeastPackGrams') ?? 5.0,
        ]);
    }

    public static function listYeastRequirements(): array
    {
        return ['error' => false, 'yeastRequirements' => CalculatorApi::listYeastRequirements()];
    }

    public static function getSugarSourceIdentifier(array $p): array
    {
        $name = (string) self::requireParam($p, 'name');
        $id = CalculatorApi::getSugarSourceIdentifier($name);
        $info = $id !== null ? Constants::SUGAR_SOURCE_INFO[$id] : null;
        return self::lookupResult($id, 'Unknown sugar source: ' . $name, $info !== null ? ['sugarSource' => $info] : []);
    }

    public static function getDaysBetween(array $p): array
    {
        $date1 = self::requireDate($p, 'date1');
        $date2 = self::requireDate($p, 'date2');
        return ['error' => false, 'daysBetween' => CalculatorApi::getDaysBetween($date1, $date2)];
    }

    public static function getMonthsBetween(array $p): array
    {
        $date1 = self::requireDate($p, 'date1');
        $date2 = self::requireDate($p, 'date2');
        $roundUp = filter_var(self::optionalParam($p, 'roundUpFractionalMonths', false), FILTER_VALIDATE_BOOLEAN);
        return ['error' => false, 'monthsBetween' => CalculatorApi::getMonthsBetween($date1, $date2, $roundUp)];
    }

    public static function randomInteger(array $p): array
    {
        $max = (int) self::requireNumeric($p, 'max');
        return ['error' => false, 'max' => $max, 'value' => CalculatorApi::randomInteger($max)];
    }

    public static function makeHoursString(array $p): array
    {
        $timing = (string) self::requireParam($p, 'timing');
        $break3 = self::optionalParam($p, 'break3');
        return [
            'error' => false,
            'timing' => $timing,
            'hoursString' => CalculatorApi::makeHoursString($timing, $break3 === null ? null : (float) $break3),
        ];
    }
}
