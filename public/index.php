<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MeadBotApi\Calculator\BatchCalculator;
use MeadBotApi\Calculator\BlendCalculator;
use MeadBotApi\Calculator\CalculatorApi;
use MeadBotApi\Calculator\Constants;
use MeadBotApi\Calculator\GravityCalculator;
use MeadBotApi\Calculator\NutrientCalculator;
use MeadBotApi\Http\Router;

/** Fetch a required param, throwing a 400-mappable exception if it's missing. */
function requireParam(array $params, string $name): mixed
{
    if (!array_key_exists($name, $params) || $params[$name] === null || $params[$name] === '') {
        throw new InvalidArgumentException("Missing required parameter: {$name}");
    }
    return $params[$name];
}

function optionalParam(array $params, string $name, mixed $default = null): mixed
{
    if (!array_key_exists($name, $params) || $params[$name] === null || $params[$name] === '') {
        return $default;
    }
    return $params[$name];
}

function requireNumeric(array $params, string $name): float
{
    $value = requireParam($params, $name);
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Parameter '{$name}' must be numeric.");
    }
    return (float) $value;
}

function optionalNumeric(array $params, string $name): ?float
{
    $value = optionalParam($params, $name);
    if ($value === null) {
        return null;
    }
    if (!is_numeric($value)) {
        throw new InvalidArgumentException("Parameter '{$name}' must be numeric.");
    }
    return (float) $value;
}

function requireDate(array $params, string $name): DateTimeImmutable
{
    $value = requireParam($params, $name);
    $date = date_create_immutable((string) $value);
    if ($date === false) {
        throw new InvalidArgumentException("Parameter '{$name}' must be a parseable date/time string.");
    }
    return $date;
}

/** Wrap a nullable-int lookup result in the same {error, ...} envelope the calculation methods use. */
function lookupResult(?int $id, string $notFoundMessage, array $extra = []): array
{
    if ($id === null) {
        return [
            'error' => true,
            'errorMessage' => $notFoundMessage,
            'errorType' => \MeadBotApi\Calculator\Constants::ERROR_INVALID_ARGUMENTS,
            'errorTypeLabel' => 'invalid arguments',
        ];
    }
    return array_merge(['error' => false, 'unitId' => $id], $extra);
}

function parseGravityUnits(?string $value): int
{
    return match ($value) {
        null, 'sg' => Constants::GRAVITY_UNIT_SG,
        'brix' => Constants::GRAVITY_UNIT_BRIX,
        'baume' => Constants::GRAVITY_UNIT_BAUME,
        default => throw new InvalidArgumentException("Unknown gravity units: {$value}"),
    };
}

function parseAbvUnits(?string $value): int
{
    return match ($value) {
        null, 'abv' => Constants::ABV_UNIT_ABV,
        'abw' => Constants::ABV_UNIT_ABW,
        default => throw new InvalidArgumentException("Unknown abv units: {$value}"),
    };
}

function parseBlendField(string $value): int
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

function parseUnits(?string $value): int
{
    return match ($value) {
        null, 'us' => Constants::UNITS_US,
        'metric' => Constants::UNITS_METRIC,
        'imperial' => Constants::UNITS_IMPERIAL,
        default => throw new InvalidArgumentException("Unknown units: {$value}"),
    };
}

function parseYanRequirement(?string $value): int
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

function parseNutrientRegimen(?string $value): int
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

/**
 * Validates an optional SNA schedule: each element must be the string "pitch" (only as the
 * first element), the string "break", or a number in [1, 500].
 *
 * @return array<int, int|string>|null
 */
function parseSnaSchedule(mixed $value): ?array
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

$router = new Router();

$router->get('/api/v1/health', fn () => ['error' => false, 'status' => 'ok']);

// Calories
$router->post('/api/v1/calories', fn (array $p) => CalculatorApi::calculateCalories(
    requireParam($p, 'percentAlcohol'),
    requireParam($p, 'fg'),
    requireParam($p, 'bottleVolume'),
    requireParam($p, 'servingVolume')
));

// ABV
$router->post('/api/v1/abv', function (array $p) {
    $og = requireParam($p, 'og');
    $fg = optionalParam($p, 'fg');
    return CalculatorApi::calculateABV($og, $fg);
});

$router->post('/api/v1/gravity-drop-to-abv', function (array $p) {
    $sgDelta = requireNumeric($p, 'sgDelta');
    return ['error' => false, 'sgDelta' => $sgDelta, 'abv' => CalculatorApi::convertGravityDropToABV($sgDelta)];
});

$router->post('/api/v1/dry-fg', function (array $p) {
    $og = requireNumeric($p, 'og');
    return ['error' => false, 'og' => $og, 'fg' => CalculatorApi::estimateDryFG($og)];
});

// Volume units / conversion
$router->get('/api/v1/volume-units/{name}', fn (array $p) => lookupResult(
    CalculatorApi::getVolumeUnit($p['name']),
    'Unknown volume unit: ' . $p['name']
));

$router->post('/api/v1/volume/convert', fn (array $p) => CalculatorApi::convertVolume(
    requireParam($p, 'amount'),
    (string) requireParam($p, 'fromUnit'),
    (string) requireParam($p, 'toUnit')
));

// Honey units / conversion
$router->get('/api/v1/honey-units/{name}', fn (array $p) => lookupResult(
    CalculatorApi::getHoneyUnit($p['name']),
    'Unknown honey unit: ' . $p['name']
));

$router->post('/api/v1/honey/convert', fn (array $p) => CalculatorApi::convertHoneyUnits(
    requireParam($p, 'amount'),
    (string) requireParam($p, 'fromUnit'),
    (string) requireParam($p, 'toUnit')
));

// Temperature conversion
$router->post('/api/v1/temperature/convert', fn (array $p) => CalculatorApi::convertTemperature(
    requireParam($p, 'fromTemperature'),
    (string) requireParam($p, 'fromUnit')
));

// Gravity / Delle
$router->post('/api/v1/sg-to-brix', function (array $p) {
    $sg = requireNumeric($p, 'sg');
    return ['error' => false, 'sg' => $sg, 'brix' => CalculatorApi::convertSGToBrix($sg)];
});

$router->post('/api/v1/delle', fn (array $p) => CalculatorApi::computeDelle(
    requireParam($p, 'abv'),
    requireParam($p, 'sg')
));

$router->post('/api/v1/potential-alcohol', function (array $p) {
    $gravityUnits = parseGravityUnits(optionalParam($p, 'gravityUnits'));
    $abvUnits = parseAbvUnits(optionalParam($p, 'abvUnits'));
    $og = optionalParam($p, 'og');
    $fg = optionalParam($p, 'fg');
    $abv = optionalParam($p, 'abv');

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
});

$router->post('/api/v1/calculate-blend', fn (array $p) => BlendCalculator::calculateBlend(
    parseBlendField((string) requireParam($p, 'fieldToCalculate')),
    optionalNumeric($p, 'value1'),
    optionalNumeric($p, 'value2'),
    optionalNumeric($p, 'blendedValue'),
    optionalNumeric($p, 'volume1'),
    optionalNumeric($p, 'volume2'),
    optionalNumeric($p, 'totalVolume')
));

$router->post('/api/v1/calculate-nutrients', function (array $p) {
    $units = parseUnits(optionalParam($p, 'units'));
    $volume = optionalNumeric($p, 'volume') ?? ($units === Constants::UNITS_US ? 5.0 : 18.9);

    return NutrientCalculator::calculateNutrients([
        'units' => $units,
        'volume' => $volume,
        'yan' => optionalNumeric($p, 'yan') ?? 175.0,
        'fermOEffectiveness' => optionalNumeric($p, 'fermOEffectiveness') ?? 2.6,
        'enforceLimits' => filter_var(optionalParam($p, 'enforceLimits', true), FILTER_VALIDATE_BOOLEAN),
        'dapLimit' => optionalNumeric($p, 'dapLimit') ?? 0.96,
        'fermKLimit' => optionalNumeric($p, 'fermKLimit') ?? 0.5,
        'fermOLimit' => optionalNumeric($p, 'fermOLimit') ?? 0.45,
        'yanRatioDap' => optionalNumeric($p, 'yanRatioDap') ?? 35.0,
        'yanRatioFermK' => optionalNumeric($p, 'yanRatioFermK') ?? 25.0,
        'yanRatioFermO' => optionalNumeric($p, 'yanRatioFermO') ?? 40.0,
        'fermKYan' => optionalNumeric($p, 'fermKYan') ?? 134.0,
        'fillFkFirst' => filter_var(optionalParam($p, 'fillFkFirst', true), FILTER_VALIDATE_BOOLEAN),
        'gofermYan' => optionalNumeric($p, 'gofermYan') ?? 77.0,
        'gofermGrams' => optionalNumeric($p, 'gofermGrams') ?? 0.0,
    ]) + ['error' => false];
});

$router->post('/api/v1/build-batch', function (array $p) {
    $units = parseUnits(optionalParam($p, 'units'));
    $volume = optionalNumeric($p, 'volume') ?? ($units === Constants::UNITS_US ? 5.0 : 18.9);

    return BatchCalculator::buildBatch([
        'units' => $units,
        'volume' => $volume,
        'yeastAbv' => optionalNumeric($p, 'yeastAbv') ?? 18.0,
        'residualSugar' => optionalNumeric($p, 'residualSugar') ?? 1.02,
        'yanRequirement' => parseYanRequirement(optionalParam($p, 'yanRequirement')),
        'nutrientRegimen' => parseNutrientRegimen(optionalParam($p, 'nutrientRegimen')),
        'ogOverride' => optionalNumeric($p, 'ogOverride') ?? 0.0,
        'pitchRateOverride' => optionalNumeric($p, 'pitchRateOverride') ?? 0.0,
        'fruitSg' => optionalNumeric($p, 'fruitSg') ?? 0.0,
        'yanOverride' => optionalNumeric($p, 'yanOverride') ?? 0.0,
        'fermOEffectiveness' => optionalNumeric($p, 'fermOEffectiveness') ?? 2.6,
        'enforceLimits' => filter_var(optionalParam($p, 'enforceLimits', true), FILTER_VALIDATE_BOOLEAN),
        'dapLimit' => optionalNumeric($p, 'dapLimit') ?? 0.96,
        'fermKLimit' => optionalNumeric($p, 'fermKLimit') ?? 0.5,
        'fermOLimit' => optionalNumeric($p, 'fermOLimit') ?? 0.45,
        'yanRatioDap' => optionalNumeric($p, 'yanRatioDap') ?? 35.0,
        'yanRatioFermK' => optionalNumeric($p, 'yanRatioFermK') ?? 25.0,
        'yanRatioFermO' => optionalNumeric($p, 'yanRatioFermO') ?? 40.0,
        'fermKYan' => optionalNumeric($p, 'fermKYan') ?? 134.0,
        'gofermYan' => optionalNumeric($p, 'gofermYan') ?? 77.0,
        'fillFkFirst' => filter_var(optionalParam($p, 'fillFkFirst', true), FILTER_VALIDATE_BOOLEAN),
        'hot' => filter_var(optionalParam($p, 'hot', false), FILTER_VALIDATE_BOOLEAN),
        'snaScheduleOverride' => parseSnaSchedule(optionalParam($p, 'snaScheduleOverride')),
    ]);
});

// Sugar sources
$router->get('/api/v1/sugar-sources/{name}', function (array $p) {
    $id = CalculatorApi::getSugarSourceIdentifier($p['name']);
    $info = $id !== null ? \MeadBotApi\Calculator\Constants::SUGAR_SOURCE_INFO[$id] : null;
    return lookupResult($id, 'Unknown sugar source: ' . $p['name'], $info !== null ? ['sugarSource' => $info] : []);
});

// Dates
$router->post('/api/v1/dates/days-between', function (array $p) {
    $date1 = requireDate($p, 'date1');
    $date2 = requireDate($p, 'date2');
    return ['error' => false, 'daysBetween' => CalculatorApi::getDaysBetween($date1, $date2)];
});

$router->post('/api/v1/dates/months-between', function (array $p) {
    $date1 = requireDate($p, 'date1');
    $date2 = requireDate($p, 'date2');
    $roundUp = filter_var(optionalParam($p, 'roundUpFractionalMonths', false), FILTER_VALIDATE_BOOLEAN);
    return ['error' => false, 'monthsBetween' => CalculatorApi::getMonthsBetween($date1, $date2, $roundUp)];
});

// Misc
$router->get('/api/v1/random', function (array $p) {
    $max = (int) requireNumeric($p, 'max');
    return ['error' => false, 'max' => $max, 'value' => CalculatorApi::randomInteger($max)];
});

$router->post('/api/v1/hours-string', function (array $p) {
    $timing = (string) requireParam($p, 'timing');
    $break3 = optionalParam($p, 'break3');
    return [
        'error' => false,
        'timing' => $timing,
        'hoursString' => CalculatorApi::makeHoursString($timing, $break3 === null ? null : (float) $break3),
    ];
});

// --- dispatch ---

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}

$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = [];
if ($rawBody !== '' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $jsonBody = $decoded;
    }
}

[$status, $body] = $router->dispatch($method, $path, function (array $pathParams) use ($jsonBody) {
    // precedence: path params > JSON body > query string
    return array_merge($_GET, $jsonBody, $pathParams);
});

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
