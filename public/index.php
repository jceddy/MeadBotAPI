<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MeadBotApi\Calculator\CalculatorApi;
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
