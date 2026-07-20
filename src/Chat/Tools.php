<?php

declare(strict_types=1);

namespace MeadBotApi\Chat;

use InvalidArgumentException;
use MeadBotApi\Http\Operations;

/**
 * Exposes most of MeadBotAPI's calculation/lookup operations (see Http\Operations) as
 * OpenAI-style function-calling tools for the chat agent. `/health` and `/random` are omitted —
 * neither is useful for an LLM to call. Tool parameter schemas mirror the corresponding REST
 * endpoint's request body in public/docs/openapi.yaml (keep them in sync if that changes);
 * dispatch just forwards the model's arguments straight to the same Operations method the REST
 * route calls, so behavior is identical either way.
 */
final class Tools
{
    /** Tool name => Http\Operations method name. */
    private const TOOL_TO_OPERATION = [
        'calculate_calories' => 'calculateCalories',
        'calculate_abv' => 'calculateABV',
        'convert_gravity_drop_to_abv' => 'convertGravityDropToABV',
        'estimate_dry_fg' => 'estimateDryFG',
        'list_volume_units' => 'listVolumeUnits',
        'get_volume_unit' => 'getVolumeUnit',
        'convert_volume' => 'convertVolume',
        'get_honey_unit' => 'getHoneyUnit',
        'convert_honey_units' => 'convertHoneyUnits',
        'convert_temperature' => 'convertTemperature',
        'convert_sg_to_brix' => 'convertSGToBrix',
        'compute_delle' => 'computeDelle',
        'potential_alcohol' => 'potentialAlcohol',
        'calculate_blend' => 'calculateBlend',
        'calculate_nutrients' => 'calculateNutrients',
        'build_batch' => 'buildBatch',
        'calculate_mead' => 'calculateMead',
        'list_yeast_requirements' => 'listYeastRequirements',
        'get_sugar_source' => 'getSugarSourceIdentifier',
        'get_days_between' => 'getDaysBetween',
        'get_months_between' => 'getMonthsBetween',
        'make_hours_string' => 'makeHoursString',
    ];

    /**
     * call(name, arguments) - dispatch a tool call by name to the matching Http\Operations
     * method. Throws InvalidArgumentException for an unknown tool name; parameter validation
     * errors from the operation itself also surface as InvalidArgumentException (callers should
     * catch this and feed the message back to the model as the tool result, rather than failing
     * the whole chat request over one bad tool call).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function call(string $name, array $arguments): array
    {
        if (!isset(self::TOOL_TO_OPERATION[$name])) {
            throw new InvalidArgumentException("Unknown tool: {$name}");
        }
        return call_user_func([Operations::class, self::TOOL_TO_OPERATION[$name]], $arguments);
    }

    /**
     * definitions() - the OpenAI/Fireworks "tools" array to send with every chat completion.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::tool(
                'calculate_calories',
                'Estimate the caloric content of a beverage.',
                ['percentAlcohol', 'fg', 'bottleVolume', 'servingVolume'],
                [
                    'percentAlcohol' => self::number('Percentage of alcohol by volume, e.g. 11.5 for 11.5% ABV. Range 0-100.'),
                    'fg' => self::number('Final/specific gravity of the beverage. Range 0.99-1.2.'),
                    'bottleVolume' => self::number('Milliliters in a bottle of the beverage. Range 100-5000.'),
                    'servingVolume' => self::number('Milliliters in a serving of the beverage. Range 10-1000.'),
                ]
            ),
            self::tool(
                'calculate_abv',
                'Calculate estimated %ABV from an original and final gravity. If fg is omitted, an estimated "dry" FG is used.',
                ['og'],
                [
                    'og' => self::number('Original gravity. Range 0.99-1.4.'),
                    'fg' => self::number('Final gravity. Range 0.99-1.2. Omit to estimate a "dry" FG.'),
                ]
            ),
            self::tool(
                'convert_gravity_drop_to_abv',
                'Convert a gravity drop (e.g. OG - FG + 1) to a %ABV estimate.',
                ['sgDelta'],
                ['sgDelta' => self::number('The gravity drop.')]
            ),
            self::tool(
                'estimate_dry_fg',
                'Estimate the "dry" final gravity from an original gravity.',
                ['og'],
                ['og' => self::number('Original gravity.')]
            ),
            self::tool(
                'list_volume_units',
                'List all recognized volume unit names alongside their display name.',
                [],
                []
            ),
            self::tool(
                'get_volume_unit',
                'Look up a volume unit identifier by name.',
                ['name'],
                ['name' => self::string('Volume unit name, e.g. gallon, liters, quart.')]
            ),
            self::tool(
                'convert_volume',
                'Convert a volume from one unit to another.',
                ['amount', 'fromUnit', 'toUnit'],
                [
                    'amount' => self::number('Amount to convert.'),
                    'fromUnit' => self::string('Unit name to convert from, e.g. gallon.'),
                    'toUnit' => self::string('Unit name to convert to, e.g. liters.'),
                ]
            ),
            self::tool(
                'get_honey_unit',
                'Look up a honey unit identifier by name.',
                ['name'],
                ['name' => self::string('Honey unit name, e.g. kg, lbs, cups.')]
            ),
            self::tool(
                'convert_honey_units',
                'Convert an amount of honey from one unit to another.',
                ['amount', 'fromUnit', 'toUnit'],
                [
                    'amount' => self::number('Amount to convert.'),
                    'fromUnit' => self::string('Unit name to convert from, e.g. kg.'),
                    'toUnit' => self::string('Unit name to convert to, e.g. lbs.'),
                ]
            ),
            self::tool(
                'convert_temperature',
                'Convert a temperature between Celsius and Fahrenheit.',
                ['fromTemperature', 'fromUnit'],
                [
                    'fromTemperature' => self::number('Temperature value to convert.'),
                    'fromUnit' => self::enumString(['c', 'celcius', 'f', 'fahrenheit'], 'The unit fromTemperature is expressed in.'),
                ]
            ),
            self::tool(
                'convert_sg_to_brix',
                'Convert a specific gravity to BRIX.',
                ['sg'],
                ['sg' => self::number('Specific gravity.')]
            ),
            self::tool(
                'compute_delle',
                'Compute an estimated Delle number from a %ABV and specific gravity.',
                ['abv', 'sg'],
                ['abv' => self::number('Percent alcohol by volume.'), 'sg' => self::number('Specific gravity.')]
            ),
            self::tool(
                'potential_alcohol',
                'Given up to two of {og, fg, abv}, solve for whichever value(s) are needed to produce a consistent '
                    . 'og/fg/abv trio: if og and abv are both given, fg is solved; if only og is given (optionally '
                    . 'with fg), abv is solved; otherwise og is solved from fg and abv. At least one of og, fg, abv '
                    . 'must be given.',
                [],
                [
                    'gravityUnits' => self::enumString(['sg', 'brix', 'baume'], 'Units og/fg are expressed in. Defaults to sg.'),
                    'abvUnits' => self::enumString(['abv', 'abw'], 'Units abv is expressed in. Defaults to abv.'),
                    'og' => self::number('Original gravity, in gravityUnits.'),
                    'fg' => self::number('Final gravity, in gravityUnits.'),
                    'abv' => self::number('Alcohol content, in abvUnits.'),
                ]
            ),
            self::tool(
                'calculate_blend',
                'Given any four (or five) of {value1, value2, blendedValue, volume1, volume2, totalVolume}, solve '
                    . 'for fieldToCalculate.',
                ['fieldToCalculate'],
                [
                    'fieldToCalculate' => self::enumString(
                        ['value1', 'value2', 'blended_value', 'volume1', 'volume2', 'total_volume'],
                        'Which field to solve for.'
                    ),
                    'value1' => self::number("The first liquid's value (gravity/ABV/etc)."),
                    'value2' => self::number("The second liquid's value."),
                    'blendedValue' => self::number('The value after blending.'),
                    'volume1' => self::number('Volume of the liquid with value1.'),
                    'volume2' => self::number('Volume of the liquid with value2.'),
                    'totalVolume' => self::number('volume1 + volume2.'),
                ]
            ),
            self::tool(
                'calculate_nutrients',
                'Compute a staggered-nutrient-addition (SNA) schedule (Fermaid O / Fermaid K / DAP, staggered '
                    . 'across 24/48/72 hours plus the 1/3 sugar break) for a target YAN. All parameters optional.',
                [],
                [
                    'units' => self::enumString(['us', 'metric'], 'Defaults to us.'),
                    'volume' => self::number('Must volume, in gallons (us) or liters (metric). Defaults to 5 (us) or 18.9 (metric).'),
                    'yan' => self::number('Target total YAN (ppm). Defaults to 175.'),
                    'fermOEffectiveness' => self::number('Defaults to 2.6.'),
                    'enforceLimits' => self::boolean('Defaults to true.'),
                    'dapLimit' => self::number('g/L. Defaults to 0.96.'),
                    'fermKLimit' => self::number('g/L. Defaults to 0.5.'),
                    'fermOLimit' => self::number('g/L. Defaults to 0.45.'),
                    'yanRatioDap' => self::number('Used only when enforceLimits is false. Defaults to 35.'),
                    'yanRatioFermK' => self::number('Used only when enforceLimits is false. Defaults to 25.'),
                    'yanRatioFermO' => self::number('Used only when enforceLimits is false. Defaults to 40.'),
                    'fermKYan' => self::number('ppm YAN provided by the Fermaid K product in use. Defaults to 134.'),
                    'fillFkFirst' => self::boolean('Defaults to true.'),
                    'gofermYan' => self::number('ppm YAN provided by the Go-Ferm product in use. Defaults to 77.'),
                    'gofermGrams' => self::number('Grams of Go-Ferm already used for rehydration. Defaults to 0.'),
                ]
            ),
            self::tool(
                'build_batch',
                'Build a full recipe (honey weight, yeast/Go-Ferm, nutrients) for a target batch: resolves a '
                    . 'target OG/FG/ABV, picks an SNA schedule, applies fruit/grain YAN contribution, and splits '
                    . 'nutrients across the selected nutrientRegimen. All parameters optional.',
                [],
                [
                    'units' => self::enumString(['us', 'metric'], 'Defaults to us.'),
                    'volume' => self::number('Batch volume, in gallons (us) or liters (metric). Defaults to 5 (us) or 18.9 (metric).'),
                    'yeastAbv' => self::number("The yeast's expected %ABV tolerance. Defaults to 18."),
                    'residualSugar' => self::number('Target FG. Ignored if ogOverride is set. Defaults to 1.02.'),
                    'yanRequirement' => self::enumString(['very_low', 'low', 'medium', 'high', 'kveik'], 'Defaults to medium.'),
                    'nutrientRegimen' => self::enumString(
                        ['tosna', 'k_dap', 'blount_elliott', 'tosna_k', 'o_k', 'advanced'],
                        'Defaults to blount_elliott.'
                    ),
                    'ogOverride' => self::number('Target OG. If set, overrides residualSugar.'),
                    'pitchRateOverride' => self::number('Yeast g/gallon (us) or g/L (metric), overriding the normal Go-Ferm calculation.'),
                    'fruitSg' => self::number('Gravity contribution from fruit/grains. Must not exceed the resolved OG.'),
                    'yanOverride' => self::number('Target total YAN (ppm). Used only for the advanced regimen.'),
                    'fermOEffectiveness' => self::number('Defaults to 2.6. Only meaningful for the advanced regimen.'),
                    'enforceLimits' => self::boolean('Defaults to true. Only meaningful for the advanced regimen.'),
                    'dapLimit' => self::number('g/L. Defaults to 0.96. Only meaningful for the advanced regimen.'),
                    'fermKLimit' => self::number('g/L. Defaults to 0.5. Only meaningful for the advanced regimen.'),
                    'fermOLimit' => self::number('g/L. Defaults to 0.45. Only meaningful for the advanced regimen.'),
                    'yanRatioDap' => self::number('Defaults to 35. Only meaningful for the advanced regimen.'),
                    'yanRatioFermK' => self::number('Defaults to 25. Only meaningful for the advanced regimen.'),
                    'yanRatioFermO' => self::number('Defaults to 40. Only meaningful for the advanced regimen.'),
                    'fermKYan' => self::number('ppm YAN provided by the Fermaid K product in use. Defaults to 134.'),
                    'gofermYan' => self::number('ppm YAN provided by the Go-Ferm product in use. Defaults to 77.'),
                    'fillFkFirst' => self::boolean('Defaults to true. Only meaningful for the advanced regimen.'),
                    'hot' => self::boolean('Whether fermenting hot. Affects the default SNA schedule. Defaults to false.'),
                    'snaScheduleOverride' => [
                        'type' => 'array',
                        'description' => 'Explicit SNA schedule overriding the default. Each element is an hour '
                            . 'count (1-500), "break", or "pitch" (only as the first element).',
                        'items' => ['type' => ['number', 'string']],
                    ],
                ]
            ),
            self::tool(
                'calculate_mead',
                'Build a full recipe from a target gravity, volume, and/or ABV: supply any two of targetGravity, '
                    . 'targetVolume, targetAbv, and the third is solved for; supports optional step feeding via '
                    . 'targetStepFeedGravity and fruit/other-sugar YAN contribution via additionalSugars. All '
                    . 'parameters optional.',
                [],
                [
                    'units' => self::enumString(['us', 'metric', 'imperial'], 'Defaults to us.'),
                    'mustTemperature' => self::number('Defaults to 68 (us/imperial) or 20 (metric).'),
                    'mustTemperatureUnits' => self::enumString(['celsius', 'fahrenheit'], 'Defaults to fahrenheit (us/imperial) or celsius (metric).'),
                    'targetGravity' => self::number('Defaults to 1.108 when neither targetVolume nor targetAbv resolve it.'),
                    'targetGravityUnits' => self::enumString(['sg', 'brix', 'baume'], 'Defaults to sg.'),
                    'targetAbv' => self::number('Defaults to 14.13 when not resolved from targetGravity/targetStepFeedGravity.'),
                    'targetAbvUnits' => self::enumString(['abv', 'abw'], 'Defaults to abv.'),
                    'targetVolume' => self::number('Defaults to 5 (us), 18.93 (metric), or 5 (imperial gallons).'),
                    'targetVolumeUnits' => self::string('Volume unit name. Defaults to gallons_us/liters/gallons_imp matching units.'),
                    'additionalSugars' => [
                        'type' => 'array',
                        'description' => 'Additional fruit/sugar sources contributing to gravity and/or YAN. At '
                            . 'most one entry may omit quantityAmount (it will be solved for); entries with '
                            . 'additive=true must specify quantityAmount.',
                        'items' => [
                            'type' => 'object',
                            'required' => ['type', 'quantityUnits'],
                            'properties' => [
                                'type' => self::string('Sugar source name, e.g. honey, blueberry, dried_apricots.'),
                                'quantityAmount' => self::number('Omit to have this sugar\'s quantity solved for.'),
                                'quantityUnits' => self::string('Honey/weight unit name, e.g. lbs.'),
                                'sugarContent' => self::number("Percent sugar by weight. Defaults to the sugar source's known value."),
                                'yanMultiplier' => self::number("Defaults to the sugar source's known value."),
                                'additive' => self::boolean('If true, this sugar is added on top of targetVolume rather than being part of it. Defaults to false.'),
                            ],
                        ],
                    ],
                    'currentGravity' => self::number('Defaults to 1.0.'),
                    'currentGravityUnits' => self::enumString(['sg', 'brix', 'baume'], 'Defaults to sg.'),
                    'currentVolume' => self::number('Defaults to 0.'),
                    'currentVolumeUnits' => self::string('Volume unit name. Defaults to gallons_us/liters/gallons_imp matching units.'),
                    'targetStepFeedGravity' => self::number('If set, must be greater than the resolved targetGravity; enables step feeding.'),
                    'yeastAbv' => self::number("The yeast's expected %ABV tolerance. Defaults to 18."),
                    'yanRequirement' => self::enumString(['very_low', 'low', 'medium', 'high', 'kveik'], 'Defaults to medium.'),
                    'hot' => self::boolean('Whether fermenting hot. Defaults to false.'),
                    'calculateAdditiveHoney' => self::boolean(
                        'When no additionalSugars are given and both targetGravity and targetVolume are '
                            . 'specified, solve for how much honey to add on top of targetVolume instead of how '
                            . 'much of targetVolume should be honey. Defaults to false.'
                    ),
                    'fermOEffectiveness' => self::number('Defaults to 2.6.'),
                    'enforceLimits' => self::boolean('Defaults to true.'),
                    'dapLimit' => self::number('g/L. Defaults to 0.96.'),
                    'fermKLimit' => self::number('g/L. Defaults to 0.5.'),
                    'fermOLimit' => self::number('g/L. Defaults to 0.45.'),
                    'yanRatioDap' => self::number('Defaults to 35.'),
                    'yanRatioFermK' => self::number('Defaults to 25.'),
                    'yanRatioFermO' => self::number('Defaults to 40.'),
                    'fermKYan' => self::number('ppm YAN provided by the Fermaid K product in use. Defaults to 134.'),
                    'gofermYan' => self::number('ppm YAN provided by the Go-Ferm product in use. Defaults to 77.'),
                    'fillFkFirst' => self::boolean('Defaults to true.'),
                    'useGoferm' => self::boolean('Defaults to true.'),
                    'yeastPackGrams' => self::number('Defaults to 5.'),
                ]
            ),
            self::tool(
                'list_yeast_requirements',
                'List known yeasts and their YAN (yeast assimilable nitrogen) requirement.',
                [],
                []
            ),
            self::tool(
                'get_sugar_source',
                'Look up a sugar source identifier by name, along with its sugar content percent and YAN multiplier.',
                ['name'],
                ['name' => self::string('Sugar source name, e.g. honey, blueberry, dried_apricots.')]
            ),
            self::tool(
                'get_days_between',
                'Calculate the number of full days between two dates.',
                ['date1', 'date2'],
                [
                    'date1' => self::string('First date/time, e.g. 2024-01-01T00:00:00Z.'),
                    'date2' => self::string('Second date/time.'),
                ]
            ),
            self::tool(
                'get_months_between',
                'Calculate the number of months between two dates.',
                ['date1', 'date2'],
                [
                    'date1' => self::string('First date/time, e.g. 2024-01-01T00:00:00Z.'),
                    'date2' => self::string('Second date/time.'),
                    'roundUpFractionalMonths' => self::boolean('If true, a fractional trailing month rounds up. Defaults to false.'),
                ]
            ),
            self::tool(
                'make_hours_string',
                'Build a human-readable string from SNA (staggered nutrient addition) timing information, e.g. '
                    . '"24 Hours after pitch" or "1/3 Sugar Break".',
                ['timing'],
                [
                    'timing' => self::string('"pitch", "break", an "hours,additionIndex" pair (e.g. "24,1"), or a plain number of hours.'),
                    'break3' => self::number('The 1/3 sugar break SG. Required only when timing is "break".'),
                ]
            ),
        ];
    }

    /**
     * @param array<int, string> $required
     * @param array<string, array<string, mixed>> $properties
     * @return array<string, mixed>
     */
    private static function tool(string $name, string $description, array $required, array $properties): array
    {
        $parameters = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $parameters['required'] = $required;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function number(string $description): array
    {
        return ['type' => 'number', 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function string(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function boolean(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @param array<int, string> $values
     * @return array<string, mixed>
     */
    private static function enumString(array $values, string $description): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description];
    }
}
