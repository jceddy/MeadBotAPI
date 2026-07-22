<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Auth;

use MeadBotApi\Auth\DiscordOAuth;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DiscordOAuthTest extends TestCase
{
    public function testAuthorizeUrlIncludesClientIdRedirectUriAndState(): void
    {
        $oauth = new DiscordOAuth('client-123', 'secret', 'https://api.example.com/api/v1/auth/discord/callback');

        $url = $oauth->authorizeUrl('random-state-value');

        self::assertStringStartsWith('https://discord.com/api/oauth2/authorize?', $url);
        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('response_type=code', $url);
        self::assertStringContainsString('scope=identify', $url);
        self::assertStringContainsString('state=random-state-value', $url);
        self::assertStringContainsString(rawurlencode('https://api.example.com/api/v1/auth/discord/callback'), $url);
    }

    public function testExchangeCodePostsToTheTokenEndpointAndReturnsTheAccessToken(): void
    {
        $capturedUrl = null;
        $capturedHeaders = null;
        $capturedMethod = null;
        $capturedBody = null;

        $transport = function (string $url, array $headers, string $method, string $body) use (
            &$capturedUrl,
            &$capturedHeaders,
            &$capturedMethod,
            &$capturedBody
        ) {
            $capturedUrl = $url;
            $capturedHeaders = $headers;
            $capturedMethod = $method;
            $capturedBody = $body;
            return ['status' => 200, 'body' => json_encode(['access_token' => 'the-access-token'])];
        };

        $oauth = new DiscordOAuth('client-123', 'shh', 'https://api.example.com/callback', $transport);
        $token = $oauth->exchangeCode('the-code');

        self::assertSame('the-access-token', $token);
        self::assertSame('https://discord.com/api/oauth2/token', $capturedUrl);
        self::assertSame('POST', $capturedMethod);
        self::assertContains('Content-Type: application/x-www-form-urlencoded', $capturedHeaders);

        parse_str($capturedBody, $fields);
        self::assertSame('client-123', $fields['client_id']);
        self::assertSame('shh', $fields['client_secret']);
        self::assertSame('authorization_code', $fields['grant_type']);
        self::assertSame('the-code', $fields['code']);
        self::assertSame('https://api.example.com/callback', $fields['redirect_uri']);
    }

    public function testExchangeCodeThrowsOnANonSuccessStatus(): void
    {
        $transport = fn () => ['status' => 400, 'body' => json_encode(['error' => 'invalid_grant'])];
        $oauth = new DiscordOAuth('id', 'secret', 'https://example.com/callback', $transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 400/');
        $oauth->exchangeCode('bad-code');
    }

    public function testExchangeCodeThrowsWhenTheResponseHasNoAccessToken(): void
    {
        $transport = fn () => ['status' => 200, 'body' => json_encode(['not' => 'what we expected'])];
        $oauth = new DiscordOAuth('id', 'secret', 'https://example.com/callback', $transport);

        $this->expectException(RuntimeException::class);
        $oauth->exchangeCode('code');
    }

    public function testFetchUserSendsTheBearerTokenAndReturnsIdAndUsername(): void
    {
        $capturedUrl = null;
        $capturedHeaders = null;

        $transport = function (string $url, array $headers) use (&$capturedUrl, &$capturedHeaders) {
            $capturedUrl = $url;
            $capturedHeaders = $headers;
            return ['status' => 200, 'body' => json_encode(['id' => '123456789012345678', 'username' => 'meadmaker'])];
        };

        $oauth = new DiscordOAuth('id', 'secret', 'https://example.com/callback', $transport);
        $user = $oauth->fetchUser('the-access-token');

        self::assertSame(['id' => '123456789012345678', 'username' => 'meadmaker'], $user);
        self::assertSame('https://discord.com/api/users/@me', $capturedUrl);
        self::assertContains('Authorization: Bearer the-access-token', $capturedHeaders);
    }

    public function testFetchUserDefaultsUsernameToUnknownWhenAbsent(): void
    {
        $transport = fn () => ['status' => 200, 'body' => json_encode(['id' => '42'])];
        $oauth = new DiscordOAuth('id', 'secret', 'https://example.com/callback', $transport);

        $user = $oauth->fetchUser('token');

        self::assertSame(['id' => '42', 'username' => 'unknown'], $user);
    }

    public function testFetchUserThrowsOnANonSuccessStatus(): void
    {
        $transport = fn () => ['status' => 401, 'body' => json_encode(['message' => '401: Unauthorized'])];
        $oauth = new DiscordOAuth('id', 'secret', 'https://example.com/callback', $transport);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 401/');
        $oauth->fetchUser('bad-token');
    }
}
