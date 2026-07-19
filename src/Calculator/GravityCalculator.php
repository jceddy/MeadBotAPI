<?php

declare(strict_types=1);

namespace MeadBotApi\Calculator;

/**
 * Ported from MeadBot's src/calculator/GravityCalculator.js — gravity/ABV unit conversions,
 * plus a corrected potentialAlcohol() orchestration (see its docblock for how it differs from
 * MeadBot's !potential-alcohol command).
 */
final class GravityCalculator
{
    public static function brixToSG(float $brix): float
    {
        return 1.00001 + $brix / (258.6 - 0.89 * $brix);
    }

    public static function baumeToSG(float $baume): float
    {
        return 145 / (145 - $baume);
    }

    public static function abvToSG(float $abv): float
    {
        return self::baumeToSG($abv);
    }

    public static function sgToBaume(float $sg): float
    {
        return 145 - 145 / $sg;
    }

    public static function sgToABV(float $sg): float
    {
        return self::sgToBaume($sg);
    }

    public static function abvToABW(float $abv): float
    {
        return $abv * 0.794;
    }

    public static function abwToABV(float $abw): float
    {
        return $abw / 0.794;
    }

    public static function sgToABW(float $sg): float
    {
        return self::abvToABW(self::sgToBaume($sg));
    }

    public static function abwToSG(float $abw): float
    {
        return self::abvToSG(self::abwToABV($abw));
    }

    // convert a gravity value expressed in the given units to plain SG
    public static function convToSG(float $value, int $units): float
    {
        return match ($units) {
            Constants::GRAVITY_UNIT_BRIX => self::brixToSG($value),
            Constants::GRAVITY_UNIT_BAUME => self::baumeToSG($value),
            default => $value,
        };
    }

    // convert an SG value to the given gravity units
    public static function doConvertSG(int $fromUnits, int $toUnits, float $gravity): float
    {
        $sg = self::convToSG($gravity, $fromUnits);

        return match ($toUnits) {
            Constants::GRAVITY_UNIT_BRIX => CalculatorApi::convertSGToBrix($sg),
            Constants::GRAVITY_UNIT_BAUME => self::sgToBaume($sg),
            default => $sg,
        };
    }

    // convert a volume value in the given units to liters
    public static function toVol(int $units, float $volume): float
    {
        return $volume * Constants::VOLUME_UNIT_INFO[$units]['conversion'];
    }

    public static function getTempCoeff(float $temp, int $units): float
    {
        $celsius = $temp;
        if ($units === Constants::TEMPERATURE_UNIT_FAHRENHEIT) {
            $celsius = (5 * ($celsius - 32)) / 9;
        }
        return -0.00469615 + 0.000377273 * $celsius - 0.0000117144 * $celsius ** 2 + 0.000000229558 * $celsius ** 3;
    }

    // sugar concentration (g/L) corresponding to a given SG
    public static function sgToSugarConc(float $sg): float
    {
        return 4 + 10 * $sg * CalculatorApi::convertSGToBrix($sg);
    }

    // SG corresponding to a given sugar concentration (g/L), via numeric search
    public static function sugarConcToSG(float $gPerL): float
    {
        $sg = 0.992;
        $loopLimit = 100000;

        for ($i = 0; $i < $loopLimit; $i++) {
            $gpl = self::sgToSugarConc($sg);
            if (round($gpl) === round($gPerL)) {
                return $sg < 1 ? 1.0 : $sg;
            }
            $diff = 10 ** (-1 * (2 + strlen((string) (int) round($gpl)))) / 3;
            $sg = $sg * (1 + $diff);
        }
        return NAN;
    }

    /**
     * Total sugar (g) contributed by an additional-sugar entry
     * { quantity_amount, quantity_units, sugar_content } (quantity in HONEY_UNIT_*, sugar_content
     * as a percent).
     *
     * @param array{quantity_amount: float, quantity_units: int, sugar_content: float} $sugar
     */
    public static function getSugars(array $sugar): float
    {
        return ($sugar['quantity_amount'] * Constants::HONEY_UNIT_INFO[$sugar['quantity_units']]['conversion'] * $sugar['sugar_content']) / 100;
    }

    public static function volumeUnitsToHoneyUnits(int $unit): ?int
    {
        return match ($unit) {
            Constants::VOLUME_UNIT_LITERS => Constants::HONEY_UNIT_LITERS,
            Constants::VOLUME_UNIT_GALLONS_US => Constants::HONEY_UNIT_GALLONS_US,
            Constants::VOLUME_UNIT_GALLONS_IMP => Constants::HONEY_UNIT_GALLONS_IMP,
            Constants::VOLUME_UNIT_CUPS_US => Constants::HONEY_UNIT_CUPS_US,
            Constants::VOLUME_UNIT_CUPS_IMP => Constants::HONEY_UNIT_CUPS_IMP,
            Constants::VOLUME_UNIT_CUPS_METRIC => Constants::HONEY_UNIT_CUPS_METRIC,
            Constants::VOLUME_UNIT_FL_OUNCES_US => Constants::HONEY_UNIT_FL_OUNCES_US,
            Constants::VOLUME_UNIT_FL_OUNCES_IMP => Constants::HONEY_UNIT_FL_OUNCES_IMP,
            Constants::VOLUME_UNIT_PINTS_US => Constants::HONEY_UNIT_PINTS_US,
            Constants::VOLUME_UNIT_PINTS_IMP => Constants::HONEY_UNIT_PINTS_IMP,
            Constants::VOLUME_UNIT_QUARTS_US => Constants::HONEY_UNIT_QUARTS_US,
            Constants::VOLUME_UNIT_QUARTS_IMP => Constants::HONEY_UNIT_QUARTS_IMP,
            default => null,
        };
    }

    // functions adapted from Storm's mead-nutrient-calculation spreadsheet
    public static function stormABVtoSG(float $abv): float
    {
        $sgDelta = 1.0;
        while (CalculatorApi::convertGravityDropToABV($sgDelta) < $abv) {
            $sgDelta += 0.001;
        }
        return $sgDelta;
    }

    public static function stormSGtoYAN(float $sgDelta, int $yanRequirement): float
    {
        return (143.254 * $sgDelta ** 3 - 648.67 * $sgDelta ** 2 + 1125.805 * $sgDelta - 620.389)
            * 10
            * $sgDelta
            * Constants::NUTRIENT_FACTOR[$yanRequirement];
    }

    /**
     * potentialAlcohol(...) - given up to two of {og, fg, abv} (each already expressed in the
     * requested gravityUnits/abvUnits), solve for whichever value(s) are needed to produce a
     * consistent og/fg/abv trio, using the same priority as MeadBot's !potential-alcohol command:
     *  - og and abv specified -> solve fg
     *  - only og specified (or og+fg) -> solve abv
     *  - otherwise -> solve og (from fg and abv)
     *
     * Unlike !potential-alcohol, this always converts BRIX/BAUME inputs to SG before computing
     * (that command skips the conversion in two of its three branches, which produces incorrect
     * results — see GravityCalculator.resolveGravityAbvTrio in the MeadBot repo) and always uses
     * the same ABV<->SG-delta formula (stormABVtoSG's iterative search against the real cubic ABV
     * formula) for both the solve-fg and solve-og branches, rather than the command's inconsistent
     * mix of formulas. A JSON API has no equivalent of "value happens to match an unstated CLI
     * default", so "specified" here just means the field was present in the request.
     *
     * @return array<string, mixed>
     */
    public static function potentialAlcohol(
        int $gravityUnits,
        int $abvUnits,
        ?float $og,
        ?float $fg,
        ?float $abv
    ): array {
        $ogSpecified = $og !== null;
        $fgSpecified = $fg !== null;
        $abvSpecified = $abv !== null;

        $ogSg = $ogSpecified ? self::convToSG($og, $gravityUnits) : 1.108;
        $fgSg = $fgSpecified ? self::convToSG($fg, $gravityUnits) : 0.998;
        $abvPct = $abvSpecified ? ($abvUnits === Constants::ABV_UNIT_ABW ? self::abwToABV($abv) : $abv) : 14.37;

        if ($ogSpecified && $abvSpecified) {
            $sgDelta = self::stormABVtoSG($abvPct);
            $fgSg = $ogSg - $sgDelta + 1;
        } elseif ($ogSpecified) {
            $abvPct = CalculatorApi::convertGravityDropToABV($ogSg - $fgSg + 1);
        } else {
            $sgDelta = self::stormABVtoSG($abvPct);
            $ogSg = $fgSg + $sgDelta - 1;
        }

        $abvOut = $abvUnits === Constants::ABV_UNIT_ABW ? self::abvToABW($abvPct) : $abvPct;

        return [
            'error' => false,
            'gravityUnits' => Constants::GRAVITY_UNIT_NAMES[$gravityUnits],
            'abvUnits' => Constants::ABV_UNIT_NAMES[$abvUnits],
            'og' => self::doConvertSG(Constants::GRAVITY_UNIT_SG, $gravityUnits, $ogSg),
            'fg' => self::doConvertSG(Constants::GRAVITY_UNIT_SG, $gravityUnits, $fgSg),
            'abv' => $abvOut,
        ];
    }
}
