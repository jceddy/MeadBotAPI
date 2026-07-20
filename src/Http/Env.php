<?php

declare(strict_types=1);

namespace MeadBotApi\Http;

/**
 * A minimal .env loader — no external dependency, matching this project's zero-runtime-deps
 * style. Parses simple KEY=VALUE lines (optionally quoted with single or double quotes; #-led
 * lines and blank lines are ignored) and exposes them via getenv()/$_ENV, without overwriting a
 * variable the environment already provides. Deployment writes this file from GitHub Actions
 * secrets (see .github/workflows/deploy.yml); locally, copy it to a gitignored `.env` at the repo
 * root for `composer run serve`.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name === '') {
                continue;
            }

            if (strlen($value) >= 2 && (
                ($value[0] === '"' && str_ends_with($value, '"')) ||
                ($value[0] === "'" && str_ends_with($value, "'"))
            )) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
    }
}
