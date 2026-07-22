<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MeadBotApi\Auth\DiscordOAuth;
use MeadBotApi\Chat\ChatAgent;
use MeadBotApi\Chat\ChatUsageException;
use MeadBotApi\Chat\FireworksClient;
use MeadBotApi\Chat\ModelCatalog;
use MeadBotApi\Feedback\ChatFeedbackStore;
use MeadBotApi\Http\Env;
use MeadBotApi\Http\Operations;
use MeadBotApi\Http\Router;
use MeadBotApi\Ledger\Ledger;

Env::load(dirname(__DIR__) . '/.env');

// Session cookie backs the web app's "Login with Discord" flow (see /api/v1/auth/* and
// /api/v1/chat/web below) -- httponly/samesite so it's not readable/forgeable from JS or a
// cross-site request, secure whenever the request itself arrived over HTTPS (including behind a
// reverse proxy that terminates TLS and forwards the original scheme).
$requestIsHttps = (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => $requestIsHttps]);
session_start();

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

/** Shared by /chat and /chat/web: checks FIREWORKS_API_KEY is configured. Returns an error array to short-circuit the route, or null when configured. */
function requireFireworksConfigured(): ?array
{
    $fireworksKey = getenv('FIREWORKS_API_KEY');
    if ($fireworksKey === false || $fireworksKey === '') {
        return ['error' => true, 'errorMessage' => 'Chat is not configured on this server.'];
    }
    return null;
}

/** Shared by /chat and /chat/web: validates the messages/model request params. Returns ['error' => array] to short-circuit the route, or ['messages' => ..., 'modelKey' => ...] on success. */
function parseChatRequest(array $p): array
{
    $messages = $p['messages'] ?? null;
    if (!is_array($messages) || $messages === []) {
        return ['error' => ['error' => true, 'errorMessage' => 'messages must be a non-empty array of {role, content} objects.']];
    }

    $modelKey = $p['model'] ?? ModelCatalog::DEFAULT_KEY;
    if (!is_string($modelKey) || !ModelCatalog::has($modelKey)) {
        return ['error' => ['error' => true, 'errorMessage' => 'model must be one of: ' . implode(', ', ModelCatalog::keys()) . '.']];
    }

    return ['messages' => $messages, 'modelKey' => $modelKey];
}

/** Shared by /chat and /chat/web: runs the chat agent and records ledger usage either way. Assumes messages/modelKey have already been validated by parseChatRequest(). */
function runChat(array $messages, string $modelKey, ?string $userId): array
{
    $model = ModelCatalog::fireworksModel($modelKey);
    $agent = new ChatAgent(new FireworksClient((string) getenv('FIREWORKS_API_KEY'), $model));
    $pricing = ModelCatalog::pricing($modelKey);
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
            'exceededToolIterations' => $e->exceededToolIterations,
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
}

/** discordOAuthClient() - builds a DiscordOAuth from DISCORD_CLIENT_ID/DISCORD_CLIENT_SECRET/DISCORD_REDIRECT_URI, or null if any is unset (web login not configured on this server). */
function discordOAuthClient(): ?DiscordOAuth
{
    $clientId = getenv('DISCORD_CLIENT_ID');
    $clientSecret = getenv('DISCORD_CLIENT_SECRET');
    $redirectUri = getenv('DISCORD_REDIRECT_URI');
    if (!$clientId || !$clientSecret || !$redirectUri) {
        return null;
    }
    return new DiscordOAuth($clientId, $clientSecret, $redirectUri);
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
    if ($configError = requireFireworksConfigured()) {
        return $configError;
    }

    $parsed = parseChatRequest($p);
    if (isset($parsed['error'])) {
        return $parsed['error'];
    }

    // Optional caller-supplied identifier, purely for usage tracking (see
    // GET /api/v1/balance/usage-by-user) — opaque to this endpoint, not sent to Fireworks.
    $userId = $_SERVER['HTTP_X_USER_ID'] ?? null;
    if ($userId !== null && trim($userId) === '') {
        $userId = null;
    }

    return runChat($parsed['messages'], $parsed['modelKey'], $userId);
});

// Web app's chat proxy (see public/app/) — gated by a Discord login session instead of
// X-Api-Key, since CHAT_API_KEY can't be shipped to a public browser client without anyone
// being able to view-source it and hit paid Fireworks usage directly, unlimited. The logged-in
// Discord user's id is used as the usage-tracking id, the same one MeadBot's X-User-Id header
// uses, so a person's bot and web usage aggregate together in GET /balance/usage-by-user.
$router->post('/api/v1/chat/web', function (array $p) {
    if ($configError = requireFireworksConfigured()) {
        return $configError;
    }

    $discordUser = $_SESSION['discord_user'] ?? null;
    if ($discordUser === null) {
        return ['error' => true, 'errorMessage' => 'Not logged in.', 'requiresLogin' => true];
    }

    $parsed = parseChatRequest($p);
    if (isset($parsed['error'])) {
        return $parsed['error'];
    }

    return runChat($parsed['messages'], $parsed['modelKey'], $discordUser['id']);
});

// Discord OAuth2 login for the web app (see public/app/) — see DiscordOAuth's class doc for why:
// identifying the user is what lets /chat/web track usage per-person without exposing
// CHAT_API_KEY to the browser.
$router->get('/api/v1/auth/discord/login', function () {
    $oauth = discordOAuthClient();
    if ($oauth === null) {
        return ['error' => true, 'errorMessage' => 'Discord login is not configured on this server.'];
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    header('Location: ' . $oauth->authorizeUrl($state));
    exit;
});

$router->get('/api/v1/auth/discord/callback', function (array $p) {
    $oauth = discordOAuthClient();
    if ($oauth === null) {
        return ['error' => true, 'errorMessage' => 'Discord login is not configured on this server.'];
    }

    $expectedState = $_SESSION['oauth_state'] ?? null;
    unset($_SESSION['oauth_state']);
    $state = (string) ($p['state'] ?? '');
    if ($expectedState === null || $state === '' || !hash_equals($expectedState, $state)) {
        return ['error' => true, 'errorMessage' => 'Invalid or expired login attempt -- please try again.'];
    }

    $code = $p['code'] ?? null;
    if (!is_string($code) || $code === '') {
        return ['error' => true, 'errorMessage' => 'Missing authorization code from Discord.'];
    }

    try {
        $accessToken = $oauth->exchangeCode($code);
        $_SESSION['discord_user'] = $oauth->fetchUser($accessToken);
    } catch (\RuntimeException $e) {
        return ['error' => true, 'errorMessage' => $e->getMessage()];
    }

    header('Location: /app/');
    exit;
});

$router->post('/api/v1/auth/logout', function () {
    unset($_SESSION['discord_user']);
    return ['error' => false];
});

$router->get('/api/v1/auth/me', function () {
    $user = $_SESSION['discord_user'] ?? null;
    return ['error' => false, 'loggedIn' => $user !== null, 'user' => $user];
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
