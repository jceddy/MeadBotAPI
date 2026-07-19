# MeadBotAPI

REST API for MeadBot calculator functionality.

This is a PHP port of the pure calculation functions exported by
[`CalculatorAPI.js`](https://github.com/jceddy/MeadBot/blob/main/src/calculator/CalculatorAPI.js)
in the [MeadBot](https://github.com/jceddy/MeadBot) repository — the mead-brewing math (ABV,
calories, Delle number, unit conversions, dry-FG estimation, sugar-source/unit lookups) with no
Discord.js dependency, served over HTTP as JSON.

## Requirements

- PHP 8.1+
- [Composer](https://getcomposer.org/)

## Setup

```
composer install
```

## Running locally

```
composer run serve
```

This starts PHP's built-in server on `http://localhost:8000`, serving `public/` as the document
root — the API under `/api/v1` and interactive docs at `/docs` (see [API docs](#api-docs)
below). For production, point your web server's document root at `public/` (an `.htaccess` is
included for Apache + `mod_rewrite`; for nginx, route unmatched requests to `index.php`).

## Deployment

`.github/workflows/deploy.yml` deploys to production automatically on every push to `main`
(i.e. whenever a PR is merged): it runs the test suite, rebuilds `vendor/` without dev
dependencies, and uploads the repo (minus `.github/`, `tests/`, and VCS files) over FTP to the
server root, using the repository secrets `FTP_HOST`, `FTP_USERNAME`, and `FTP_PASSWORD`. This
assumes the web server's document root points at the `public/` subfolder of that upload
directory, since `public/index.php` loads `vendor/` and `src/` via paths relative to itself.

## API docs

`public/docs/openapi.yaml` is an OpenAPI 3.0 spec covering every endpoint below, and
`public/docs/index.html` renders it as interactive Swagger UI at `/docs` — since it lives under
`public/`, it's served automatically by `composer run serve` locally and is reachable at
`/docs` on the deployed API too. The `swagger-ui-dist` assets are vendored under
`public/docs/vendor/swagger-ui/` (not loaded from a CDN), so the docs page works offline and
isn't subject to a third party's availability.

## Tests

```
composer test
```

Test cases assert parity with reference values captured by running the original
`CalculatorAPI.js` under Node.js.

## API

All endpoints live under `/api/v1` and return JSON. Parameters can be supplied as a JSON request
body (`Content-Type: application/json`), as query-string parameters, or (for path parameters) in
the URL itself — path parameters take precedence, then JSON body, then query string.

Responses that represent a calculation result always include an `error` boolean. When `error` is
`true`, the response also includes `errorMessage`, and usually `errorArgument`,
`errorArgumentPosition`, `errorType` (numeric code), and `errorTypeLabel` (string). The HTTP
status code is `400` whenever `error` is `true`, `404` for an unknown route, `405` for a known
route called with the wrong HTTP method, and `200` otherwise.

| Method | Path | Body / query params | Mirrors |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | — | — |
| POST | `/api/v1/calories` | `percentAlcohol`, `fg`, `bottleVolume`, `servingVolume` | `CalculateCalories` |
| POST | `/api/v1/abv` | `og`, `fg` (optional — estimated "dry" FG is used if omitted) | `CalculateABV` |
| POST | `/api/v1/gravity-drop-to-abv` | `sgDelta` | `ConvertGravityDropToABV` |
| POST | `/api/v1/dry-fg` | `og` | `EstimateDryFG` |
| GET | `/api/v1/volume-units/{name}` | — | `GetVolumeUnit` |
| POST | `/api/v1/volume/convert` | `amount`, `fromUnit`, `toUnit` | `ConvertVolume` |
| GET | `/api/v1/honey-units/{name}` | — | `GetHoneyUnit` |
| POST | `/api/v1/honey/convert` | `amount`, `fromUnit`, `toUnit` | `ConvertHoneyUnits` |
| POST | `/api/v1/temperature/convert` | `fromTemperature`, `fromUnit` (`c`/`celcius`/`f`/`fahrenheit`) | `ConvertTemperature` |
| POST | `/api/v1/sg-to-brix` | `sg` | `ConvertSGToBrix` |
| POST | `/api/v1/delle` | `abv`, `sg` | `ComputeDelle` |
| POST | `/api/v1/potential-alcohol` | `gravityUnits`, `abvUnits`, and at least one of `og`/`fg`/`abv` (see [docs](#api-docs) for the solve priority) | `!potential-alcohol`\* |
| POST | `/api/v1/calculate-blend` | `fieldToCalculate` and 4-5 of `value1`/`value2`/`blendedValue`/`volume1`/`volume2`/`totalVolume` (see [docs](#api-docs)) | `!calculate-blend` |
| POST | `/api/v1/calculate-nutrients` | All optional — `units`, `volume`, `yan`, and various nutrient-limit/ratio overrides (see [docs](#api-docs)) | `!calculate-nutrients` |
| GET | `/api/v1/sugar-sources/{name}` | — | `GetSugarSourceIdentifier` |
| POST | `/api/v1/dates/days-between` | `date1`, `date2` (parseable date/time strings) | `GetDaysBetween` |
| POST | `/api/v1/dates/months-between` | `date1`, `date2`, `roundUpFractionalMonths` (optional bool) | `GetMonthsBetween` |
| GET | `/api/v1/random` | `max` | `RandomInteger` |
| POST | `/api/v1/hours-string` | `timing`, `break3` (required only when `timing` is `"break"`) | `MakeHoursString` |

\* `/potential-alcohol` mirrors the *intent* of MeadBot's `!potential-alcohol` command rather
than its exact behavior: that command has two known bugs (a specified value that happens to
equal its default is silently ignored, and BRIX/BAUME inputs aren't converted to SG before use
in two of its three solve branches) which are preserved as-is in the MeadBot repo for backward
compatibility, but corrected here since this endpoint has no existing consumers to break.

### Examples

```
curl -s http://localhost:8000/api/v1/abv \
  -H 'Content-Type: application/json' \
  -d '{"og": 1.100, "fg": 1.000}'
# {"error":false,"og":1.1,"fg":1,"abv":13.187}

curl -s "http://localhost:8000/api/v1/volume-units/gallon"
# {"error":false,"unitId":1}

curl -s http://localhost:8000/api/v1/volume/convert \
  -H 'Content-Type: application/json' \
  -d '{"amount": 1, "fromUnit": "gallon", "toUnit": "liters"}'
# {"error":false,"fromAmount":1,"fromUnit":{"name":"Gallon(s) US","conversion":3.7854117891},"toAmount":3.7854117891,"toUnit":{"name":"Liter(s)","conversion":1}}
```

## Project structure

- `public/index.php` - front controller; defines all routes and maps HTTP params onto
  `CalculatorApi` calls.
- `src/Calculator/CalculatorApi.php` - the ported calculator methods.
- `src/Calculator/GravityCalculator.php` - gravity/ABV unit conversions and the
  `potentialAlcohol` solver, ported from `GravityCalculator.js`.
- `src/Calculator/BlendCalculator.php` - the two-liquid blend solver, ported from
  `BlendCalculator.js`.
- `src/Calculator/NutrientCalculator.php` - the SNA nutrient-schedule solver, ported from
  `NutrientCalculator.js`.
- `src/Calculator/Constants.php` - unit tables, error-type codes, and sugar-source data, ported
  from `CalculatorAPI.Constants.js`.
- `src/Http/Router.php` - a minimal method+path router used by `public/index.php`.
- `tests/` - PHPUnit tests, run with `composer test`.
- `public/docs/` - OpenAPI spec and Swagger UI, served at `/docs`.
