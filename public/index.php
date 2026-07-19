<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MeadBotApi\Chat\ChatAgent;
use MeadBotApi\Chat\FireworksClient;
use MeadBotApi\Http\Env;
use MeadBotApi\Http\Operations;
use MeadBotApi\Http\Router;

Env::load(dirname(__DIR__) . '/.env');

$router = new Router();

$router->get('/api/v1/health', fn () => Operations::health());

// Calories
$router->post('/api/v1/calories', fn (array $p) => Operations::calculateCalories($p));

// ABV
$router->post('/api/v1/abv', fn (array $p) => Operations::calculateABV($p));
$router->post('/api/v1/gravity-drop-to-abv', fn (array $p) => Operations::convertGravityDropToABV($p));
$router->post('/api/v1/dry-fg', fn (array $p) => Operations::estimateDryFG($p));

// Volume units / conversion
$router->get('/api/v1/volume-units', fn () => Operations::listVolumeUnits());
$router->get('/api/v1/volume-units/{name}', fn (array $p) => Operations::getVolumeUnit($p));
$router->post('/api/v1/volume/convert', fn (array $p) => Operations::convertVolume($p));

// Honey units / conversion
$router->get('/api/v1/honey-units/{name}', fn (array $p) => Operations::getHoneyUnit($p));
$router->post('/api/v1/honey/convert', fn (array $p) => Operations::convertHoneyUnits($p));

// Temperature conversion
$router->post('/api/v1/temperature/convert', fn (array $p) => Operations::convertTemperature($p));

// Gravity / Delle
$router->post('/api/v1/sg-to-brix', fn (array $p) => Operations::convertSGToBrix($p));
$router->post('/api/v1/delle', fn (array $p) => Operations::computeDelle($p));
$router->post('/api/v1/potential-alcohol', fn (array $p) => Operations::potentialAlcohol($p));

$router->post('/api/v1/calculate-blend', fn (array $p) => Operations::calculateBlend($p));
$router->post('/api/v1/calculate-nutrients', fn (array $p) => Operations::calculateNutrients($p));
$router->post('/api/v1/build-batch', fn (array $p) => Operations::buildBatch($p));
$router->post('/api/v1/calculate-mead', fn (array $p) => Operations::calculateMead($p));

$router->get('/api/v1/yeast-requirements', fn () => Operations::listYeastRequirements());

// Sugar sources
$router->get('/api/v1/sugar-sources/{name}', fn (array $p) => Operations::getSugarSourceIdentifier($p));

// Dates
$router->post('/api/v1/dates/days-between', fn (array $p) => Operations::getDaysBetween($p));
$router->post('/api/v1/dates/months-between', fn (array $p) => Operations::getMonthsBetween($p));

// Misc
$router->get('/api/v1/random', fn (array $p) => Operations::randomInteger($p));
$router->post('/api/v1/hours-string', fn (array $p) => Operations::makeHoursString($p));

// Chat agent
$router->post('/api/v1/chat', function (array $p) {
    $apiKey = getenv('CHAT_API_KEY');
    if ($apiKey === false || $apiKey === '') {
        return ['error' => true, 'errorMessage' => 'Chat is not configured on this server.'];
    }
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($apiKey, $provided)) {
        return ['error' => true, 'errorMessage' => 'Missing or invalid X-Api-Key header.'];
    }

    $fireworksKey = getenv('FIREWORKS_API_KEY');
    if ($fireworksKey === false || $fireworksKey === '') {
        return ['error' => true, 'errorMessage' => 'Chat is not configured on this server.'];
    }

    $messages = $p['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return ['error' => true, 'errorMessage' => 'messages must be a non-empty array of {role, content} objects.'];
    }

    $model = getenv('FIREWORKS_MODEL') ?: 'accounts/fireworks/models/firefunction-v2';
    $agent = new ChatAgent(new FireworksClient($fireworksKey, $model));

    try {
        $result = $agent->run($messages);
    } catch (\RuntimeException $e) {
        return ['error' => true, 'errorMessage' => 'Chat backend error: ' . $e->getMessage()];
    }

    return [
        'error' => false,
        'reply' => $result['reply'],
        'messages' => $result['messages'],
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
