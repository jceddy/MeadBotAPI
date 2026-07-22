<?php

declare(strict_types=1);

namespace MeadBotApi\Auth;

use RuntimeException;

/**
 * Minimal Discord OAuth2 client (https://discord.com/developers/docs/topics/oauth2) for the web
 * app's "Login with Discord" flow. Used only to identify the logged-in user (their Discord
 * snowflake ID) -- see public/index.php's /api/v1/chat/web route -- so web chat usage can be
 * tagged with the same id space MeadBot's X-User-Id header already uses, letting one person's bot
 * and web usage aggregate together in GET /balance/usage-by-user. Uses cURL directly, matching
 * FireworksClient's zero-runtime-deps style.
 */
final class DiscordOAuth
{
    private const AUTHORIZE_URL = 'https://discord.com/api/oauth2/authorize';
    private const TOKEN_URL = 'https://discord.com/api/oauth2/token';
    private const USER_URL = 'https://discord.com/api/users/@me';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    /** @var callable(string, array<int, string>, string, string): array{status: int, body: string} */
    private $transport;

    /**
     * @param callable(string, array<int, string>, string, string): array{status: int, body: string}|null $transport
     *   Injectable HTTP transport for testing -- takes (url, headers, method, body), returns
     *   {status, body}. Defaults to a real cURL request.
     */
    public function __construct(string $clientId, string $clientSecret, string $redirectUri, ?callable $transport = null)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->transport = $transport ?? self::curlTransport(...);
    }

    /** authorizeUrl(state) - the URL to send the user's browser to start the login flow. */
    public function authorizeUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'identify',
            'state' => $state,
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * exchangeCode(code) - trades the callback's authorization code for an access token.
     * Throws RuntimeException on any failure.
     */
    public function exchangeCode(string $code): string
    {
        $body = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $response = ($this->transport)(
            self::TOKEN_URL,
            ['Content-Type: application/x-www-form-urlencoded'],
            'POST',
            $body
        );

        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || !isset($decoded['access_token'])) {
            throw new RuntimeException("Discord token exchange failed (HTTP {$response['status']}).");
        }

        return (string) $decoded['access_token'];
    }

    /**
     * fetchUser(accessToken) - the logged-in Discord user's id and username.
     * Throws RuntimeException on any failure.
     *
     * @return array{id: string, username: string}
     */
    public function fetchUser(string $accessToken): array
    {
        $response = ($this->transport)(self::USER_URL, ['Authorization: Bearer ' . $accessToken], 'GET', '');

        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($decoded) || !isset($decoded['id'])) {
            throw new RuntimeException("Failed to fetch Discord user profile (HTTP {$response['status']}).");
        }

        return ['id' => (string) $decoded['id'], 'username' => (string) ($decoded['username'] ?? 'unknown')];
    }

    /** @return array{status: int, body: string} */
    private static function curlTransport(string $url, array $headers, string $method, string $body): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Discord API request failed: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $raw];
    }
}
