<?php

declare(strict_types=1);

namespace MeadBotApi\Calculator;

/**
 * Ported from MeadBot's src/calculator/NutrientCalculator.js — yeast/nutrient-schedule math
 * shared by the build-batch, calculate-nutrients, and calculate-mead commands. Originally ported
 * from Storm's mead-nutrient-calculation spreadsheet.
 */
final class NutrientCalculator
{
    public static function getYanRatio(float $yan, float $ratio, float $totalRatio): float
    {
        return ($yan * $ratio) / $totalRatio;
    }

    /**
     * Blount-Elliott-style nutrient split across Fermaid O, Fermaid K, and DAP, staggered across
     * a given SNA schedule (each element is either an hour count or a label like 'break' — the
     * values are opaque to this function and simply copied into each addition's `timing` field).
     *
     * @param array<int, int|string> $snaSchedule
     * @return array<string, mixed>
     */
    public static function getAdvancedNutrients(
        int $units,
        float $volume,
        float $yan,
        float $fermOEffectiveness,
        bool $enforceLimits,
        float $dapLimit,
        float $fermKLimit,
        float $fermOLimit,
        float $yanRatioDap,
        float $yanRatioFermK,
        float $yanRatioFermO,
        array $snaSchedule,
        float $fermKYan,
        bool $fillFkFirst
    ): array {
        $dapR = $yanRatioDap;
        $fkR = $yanRatioFermK;
        $foR = $yanRatioFermO;
        $rt = $dapR + $fkR + $foR;
        $vol = $volume;
        $foe = $fermOEffectiveness;

        if ($units === Constants::UNITS_US) {
            $vol = $vol * 3.78541;
        }

        $fkGL = $fkYan = $dapGL = $dapYan = $foGL = $foYan = 0.0;
        $debug = '';

        if ($enforceLimits) {
            $foMaxGL = $fermOLimit;
            $fkMaxGL = $fermKLimit;
            $dapMaxGL = $dapLimit;

            $foMaxYan = $foMaxGL * 40 * $foe;
            $fkMaxYan = $fkMaxGL * $fermKYan;
            $dapMaxYan = $dapMaxGL * 210;

            $maxYan = $foMaxYan + $fkMaxYan + $dapMaxYan;
            $fofkMaxYan = $foMaxYan + $fkMaxYan;

            if ($yan > $maxYan) {
                $debug = 'calculate all three with fo remainder';
                $fkGL = $fkMaxGL;
                $fkYan = $fkMaxYan;
                $dapGL = $dapMaxGL;
                $dapYan = $dapMaxYan;
                $yanRem = $yan - ($fkMaxYan + $dapMaxYan);
                $foGL = $yanRem / (40 * $foe);
                $foYan = $yanRem;
            } elseif ($yan > $fofkMaxYan) {
                $debug = 'calculate all three with dap remainder';
                $foGL = $foMaxGL;
                $foYan = $foMaxYan;
                $fkGL = $fkMaxGL;
                $fkYan = $fkMaxYan;
                $yanRem = $yan - ($fkMaxYan + $foMaxYan);
                $dapGL = $yanRem / 210;
                $dapYan = $yanRem;
            } elseif ($fillFkFirst) {
                if ($yan > $fkMaxYan) {
                    $debug = 'calculate fk and fo remainder';
                    $fkGL = $fkMaxGL;
                    $fkYan = $fkMaxYan;
                    $yanRem = $yan - $fkMaxYan;
                    $foGL = $yanRem / (40 * $foe);
                    $foYan = $yanRem;
                    $dapGL = 0.0;
                    $dapYan = 0.0;
                } else {
                    $debug = 'calculate fk only';
                    $fkGL = $yan / $fermKYan;
                    $fkYan = $yan;
                    $foGL = 0.0;
                    $foYan = 0.0;
                    $dapGL = 0.0;
                    $dapYan = 0.0;
                }
            } elseif ($yan > $foMaxYan) {
                $debug = 'calculate fo and fk remainder';
                $foGL = $foMaxGL;
                $foYan = $foMaxYan;
                $yanRem = $yan - $foMaxYan;
                $fkGL = $yanRem / $fermKYan;
                $fkYan = $yanRem;
                $dapGL = 0.0;
                $dapYan = 0.0;
            } else {
                $debug = 'calculate fo only';
                $foGL = $yan / (40 * $foe);
                $foYan = $yan;
                $fkGL = 0.0;
                $fkYan = 0.0;
                $dapGL = 0.0;
                $dapYan = 0.0;
            }

            $yanRatioFermK = ceil(($fkYan / $yan) * 1000) / 1000;
            $yanRatioFermO = ceil(($foYan / $yan) * 1000) / 1000;
            $yanRatioDap = ceil(($dapYan / $yan) * 1000) / 1000;
        } else {
            $debug = 'custom limits';
            $dapYan = self::getYanRatio($yan, $dapR, $rt);
            $fkYan = self::getYanRatio($yan, $fkR, $rt);
            $foYan = self::getYanRatio($yan, $foR, $rt);

            $dapGL = $dapYan / 210;
            $fkGL = $fkYan / $fermKYan;
            $foGL = $foYan / (40 * $foe);
        }

        $dapG = $dapGL * $vol;
        $fkG = $fkGL * $vol;
        $foG = $foGL * $vol;

        $dapYan = round($dapYan * 100) / 100;
        $fkYan = round($fkYan * 100) / 100;
        $foYan = round($foYan * 100) / 100;
        $dapGL = round($dapGL * 100) / 100;
        $fkGL = round($fkGL * 100) / 100;
        $foGL = round($foGL * 100) / 100;
        $dapG = round($dapG * 100) / 100;
        $fkG = round($fkG * 100) / 100;
        $foG = round($foG * 100) / 100;

        $additions = [];
        $scheduleLength = count($snaSchedule);

        if ($scheduleLength > 4 && ($fkG > 0 || $dapG > 0)) {
            $fkG1 = round(($fkG * 100) / 2) / 100;
            $fkG2 = round(($fkG - $fkG1) * 100) / 100;
            $foG1 = round(($foG * 100) / 2) / 100;
            $foG2 = round(($foG - $foG1) * 100) / 100;

            $stepDiv = (int) floor(($scheduleLength - 1) / 2);

            $dapN = round($dapG / $stepDiv * 100) / 100;
            $finalDap = round(($dapG - $dapN * ($stepDiv - 1)) * 100) / 100;
            $fkN1 = round($fkG1 / $stepDiv * 100) / 100;
            $finalFk1 = round(($fkG1 - $fkN1 * ($stepDiv - 1)) * 100) / 100;
            $fkN2 = round($fkG2 / $stepDiv * 100) / 100;
            $finalFk2 = round(($fkG2 - $fkN2 * ($stepDiv - 1)) * 100) / 100;
            $foN1 = round($foG1 / $stepDiv * 100) / 100;
            $finalFo1 = round(($foG1 - $foN1 * ($stepDiv - 1)) * 100) / 100;

            $i = 0;
            for ($j = 0; $j < $stepDiv - 1; $j++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $fkN1, 'dap' => $dapN];
                $i++;
            }
            $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $finalFk1, 'dap' => $finalDap];
            $i++;
            for ($j = 0; $j < $stepDiv - 1; $j++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $foN1, 'fermK' => $fkN2, 'dap' => 0.0];
                $i++;
            }
            $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $finalFo1, 'fermK' => $finalFk2, 'dap' => 0.0];
            $i++;
            $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $foG2, 'fermK' => 0.0, 'dap' => 0.0];
        } else {
            $foN = ceil(100 * $foG / $scheduleLength) / 100;
            $fkN = ceil(100 * $fkG / $scheduleLength) / 100;
            $dapN = ceil(100 * $dapG / $scheduleLength) / 100;
            $finalFo = round(100 * ($foG - $foN * ($scheduleLength - 1))) / 100;
            $finalFk = round(100 * ($fkG - $fkN * ($scheduleLength - 1))) / 100;
            $finalDap = round(100 * ($dapG - $dapN * ($scheduleLength - 1))) / 100;

            for ($i = 0; $i < $scheduleLength - 1; $i++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $foN, 'fermK' => $fkN, 'dap' => $dapN];
            }
            $additions[] = [
                'timing' => $snaSchedule[$scheduleLength - 1],
                'fermO' => $finalFo,
                'fermK' => $finalFk,
                'dap' => $finalDap,
            ];
        }

        return [
            'yan' => $yan,
            'volume' => $volume,
            'units' => $units,
            'foe' => $foe,
            'dap_limit' => $dapLimit,
            'fermK_limit' => $fermKLimit,
            'fermO_limit' => $fermOLimit,
            'yan_ratio_dap' => $yanRatioDap,
            'yan_ratio_fermK' => $yanRatioFermK,
            'yan_ratio_fermO' => $yanRatioFermO,
            'dapGL' => $dapGL,
            'fkGL' => $fkGL,
            'foGL' => $foGL,
            'dapYan' => $dapYan,
            'fkYan' => $fkYan,
            'foYan' => $foYan,
            'dapG' => $dapG,
            'fkG' => $fkG,
            'foG' => $foG,
            'enforce' => $enforceLimits,
            'debug' => $debug,
            'sna' => [
                'nitrogen' => $yan,
                'totalFermO' => $foG,
                'totalFermK' => $fkG,
                'totalDAP' => $dapG,
                'additions' => $additions,
            ],
        ];
    }

    // yanContributionFromGrams(grams, yanPpm, volumeInLiters) - ppm YAN contributed by adding a
    // nutrient source (e.g. Go-Ferm) of the given YAN concentration (in parts per million) to a
    // must of the given volume, in grams added
    public static function yanContributionFromGrams(float $grams, float $yanPpm, float $volumeInLiters): float
    {
        if ($grams <= 0) {
            return 0.0;
        }
        return floor(($grams * $yanPpm) / $volumeInLiters);
    }

    /**
     * calculateNutrients(options) - orchestrates !calculate-nutrients' full computation (Go-Ferm
     * YAN contribution, then a staggered-nutrient-addition schedule fixed at
     * [24, 48, 72, 'break']) from already-defaulted options. See NutrientCalculator.js's
     * calculateNutrients for the field list; keys here are the same, camelCase.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function calculateNutrients(array $options): array
    {
        $units = $options['units'];
        $volume = $options['volume'];

        $volumeInLiters = $units === Constants::UNITS_US ? $volume * 3.784 : $volume;
        $gofermYanContribution = self::yanContributionFromGrams(
            $options['gofermGrams'],
            $options['gofermYan'],
            $volumeInLiters
        );

        $nutrients = self::getAdvancedNutrients(
            $units,
            $volume,
            $options['yan'] - $gofermYanContribution,
            $options['fermOEffectiveness'],
            $options['enforceLimits'],
            $options['dapLimit'],
            $options['fermKLimit'],
            $options['fermOLimit'],
            $options['yanRatioDap'],
            $options['yanRatioFermK'],
            $options['yanRatioFermO'],
            [24, 48, 72, 'break'],
            $options['fermKYan'],
            $options['fillFkFirst']
        );

        $nutrients['gofermYanContribution'] = $gofermYanContribution;
        return $nutrients;
    }
}
