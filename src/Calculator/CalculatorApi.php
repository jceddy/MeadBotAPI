<?php

declare(strict_types=1);

namespace MeadBotApi\Calculator;

use DateTimeInterface;

/**
 * Ported from MeadBot's src/calculator/CalculatorAPI.js. Method names are camelCase
 * equivalents of the original PascalCase JS exports; behavior and numeric results are
 * intended to match exactly.
 */
final class CalculatorApi
{
    // randomInteger(max) - returns a random integer in the range [0-max)
    public static function randomInteger(int $max): int
    {
        return random_int(0, max(0, $max - 1));
    }

    // getDaysBetween(date1, date2) - calculate the number of full days between date1 and date2
    public static function getDaysBetween(DateTimeInterface $date1, DateTimeInterface $date2): float
    {
        $timeDifference = $date2->getTimestamp() - $date1->getTimestamp();
        return abs($timeDifference / (60 * 60 * 24));
    }

    // getMonthsBetween(date1, date2, roundUpFractionalMonths) - calculate the number of months
    // between date1 and date2; if roundUpFractionalMonths is true, fractional months round up,
    // otherwise they are ignored
    public static function getMonthsBetween(
        DateTimeInterface $date1,
        DateTimeInterface $date2,
        bool $roundUpFractionalMonths = false
    ): int {
        $startDate = $date1;
        $endDate = $date2;
        $inverse = false;
        if ($date1 > $date2) {
            $startDate = $date2;
            $endDate = $date1;
            $inverse = true;
        }

        $yearsDifference = (int) $endDate->format('Y') - (int) $startDate->format('Y');
        $monthsDifference = (int) $endDate->format('n') - (int) $startDate->format('n');
        $daysDifference = (int) $endDate->format('j') - (int) $startDate->format('j');

        $monthCorrection = 0;
        if ($roundUpFractionalMonths === true && $daysDifference > 0) {
            $monthCorrection = 1;
        } elseif ($roundUpFractionalMonths !== true && $daysDifference < 0) {
            $monthCorrection = -1;
        }

        return ($inverse ? -1 : 1) * ($yearsDifference * 12 + $monthsDifference + $monthCorrection);
    }

    /**
     * makeError(message, argument, argumentPosition, type) - build the standard error shape
     * returned by the calculation methods below.
     *
     * @return array{error: true, errorMessage: string, errorArgument: ?string, errorArgumentPosition: ?int, errorType: int, errorTypeLabel: string}
     */
    public static function makeError(string $message, ?string $argument, ?int $argumentPosition, int $type): array
    {
        return [
            'error' => true,
            'errorMessage' => $message,
            'errorArgument' => $argument,
            'errorArgumentPosition' => $argumentPosition,
            'errorType' => $type,
            'errorTypeLabel' => Constants::ERROR_TYPE_STRINGS[$type] ?? Constants::ERROR_TYPE_STRINGS[Constants::ERROR_UNKNOWN],
        ];
    }

    // true if $value cannot be treated as a number (mirrors JS's isNaN() over user input)
    private static function isNotNumeric(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return false;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return false;
        }
        return true;
    }

    private static function toFloat(mixed $value): float
    {
        return (float) $value;
    }

    /**
     * calculateCalories(percentAlcohol, fg, bottleVolume, servingVolume) - estimate the
     * caloric content of a beverage.
     *
     * @return array<string, mixed>
     */
    public static function calculateCalories(
        mixed $percentAlcohol,
        mixed $fg,
        mixed $bottleVolume,
        mixed $servingVolume
    ): array {
        if (self::isNotNumeric($percentAlcohol)) {
            return self::makeError(
                'percentAlcohol ' . $percentAlcohol . ' is not a number.',
                'percentAlcohol',
                0,
                Constants::ERROR_IS_NAN
            );
        }
        $percentAlcohol = self::toFloat($percentAlcohol);
        if ($percentAlcohol < 0 || $percentAlcohol > 100) {
            return self::makeError(
                'Percent Alcohol is out of range: ' . number_format($percentAlcohol, 1),
                'percentAlcohol',
                0,
                Constants::ERROR_RANGE
            );
        }

        if (self::isNotNumeric($fg)) {
            return self::makeError('fg ' . $fg . ' is not a number.', 'fg', 1, Constants::ERROR_IS_NAN);
        }
        $fg = self::toFloat($fg);
        if ($fg < 0.99 || $fg > 1.2) {
            return self::makeError(
                'Final Gravity is out of range: ' . number_format($fg, 3),
                'fg',
                1,
                Constants::ERROR_RANGE
            );
        }

        if (self::isNotNumeric($bottleVolume)) {
            return self::makeError(
                'bottleVolume ' . $bottleVolume . ' is not a number.',
                'bottleVolume',
                2,
                Constants::ERROR_IS_NAN
            );
        }
        $bottleVolume = self::toFloat($bottleVolume);
        if ($bottleVolume < 100 || $bottleVolume > 5000) {
            return self::makeError(
                'Bottle ml is out of range: ' . number_format($bottleVolume, 1),
                'bottleVolume',
                2,
                Constants::ERROR_RANGE
            );
        }

        if (self::isNotNumeric($servingVolume)) {
            return self::makeError(
                'servingVolume ' . $servingVolume . ' is not a number.',
                'servingVolume',
                3,
                Constants::ERROR_IS_NAN
            );
        }
        $servingVolume = self::toFloat($servingVolume);
        if ($servingVolume < 10 || $servingVolume > 1000) {
            return self::makeError(
                'Serving ml is out of range: ' . number_format($servingVolume, 1),
                'servingVolume',
                3,
                Constants::ERROR_RANGE
            );
        }

        $alcGL = 0.8 * $percentAlcohol;
        $alcCal = $alcGL * 7;
        $residualSugar = ($fg * 2641 - 2625) * ($bottleVolume / 1000);
        $residualCalories = $residualSugar * 4;
        $totalCaloriesBottle = $alcCal * ($bottleVolume / 100) + $residualCalories;
        $totalCalories250 = $alcCal * 2.5 + $residualCalories / ($bottleVolume / 250);
        $totalCaloriesServing = $alcCal * ($servingVolume / 100) + $residualCalories / ($bottleVolume / $servingVolume);

        return [
            'error' => false,
            'percentAlcohol' => $percentAlcohol,
            'fg' => $fg,
            'alcoholGramsLiter' => $alcGL,
            'alcoholCalories' => $alcCal,
            'bottleVolume' => $bottleVolume,
            'servingVolume' => $servingVolume,
            'residualSugar' => $residualSugar,
            'residualCalories' => $residualCalories,
            'totalCaloriesBottle' => $totalCaloriesBottle,
            'totalCalories250' => $totalCalories250,
            'totalCaloriesServing' => $totalCaloriesServing,
        ];
    }

    // convertGravityDropToABV(sgDelta) - convert a gravity drop to a %ABV estimate
    public static function convertGravityDropToABV(float $sgDelta): float
    {
        return -109.7 * ($sgDelta ** 2) + 361.84 * $sgDelta - 252.1;
    }

    // estimateDryFG(og) - estimate the "dry" final gravity from an original gravity
    public static function estimateDryFG(float $og): float
    {
        if ($og <= 1.0) {
            return $og;
        }

        $ogValues = Constants::DRY_FG_OG_VALUES;
        $fgValues = Constants::DRY_FG_FG_VALUES;
        $last = count($ogValues) - 1;

        if ($og >= $ogValues[$last]) {
            return $fgValues[$last];
        }

        $i = 1;
        while ($i < count($ogValues) && $og > $ogValues[$i]) {
            $i++;
        }

        $mu = ($og - $ogValues[$i - 1]) / ($ogValues[$i] - $ogValues[$i - 1]);
        $fg = $fgValues[$i - 1] * (1 - $mu) + $fgValues[$i] * $mu + 0.0001;
        return round($fg * 1000) / 1000;
    }

    /**
     * calculateABV(og, fg) - calculate estimated %ABV from an original and final gravity.
     * If $fg is null, a "dry" final gravity is estimated and used.
     *
     * @return array<string, mixed>
     */
    public static function calculateABV(mixed $og, mixed $fg = null): array
    {
        if (self::isNotNumeric($og)) {
            return self::makeError('og ' . $og . ' is not a number.', 'og', 0, Constants::ERROR_IS_NAN);
        }
        $og = self::toFloat($og);
        if ($og < 0.99 || $og > 1.4) {
            return self::makeError(
                'Original Gravity is out of range: ' . number_format($og, 3),
                'og',
                0,
                Constants::ERROR_RANGE
            );
        }

        if ($fg === null) {
            $fg = self::estimateDryFG($og);
        } else {
            if (self::isNotNumeric($fg)) {
                return self::makeError('fg ' . $fg . ' is not a number.', 'fg', 1, Constants::ERROR_IS_NAN);
            }
            $fg = self::toFloat($fg);
            if ($fg < 0.99 || $fg > 1.2) {
                return self::makeError(
                    'Final Gravity is out of range: ' . number_format($fg, 3),
                    'fg',
                    1,
                    Constants::ERROR_RANGE
                );
            }
        }

        if ($og < $fg) {
            return self::makeError(
                'Original Gravity cannot be less than Final Gravity: (' .
                    number_format($og, 3) . ' < ' . number_format($fg, 3) . ')',
                null,
                null,
                Constants::ERROR_INVALID_ARGUMENTS
            );
        }

        $abv = self::convertGravityDropToABV($og - $fg + 1);

        return [
            'error' => false,
            'og' => $og,
            'fg' => $fg,
            'abv' => $abv,
        ];
    }

    // getVolumeUnit(volume) - get a numeric volume unit identifier from a string
    public static function getVolumeUnit(string $volume): ?int
    {
        return match ($volume) {
            'liters', 'liter', 'litres', 'litre' => Constants::VOLUME_UNIT_LITERS,
            'gallons_us', 'gallon_us', 'us_gallons', 'us_gallon', 'gallons', 'gallon' => Constants::VOLUME_UNIT_GALLONS_US,
            'gallons_imp', 'gallon_imp', 'imp_gallons', 'imp_gallon',
            'gallons_imperial', 'gallon_imperial', 'imperial_gallons', 'imperial_gallon' => Constants::VOLUME_UNIT_GALLONS_IMP,
            'fl_ounces_us', 'fl_ounce_us', 'us_fl_ounces', 'us_fl_ounce', 'fl_oz_us', 'us_fl_oz',
            'fluid_ounces_us', 'us_fluid_ounces', 'ounces', 'oz' => Constants::VOLUME_UNIT_FL_OUNCES_US,
            'fl_ounces_imp', 'fl_ounce_imp', 'imp_fl_ounces', 'imp_fl_ounce', 'fl_oz_imp', 'imp_fl_oz',
            'fl_ounces_imperial', 'fl_ounce_imperial', 'imperial_fl_ounces', 'imperial_fl_ounce' => Constants::VOLUME_UNIT_FL_OUNCES_IMP,
            'pints_us', 'pint_us', 'us_pints', 'us_pint', 'pints', 'pint' => Constants::VOLUME_UNIT_PINTS_US,
            'pints_imp', 'pint_imp', 'imp_pints', 'imp_pint',
            'pints_imperial', 'pint_imperial', 'imperial_pints', 'imperial_pint' => Constants::VOLUME_UNIT_PINTS_IMP,
            'quarts_us', 'quart_us', 'us_quarts', 'us_quart', 'quarts', 'quart' => Constants::VOLUME_UNIT_QUARTS_US,
            'quarts_imp', 'quart_imp', 'imp_quarts', 'imp_quart',
            'quarts_imperial', 'quart_imperial', 'imperial_quarts', 'imperial_quart' => Constants::VOLUME_UNIT_QUARTS_IMP,
            'cups_us', 'cup_us', 'us_cups', 'us_cup', 'cups', 'cup' => Constants::VOLUME_UNIT_CUPS_US,
            'cups_imp', 'cup_imp', 'imp_cups', 'imp_cup',
            'cups_imperial', 'cup_imperial', 'imperial_cups', 'imperial_cup' => Constants::VOLUME_UNIT_CUPS_IMP,
            'cups_metric', 'cup_metric', 'metric_cups', 'metric_cup' => Constants::VOLUME_UNIT_CUPS_METRIC,
            default => null,
        };
    }

    /**
     * convertVolume(amount, fromUnit, toUnit) - convert a volume in fromUnit units to toUnit units.
     *
     * @return array<string, mixed>
     */
    public static function convertVolume(mixed $amount, string $fromUnit, string $toUnit): array
    {
        if (self::isNotNumeric($amount)) {
            return self::makeError('amount ' . $amount . ' is not a number.', 'amount', 0, Constants::ERROR_IS_NAN);
        }
        $amount = self::toFloat($amount);

        $fromUnitId = self::getVolumeUnit($fromUnit);
        if ($fromUnitId === null) {
            return self::makeError('Unknown volume unit: ' . $fromUnit, 'fromUnit', 1, Constants::ERROR_INVALID_ARGUMENTS);
        }

        $toUnitId = self::getVolumeUnit($toUnit);
        if ($toUnitId === null) {
            return self::makeError('Unknown volume unit: ' . $toUnit, 'toUnit', 2, Constants::ERROR_INVALID_ARGUMENTS);
        }

        $fromUnitInfo = Constants::VOLUME_UNIT_INFO[$fromUnitId];
        $toUnitInfo = Constants::VOLUME_UNIT_INFO[$toUnitId];

        $result = ($amount / $toUnitInfo['conversion']) * $fromUnitInfo['conversion'];

        return [
            'error' => false,
            'fromAmount' => $amount,
            'fromUnit' => $fromUnitInfo,
            'toAmount' => $result,
            'toUnit' => $toUnitInfo,
        ];
    }

    // getHoneyUnit(unitString) - get a numeric honey unit identifier from a string
    public static function getHoneyUnit(string $unitString): ?int
    {
        return match ($unitString) {
            'kilogram', 'kilograms', 'kilo', 'kilos', 'kg' => Constants::HONEY_UNIT_KILOGRAMS,
            'pounds', 'pound', 'lbs', 'lb' => Constants::HONEY_UNIT_POUNDS,
            'liters', 'liter', 'litres', 'litre', 'l' => Constants::HONEY_UNIT_LITERS,
            'gallons_us', 'gallon_us', 'us_gallons', 'us_gallon', 'gallons', 'gallon', 'gals', 'gal' => Constants::HONEY_UNIT_GALLONS_US,
            'gallons_imp', 'gallon_imp', 'gallons_imperial', 'gallon_imperial',
            'imp_gallons', 'imp_gallon', 'imperial_gallons', 'imperial_gallon' => Constants::HONEY_UNIT_GALLONS_IMP,
            'ounces', 'ounce', 'oz' => Constants::HONEY_UNIT_OUNCES,
            'cups_us', 'cup_us', 'us_cups', 'us_cup', 'cups', 'cup', 'c' => Constants::HONEY_UNIT_CUPS_US,
            'cups_imp', 'cup_imp', 'cups_imperial', 'cup_imperial',
            'imp_cups', 'imp_cup', 'imperial_cups', 'imperial_cup' => Constants::HONEY_UNIT_CUPS_IMP,
            'cups_metric', 'cup_metric', 'metric_cups', 'metric_cup' => Constants::HONEY_UNIT_CUPS_METRIC,
            'fl_ounces_us', 'fl_ounce_us', 'fl_oz_us', 'us_fl_ounces', 'us_fl_ounce', 'us_fl_oz',
            'fl_ounces', 'fl_ounce', 'fl_oz', 'fluid_ounces_us' => Constants::HONEY_UNIT_FL_OUNCES_US,
            'fl_ounces_imp', 'fl_ounce_imp', 'fl_oz_imp', 'imp_fl_ounces', 'imp_fl_ounce', 'imp_fl_oz',
            'imperial_fl_ounces', 'imperial_fl_ounce', 'imperial_fl_oz', 'fluid_ounces_imp' => Constants::HONEY_UNIT_FL_OUNCES_IMP,
            'pints_us', 'pint_us', 'us_pints', 'us_pint', 'pints', 'pint' => Constants::HONEY_UNIT_PINTS_US,
            'pints_imp', 'pint_imp', 'imp_pints', 'imp_pint',
            'pints_imperial', 'pint_imperial', 'imperial_pints', 'imperial_pint' => Constants::HONEY_UNIT_PINTS_IMP,
            'quarts_us', 'quart_us', 'us_quarts', 'us_quart', 'quarts', 'quart', 'qt', 'qt_us', 'us_qt' => Constants::HONEY_UNIT_QUARTS_US,
            'quarts_imp', 'quart_imp', 'imp_quarts', 'imp_quart', 'quarts_imperial', 'quart_imperial',
            'imperial_quarts', 'imperial_quart', 'qt_imp', 'qt_imperial' => Constants::HONEY_UNIT_QUARTS_IMP,
            default => null,
        };
    }

    /**
     * convertHoneyUnits(amount, fromUnit, toUnit) - convert an amount of honey in fromUnit
     * units to toUnit units.
     *
     * @return array<string, mixed>
     */
    public static function convertHoneyUnits(mixed $amount, string $fromUnit, string $toUnit): array
    {
        if (self::isNotNumeric($amount)) {
            return self::makeError('amount ' . $amount . ' is not a number.', 'amount', 0, Constants::ERROR_IS_NAN);
        }
        $amount = self::toFloat($amount);

        $fromUnitId = self::getHoneyUnit($fromUnit);
        if ($fromUnitId === null) {
            return self::makeError('Unknown honey unit: ' . $fromUnit, 'fromUnit', 1, Constants::ERROR_INVALID_ARGUMENTS);
        }

        $toUnitId = self::getHoneyUnit($toUnit);
        if ($toUnitId === null) {
            return self::makeError('Unknown honey unit: ' . $toUnit, 'toUnit', 2, Constants::ERROR_INVALID_ARGUMENTS);
        }

        $fromUnitInfo = Constants::HONEY_UNIT_INFO[$fromUnitId];
        $toUnitInfo = Constants::HONEY_UNIT_INFO[$toUnitId];

        $result = ($amount / $toUnitInfo['conversion']) * $fromUnitInfo['conversion'];

        return [
            'error' => false,
            'fromAmount' => $amount,
            'fromUnit' => $fromUnitInfo,
            'toAmount' => $result,
            'toUnit' => $toUnitInfo,
        ];
    }

    /**
     * convertTemperature(fromTemperature, fromUnit) - convert a temperature from one unit to
     * the other ('celcius'/'c' <-> 'fahrenheit'/'f').
     *
     * @return array<string, mixed>
     */
    public static function convertTemperature(mixed $fromTemperature, string $fromUnit): array
    {
        if (self::isNotNumeric($fromTemperature)) {
            return self::makeError(
                'fromTemperature ' . $fromTemperature . ' is not a number.',
                'fromTemperature',
                0,
                Constants::ERROR_IS_NAN
            );
        }
        $fromTemperature = self::toFloat($fromTemperature);

        $fromUnitId = null;
        $toUnitId = null;
        $toTemp = null;

        if ($fromUnit === 'celcius' || $fromUnit === 'c') {
            $fromUnitId = Constants::TEMPERATURE_UNIT_CELSIUS;
            $toUnitId = Constants::TEMPERATURE_UNIT_FAHRENHEIT;
            $toTemp = round((($fromTemperature * 9) / 5 + 32) * 100) / 100;
        } elseif ($fromUnit === 'fahrenheit' || $fromUnit === 'f') {
            $fromUnitId = Constants::TEMPERATURE_UNIT_FAHRENHEIT;
            $toUnitId = Constants::TEMPERATURE_UNIT_CELSIUS;
            $toTemp = round((($fromTemperature - 32) * 5 / 9) * 100) / 100;
        }

        if ($fromUnitId === null) {
            return self::makeError('Unknown temperature unit: ' . $fromUnit, 'fromUnit', 1, Constants::ERROR_INVALID_ARGUMENTS);
        }

        return [
            'error' => false,
            'fromTemperature' => $fromTemperature,
            'fromUnit' => Constants::TEMPERATURE_UNIT_NAMES[$fromUnitId],
            'toTemperature' => $toTemp,
            'toUnit' => Constants::TEMPERATURE_UNIT_NAMES[$toUnitId],
        ];
    }

    // convertSGToBrix(sg) - convert a specific gravity to BRIX
    public static function convertSGToBrix(float $sg): float
    {
        return 135.997 * ($sg ** 3) - 630.272 * ($sg ** 2) + 1111.14 * $sg - 616.868;
    }

    /**
     * computeDelle(abv, sg) - compute an estimated Delle number from a provided %ABV and
     * specific gravity.
     *
     * @return array<string, mixed>
     */
    public static function computeDelle(mixed $abv, mixed $sg): array
    {
        if (self::isNotNumeric($abv)) {
            return self::makeError('abv ' . $abv . ' is not a number.', 'abv', 0, Constants::ERROR_IS_NAN);
        }
        $abv = round(self::toFloat($abv) * 10) / 10;

        if (self::isNotNumeric($sg)) {
            return self::makeError('sg ' . $sg . ' is not a number.', 'sg', 1, Constants::ERROR_IS_NAN);
        }
        $sg = round(self::toFloat($sg) * 1000) / 1000;

        return [
            'error' => false,
            'abv' => $abv,
            'sg' => $sg,
            'delle' => 4.5 * $abv + self::convertSGToBrix($sg) * $sg,
        ];
    }

    // getSugarSourceIdentifier(sugar) - return the sugar source identifier corresponding to a string
    public static function getSugarSourceIdentifier(string $sugar): ?int
    {
        $sources = Constants::SUGAR_SOURCES;

        return match ($sugar) {
            'honey' => $sources['HONEY'],
            'sugar' => $sources['SUGAR'],
            'acerola' => $sources['ACEROLA'],
            'apples', 'apple' => $sources['APPLES'],
            'apricots', 'apricot' => $sources['APRICOTS'],
            'apricots_dried', 'dried_apricots' => $sources['APRICOTS_DRIED'],
            'bananas', 'banana' => $sources['BANANAS'],
            'blackberry' => $sources['BLACKBERRY'],
            'blueberry' => $sources['BLUEBERRY'],
            'boysenberry' => $sources['BOYSENBERRY'],
            'cantaloupe' => $sources['CANTALOUPE'],
            'carambola' => $sources['CARAMBOLA'],
            'carrots', 'carrot' => $sources['CARROTS'],
            'casaba_melon' => $sources['CASABA_MELON'],
            'cashews', 'cashew' => $sources['CASHEWS'],
            'celery' => $sources['CELERY'],
            'cherry_dark_sweet', 'cherry_sweet', 'dark_sweet_cherry', 'sweet_cherry',
            'cherry_dark', 'dark_cherry' => $sources['CHERRY_DARK_SWEET'],
            'cherry_montmorency', 'montmorency_cherry', 'cherry_tart', 'tart_cherry' => $sources['CHERRY_MONTMORENCY'],
            'crabapples', 'crabapple' => $sources['CRABAPPLES'],
            'cranberry' => $sources['CRANBERRY'],
            'currant_black', 'black_currant' => $sources['CURRANT_BLACK'],
            'currant_red', 'red_currant' => $sources['CURRANT_RED'],
            'dates', 'date' => $sources['DATES'],
            'dates_dried', 'dried_dates' => $sources['DATES_DRIED'],
            'dewberry' => $sources['DEWBERRY'],
            'elderberry' => $sources['ELDERBERRY'],
            'figs', 'fig' => $sources['FIGS'],
            'figs_dried', 'dried_figs' => $sources['FIGS_DRIED'],
            'gooseberry' => $sources['GOOSEBERRY'],
            'grape_concord', 'concord_grape' => $sources['GRAPE_CONCORD'],
            'grapes', 'grape' => $sources['GRAPES'],
            'grapefruit' => $sources['GRAPEFRUIT'],
            'guanabana' => $sources['GUANABANA'],
            'guavas', 'guava' => $sources['GUAVAS'],
            'honeydew_melon' => $sources['HONEYDEW_MELON'],
            'jackfruit' => $sources['JACKFRUIT'],
            'kiwis', 'kiwi' => $sources['KIWIS'],
            'lemon_juice' => $sources['LEMON_JUICE'],
            'lime_juice' => $sources['LIME_JUICE'],
            'lychee_litchi', 'lychee', 'litchi' => $sources['LYCHEE_LITCHI'],
            'loganberry' => $sources['LOGANBERRY'],
            'mangos', 'mango' => $sources['MANGOS'],
            'maple_syrup' => $sources['MAPLE_SYRUP'],
            'maple_sap' => $sources['MAPLE_SAP'],
            'mulberry' => $sources['MULBERRY'],
            'nectarines' => $sources['NECTARINES'],
            'orange_juice' => $sources['ORANGE_JUICE'],
            'papaya' => $sources['PAPAYA'],
            'passionfruit' => $sources['PASSIONFRUIT'],
            'peaches', 'peach' => $sources['PEACHES'],
            'pears', 'pear' => $sources['PEARS'],
            'persimmon' => $sources['PERSIMMON'],
            'pineapples', 'pineapple' => $sources['PINEAPPLES'],
            'plums', 'plum' => $sources['PLUMS'],
            'pomegranates', 'pomegranate' => $sources['POMEGRANATES'],
            'prickly_pear' => $sources['PRICKLY_PEAR'],
            'prunes_dried', 'dried_prunes', 'prunes' => $sources['PRUNES_DRIED'],
            'quinces', 'quince' => $sources['QUINCES'],
            'raisins_dried', 'dried_raisins', 'raisins' => $sources['RAISINS_DRIED'],
            'raspberry_red', 'red_raspberry' => $sources['RASPBERRY_RED'],
            'raspberry_black', 'black_raspberry' => $sources['RASPBERRY_BLACK'],
            'rhubarb' => $sources['RHUBARB'],
            'strawberry' => $sources['STRAWBERRY'],
            'sultanas' => $sources['SULTANAS'],
            'tangerines', 'tangerine' => $sources['TANGERINES'],
            'tangelo' => $sources['TANGELO'],
            'tomatoes', 'tomato', 'tomatos' => $sources['TOMATOES'],
            'watermelons', 'watermelon' => $sources['WATERMELONS'],
            'youngberry' => $sources['YOUNGBERRY'],
            'dme', 'dry_malt_extract' => $sources['DME'],
            'lme', 'liquid_malt_extract' => $sources['LME'],
            'apple_juice', 'aj' => $sources['APPLE_JUICE'],
            'cranberry_juice', 'cran_juice' => $sources['CRANBERRY_JUICE'],
            default => null,
        };
    }

    // makeHoursString(timing, break3) - human-readable string from SNA timing info and the
    // 1/3 sugar break SG
    public static function makeHoursString(string $timing, ?float $break3 = null): string
    {
        if ($timing === 'pitch') {
            return 'At Pitch';
        }
        if ($timing === 'break') {
            return '1/3 Sugar Break (' . number_format((float) $break3, 3) . ' - no later than 7 days)';
        }
        if (str_contains($timing, ',')) {
            $t = explode(',', $timing);
            return $t[0] . ' Hours after ' . ($t[1] === '0' ? 'pitch' : 'honey addition #' . $t[1]);
        }
        return $timing . ' Hours';
    }
}
