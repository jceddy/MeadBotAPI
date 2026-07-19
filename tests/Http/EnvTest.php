<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Http;

use MeadBotApi\Http\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private ?string $tmpFile = null;

    protected function tearDown(): void
    {
        if ($this->tmpFile !== null && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        foreach (['ENV_TEST_A', 'ENV_TEST_B', 'ENV_TEST_QUOTED', 'ENV_TEST_ALREADY_SET'] as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    private function writeEnvFile(string $contents): string
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'meadbotapi-env-test-');
        file_put_contents($this->tmpFile, $contents);
        return $this->tmpFile;
    }

    public function testLoadsSimpleKeyValuePairs(): void
    {
        $path = $this->writeEnvFile("ENV_TEST_A=hello\nENV_TEST_B=42\n");
        Env::load($path);

        self::assertSame('hello', getenv('ENV_TEST_A'));
        self::assertSame('42', getenv('ENV_TEST_B'));
        self::assertSame('hello', $_ENV['ENV_TEST_A']);
    }

    public function testStripsSurroundingQuotesAndIgnoresCommentsAndBlankLines(): void
    {
        $path = $this->writeEnvFile("# a comment\n\nENV_TEST_QUOTED=\"has spaces\"\n");
        Env::load($path);

        self::assertSame('has spaces', getenv('ENV_TEST_QUOTED'));
    }

    public function testDoesNotOverwriteAnAlreadySetEnvironmentVariable(): void
    {
        putenv('ENV_TEST_ALREADY_SET=from-real-environment');
        $path = $this->writeEnvFile("ENV_TEST_ALREADY_SET=from-dotenv\n");
        Env::load($path);

        self::assertSame('from-real-environment', getenv('ENV_TEST_ALREADY_SET'));
    }

    public function testIsANoOpWhenTheFileDoesNotExist(): void
    {
        Env::load('/nonexistent/path/.env');
        self::assertFalse(getenv('ENV_TEST_A'));
    }
}
