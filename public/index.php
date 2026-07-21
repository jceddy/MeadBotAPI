<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MeadBotApi\Chat\ChatAgent;
use MeadBotApi\Chat\ChatUsageException;
use MeadBotApi\Chat\CostCalculator;
use MeadBotApi\Chat\FireworksClient;
use MeadBotApi\Feedback\ChatFeedbackStore;
use MeadBotApi\Http\Env;
use MeadBotApi\Http\Operations;
use MeadBotApi\Http\Router;
use MeadBotApi\Ledger\Ledger;

Env::load(dirname(__DIR__) . '/.env');

/** Shared by /chat, /chat/feedback, and /balance/*: checks X-Api-Key against CHAT_API_KEY. Returns an error array to short-circuit the route, or null when authorized. */
function requireChatApiKey(): ?array
{
    $apiKey = getenv('CHAT_API_KEY');
    if ($apiKey === false || $apiKey === '') {
        return ['error' => true, 'errorMessage' => 'This endpoint is not configured on this server.'];
    }
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals($apiKey, $provided)) {
        return ['error' => true, 'errorMessage' => 'Missing or invalid X-Api-Key header.'];
    }
    return null;
}

/**
 * gpt-oss-120b's published per-1M-token pricing by default; override via .env if FIREWORKS_MODEL
 * is ever changed to a model with different rates.
 */
function fireworksPricing(): CostCalculator
{
    return new CostCalculator(
        (float) (getenv('FIREWORKS_PRICE_INPUT_PER_1M') ?: 0.15),
        (float) (getenv('FIREWORKS_PRICE_CACHED_INPUT_PER_1M') ?: 0.01),
        (float) (getenv('FIREWORKS_PRICE_OUTPUT_PER_1M') ?: 0.60)
    );
}

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
    if ($authError = requireChatApiKey()) {
        return $authError;
    }

    $fireworksKey = getenv('FIREWORKS_API_KEY');
    if ($fireworksKey === false || $fireworksKey === '') {
        return ['error' => true, 'errorMessage' => 'Chat is not configured on this server.'];
    }

    $messages = $p['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return ['error' => true, 'errorMessage' => 'messages must be a non-empty array of {role, content} objects.'];
    }

    // Optional caller-supplied identifier, purely for usage tracking (see
    // GET /api/v1/balance/usage-by-user) — opaque to this endpoint, not sent to Fireworks.
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId !== null && trim($userId) === '') {
        $userId = null;
    }

    $model = getenv('FIREWORKS_MODEL') ?: 'accounts/fireworks/models/gpt-oss-120b';
    $agent = new ChatAgent(new FireworksClient($fireworksKey, $model));
    $pricing = fireworksPricing();
    $ledger = Ledger::connect();

    try {
        $result = $agent->run($messages);
    } catch (ChatUsageException $e) {
        // Fireworks already billed for whatever calls succeeded before this failure, even
        // though the request as a whole didn't complete — surface usage/cost here too so the
        // ledger doesn't silently miss it.
        $costUsd = $pricing->costUsd($e->usage);
        $ledger->recordChatUsage($e->usage, $costUsd, $model, false, $e->getMessage(), $userId);
        return [
            'error' => true,
            'errorMessage' => 'Chat backend error: ' . $e->getMessage(),
            'insufficientBalance' => $e->insufficientBalance,
            'usage' => $e->usage,
            'costUsd' => $costUsd,
        ];
    }

    $costUsd = $pricing->costUsd($result['usage']);
    $ledger->recordChatUsage($result['usage'], $costUsd, $model, true, null, $userId);

    return [
        'error' => false,
        'reply' => $result['reply'],
        'messages' => $result['messages'],
        'usage' => $result['usage'],
        'costUsd' => $costUsd,
    ];
});

// Negative feedback on a !chat reply (a Discord 👎 reaction), recorded by MeadBot's
// messageReactionAdd handler once it's confirmed the reacted-to message really was a chat reply.
$router->post('/api/v1/chat/feedback', function (array $p) {
    if ($authError = requireChatApiKey()) {
        return $authError;
    }

    $discordUserId = isset($p['discordUserId']) ? trim((string) $p['discordUserId']) : '';
    $discordMessageId = isset($p['discordMessageId']) ? trim((string) $p['discordMessageId']) : '';
    if ($discordUserId === '' || $discordMessageId === '') {
        return ['error' => true, 'errorMessage' => "Parameters 'discordUserId' and 'discordMessageId' are required."];
    }

    $discordChannelId = !empty($p['discordChannelId']) ? (string) $p['discordChannelId'] : null;
    $discordGuildId = !empty($p['discordGuildId']) ? (string) $p['discordGuildId'] : null;

    $messages = $p['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return ['error' => true, 'errorMessage' => 'messages must be a non-empty array of {role, content} objects.'];
    }

    try {
        ChatFeedbackStore::connect()->record($discordUserId, $discordMessageId, $discordChannelId, $discordGuildId, $messages);
    } catch (\RuntimeException $e) {
        return ['error' => true, 'errorMessage' => $e->getMessage()];
    }

    return ['error' => false];
});

// Balance ledger
$router->post('/api/v1/balance/deposits', function (array $p) {
    if ($authError = requireChatApiKey()) {
        return $authError;
    }

    $amountUsd = $p['amountUsd'] ?? null;
    if (!is_numeric($amountUsd)) {
        return ['error' => true, 'errorMessage' => "Parameter 'amountUsd' must be numeric."];
    }
    $note = isset($p['note']) ? (string) $p['note'] : null;

    $ledger = Ledger::connect();
    try {
        $ledger->recordDeposit((float) $amountUsd, $note);
        $balance = $ledger->getBalance();
    } catch (\RuntimeException $e) {
        return ['error' => true, 'errorMessage' => $e->getMessage()];
    }

    return ['error' => false, 'deposit' => ['amountUsd' => (float) $amountUsd, 'note' => $note], 'balance' => $balance];
});

$router->get('/api/v1/balance', function () {
    if ($authError = requireChatApiKey()) {
        return $authError;
    }

    $ledger = Ledger::connect();
    try {
        $balance = $ledger->getBalance();
    } catch (\RuntimeException $e) {
        return ['error' => true, 'errorMessage' => $e->getMessage()];
    }

    return ['error' => false, 'balance' => $balance];
});

$router->get('/api/v1/balance/usage-by-user', function () {
    if ($authError = requireChatApiKey()) {
        return $authError;
    }

    $ledger = Ledger::connect();
    try {
        $usageByUser = $ledger->usageByUser();
    } catch (\RuntimeException $e) {
        return ['error' => true, 'errorMessage' => $e->getMessage()];
    }

    return ['error' => false, 'usageByUser' => $usageByUser];
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
