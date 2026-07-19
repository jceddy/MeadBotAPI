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
     * hoCalc(og, volumeInGallons, units) - honey weight (and its unit label) needed to hit a
     * given OG for a batch of the given volume (in gallons, regardless of units).
     *
     * @return array{0: float, 1: string}|null null for an unrecognized units value.
     */
    public static function hoCalc(float $og, float $volumeInGallons, int $units): ?array
    {
        $hV = ($og - 1) / 0.41204; // honey weight in lbs, per gallon-equivalent

        return match ($units) {
            Constants::UNITS_US => [$hV * 11.76088 * $volumeInGallons, 'lbs'],
            Constants::UNITS_METRIC => [($hV * 11.76088 * $volumeInGallons) / 2.2046, 'kg'],
            default => null,
        };
    }

    /**
     * getGoferm(volumeInGallons, og, fruitYan, useGoferm, yeastPackGrams, doKveik) - dry yeast /
     * Go-Ferm requirements for a batch.
     *
     * @return array{0: float, 1: int, 2: float, 3: float, 4: float}
     */
    public static function getGoferm(
        float $volumeInGallons,
        float $og,
        float $fruitYan,
        bool $useGoferm = true,
        float $yeastPackGrams = 5.0,
        bool $doKveik = false
    ): array {
        $volume = (float) round($volumeInGallons, 3);

        $pitch = 2;
        if ($doKveik) {
            $pitch = 1;
            $yeastPackGrams = 0.0;
        } elseif ($og >= 1.144 || $fruitYan < 0) {
            $pitch = 3;
        }

        $yst = ceil($pitch * $volume);
        $numPacket = 0;
        $gf = 1.25 * $yst;
        if ($yeastPackGrams > 0) {
            $numPacket = (int) ceil($yst / $yeastPackGrams);
            $gf = 1.25 * $numPacket * $yeastPackGrams;
        }
        if (!$useGoferm) {
            $gf = 0.0;
        }
        $reh = $gf * 20;

        return [$yst, $numPacket, $gf, $reh, $yeastPackGrams];
    }

    /**
     * @param array<int, int|string> $snaSchedule
     * @return array<string, mixed>
     */
    public static function getFermO(float $vol, float $nit, array $snaSchedule): array
    {
        $scheduleLength = count($snaSchedule);
        $fermO = round(($nit * $vol) / 50 * 10) / 10;
        $fermN = round($fermO / $scheduleLength * 10) / 10;
        $lastFerm = round(($fermO - $fermN * ($scheduleLength - 1)) * 10) / 10;

        $additions = [];
        for ($i = 0; $i < $scheduleLength - 1; $i++) {
            $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $fermN, 'fermK' => 0.0, 'dap' => 0.0];
        }
        $additions[] = [
            'timing' => $snaSchedule[$scheduleLength - 1],
            'fermO' => $lastFerm,
            'fermK' => 0.0,
            'dap' => 0.0,
        ];

        return ['nitrogen' => $nit, 'totalFermO' => $fermO, 'totalFermK' => 0.0, 'totalDAP' => 0.0, 'additions' => $additions];
    }

    /**
     * @param array<int, int|string> $snaSchedule
     * @return array<string, mixed>
     */
    public static function getFermK(float $vol, float $nit, array $snaSchedule): array
    {
        $scheduleLength = count($snaSchedule);
        $fermK = round($nit * $vol / 50 / 2 * 10) / 10;
        $fermN = round($fermK / $scheduleLength * 10) / 10;
        $lastFerm = round(($fermK - $fermN * ($scheduleLength - 1)) * 10) / 10;

        $additions = [];
        for ($i = 0; $i < $scheduleLength - 1; $i++) {
            $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $fermN, 'dap' => 0.0];
        }
        $additions[] = [
            'timing' => $snaSchedule[$scheduleLength - 1],
            'fermO' => 0.0,
            'fermK' => $lastFerm,
            'dap' => 0.0,
        ];

        return ['nitrogen' => $nit, 'totalFermO' => 0.0, 'totalFermK' => $fermK, 'totalDAP' => 0.0, 'additions' => $additions];
    }

    /**
     * @param array<int, int|string> $snaSchedule
     * @return array<string, mixed>
     */
    public static function getFermOK(float $vol, float $nit, array $snaSchedule): array
    {
        $additions = [];
        $scheduleLength = count($snaSchedule);
        $fermO = 0.0;
        $fermK = 0.0;

        if ($scheduleLength === 1) {
            $fermO = round($nit * $vol / 50 / 2 * 10) / 10;
            $fermK = round($nit * $vol / 50 / 4 * 10) / 10;

            $additions[] = ['timing' => $snaSchedule[0], 'fermO' => $fermO, 'fermK' => $fermK, 'dap' => 0.0];
        } elseif ($scheduleLength === 2) {
            $fermO = round($nit * $vol / 50 / 2 * 10) / 10;
            $fermO2 = round($fermO / 2 * 10) / 10;
            $lastFermO = round(($fermO - $fermO2) * 10) / 10;
            $fermK = round($nit * $vol / 50 / 4 * 10) / 10;
            $fermK2 = round($fermK / 2 * 10) / 10;
            $lastFermK = round(($fermK - $fermK2) * 10) / 10;

            $additions[] = ['timing' => $snaSchedule[0], 'fermO' => $fermO2, 'fermK' => $fermK2, 'dap' => 0.0];
            $additions[] = ['timing' => $snaSchedule[1], 'fermO' => $lastFermO, 'fermK' => $lastFermK, 'dap' => 0.0];
        } elseif ($scheduleLength === 3) {
            $fermO = round($nit * $vol / 50 * 10 * (2 / 3) / 10);
            $fermK = round($nit * $vol / 50 / 2 * 10 * (1 / 3) / 10);
            $fermO2 = round($fermO / 2 * 10) / 10;
            $lastFermO = round(($fermO - $fermO2) * 10) / 10;

            $additions[] = ['timing' => $snaSchedule[0], 'fermO' => $fermO2, 'fermK' => 0.0, 'dap' => 0.0];
            $additions[] = ['timing' => $snaSchedule[1], 'fermO' => 0.0, 'fermK' => $fermK, 'dap' => 0.0];
            $additions[] = ['timing' => $snaSchedule[2], 'fermO' => $lastFermO, 'fermK' => 0.0, 'dap' => 0.0];
        } elseif ($scheduleLength === 4) {
            $fermO = round($nit * $vol / 50 / 2 * 10) / 10;
            $fermO2 = round($fermO / 2 * 10) / 10;
            $lastFermO = round(($fermO - $fermO2) * 10) / 10;
            $fermK = round($nit * $vol / 50 / 4 * 10) / 10;
            $fermK2 = round($fermK / 2 * 10) / 10;
            $lastFermK = round(($fermK - $fermK2) * 10) / 10;

            $additions[] = ['timing' => $snaSchedule[0], 'fermO' => $fermO2, 'fermK' => 0.0, 'dap' => 0.0];
            $additions[] = ['timing' => $snaSchedule[1], 'fermO' => 0.0, 'fermK' => $fermK2, 'dap' => 0.0];
            $additions[] = ['timing' => $snaSchedule[2], 'fermO' => 0.0, 'fermK' => $lastFermK, 'dap' => 0.0];
            $additions[] = ['timing' => $snaSchedule[3], 'fermO' => $lastFermO, 'fermK' => 0.0, 'dap' => 0.0];
        } else {
            $fermK = round($nit * $vol / 50 / 2 * 10 * (($scheduleLength - 2) / $scheduleLength) / 10);
            $fermO = round($nit * $vol / 50 / 2 * 10) / 10;
            $fermO2 = round($fermO / 2 * 10) / 10;
            $lastFermO = round(($fermO - $fermO2) * 10) / 10;
            $fermKN = round($fermK / ($scheduleLength - 2) * 10) / 10;
            $lastFermK = round(($fermK - $fermKN * ($scheduleLength - 3)) * 10) / 10;

            $additions[] = ['timing' => $snaSchedule[0], 'fermO' => $fermO2, 'fermK' => 0.0, 'dap' => 0.0];
            for ($i = 1; $i < $scheduleLength - 2; $i++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $fermKN, 'dap' => 0.0];
            }
            $additions[] = [
                'timing' => $snaSchedule[$scheduleLength - 2],
                'fermO' => 0.0,
                'fermK' => $lastFermK,
                'dap' => 0.0,
            ];
            $additions[] = [
                'timing' => $snaSchedule[$scheduleLength - 1],
                'fermO' => $lastFermO,
                'fermK' => 0.0,
                'dap' => 0.0,
            ];
        }

        return ['nitrogen' => $nit, 'totalFermO' => $fermO, 'totalFermK' => $fermK, 'totalDAP' => 0.0, 'additions' => $additions];
    }

    /**
     * @param array<int, int|string> $snaSchedule
     * @return array<string, mixed>
     */
    public static function getFermKdap(float $vol, float $nit, array $snaSchedule, float $fermKYan): array
    {
        if ($nit > 250) {
            $nit = 250.0;
        }
        $scheduleLength = count($snaSchedule);
        $fk = round($nit * 0.2 / $fermKYan * 3.7854 * $vol * 10) / 10;
        $dap = round($nit * 0.8 / 210 * 3.7854 * $vol * 10) / 10;

        $fkN = round($fk / $scheduleLength * 10) / 10;
        $dapN = round($dap / $scheduleLength * 10) / 10;
        $lastFk = round(($fk - $fkN * ($scheduleLength - 1)) * 10) / 10;
        $lastDap = round(($dap - $dapN * ($scheduleLength - 1)) * 10) / 10;

        $additions = [];
        for ($i = 0; $i < $scheduleLength - 1; $i++) {
            $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $fkN, 'dap' => $dapN];
        }
        $additions[] = [
            'timing' => $snaSchedule[$scheduleLength - 1],
            'fermO' => 0.0,
            'fermK' => $lastFk,
            'dap' => $lastDap,
        ];

        return ['nitrogen' => $nit, 'totalFermO' => 0.0, 'totalFermK' => $fk, 'totalDAP' => $dap, 'additions' => $additions];
    }

    /**
     * Blount-Elliott style nutrient split across Fermaid O, Fermaid K, and DAP.
     *
     * @param array<int, int|string> $snaSchedule
     * @return array<string, mixed>
     */
    public static function getNutrients(
        float $vol,
        float $abv,
        float $yan,
        array $snaSchedule,
        float $fermKYan,
        float $fermOEffectiveness
    ): array {
        $vol = $vol * 3.784; // volume in liters
        $foe = $fermOEffectiveness;

        $foMaxGL = 0.45; // Lallemand recommendation
        $fkMaxGL = 0.5; // US legal limit
        $dapMaxGL = 0.96; // US legal limit

        $foMaxYan = $foMaxGL * 40 * $foe;
        $fkMaxYan = $fkMaxGL * $fermKYan;
        $dapMaxYan = $dapMaxGL * 210;

        $maxYan = $foMaxYan + $fkMaxYan + $dapMaxYan;
        $fofkMaxYan = $foMaxYan + $fkMaxYan;

        if ($yan > $maxYan) {
            $fkGL = $fkMaxGL;
            $dapGL = $dapMaxGL;
            $yanRem = $yan - ($fkMaxYan + $dapMaxYan);
            $foGL = $yanRem / (40 * $foe);
        } elseif ($yan > $fofkMaxYan) {
            $foGL = $foMaxGL;
            $fkGL = $fkMaxGL;
            $yanRem = $yan - ($fkMaxYan + $foMaxYan);
            $dapGL = $yanRem / 210;
        } elseif ($yan > $foMaxYan) {
            $foGL = $foMaxGL;
            $yanRem = $yan - $foMaxYan;
            $fkGL = $yanRem / $fermKYan;
            $dapGL = 0.0;
        } else {
            $foGL = $yan / (40 * $foe);
            $fkGL = 0.0;
            $dapGL = 0.0;
        }

        $fo = round($foGL * $vol * 10) / 10;
        $fk = round($fkGL * $vol * 10) / 10;
        $dap = round($dapGL * $vol * 10) / 10;

        $additions = [];
        $scheduleLength = count($snaSchedule);
        if ($scheduleLength % 2 === 0) {
            $half = (int) ($scheduleLength / 2);
            $foN = round($fo / $half * 10) / 10;
            $lastFo = round(($fo - $foN * ($half - 1)) * 10) / 10;
            $fkN = round($fk / $half * 10) / 10;
            $lastFk = round(($fk - $fkN * ($half - 1)) * 10) / 10;
            $dapN = round($dap / $half * 10) / 10;
            $lastDap = round(($dap - $dapN * ($half - 1)) * 10) / 10;

            for ($i = 0; $i < $half - 1; $i++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $foN, 'fermK' => 0.0, 'dap' => 0.0];
            }
            $additions[] = ['timing' => $snaSchedule[$half - 1], 'fermO' => $lastFo, 'fermK' => 0.0, 'dap' => 0.0];
            for ($i = $half; $i < $scheduleLength - 1; $i++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $fkN, 'dap' => $dapN];
            }
            $additions[] = [
                'timing' => $snaSchedule[$scheduleLength - 1],
                'fermO' => 0.0,
                'fermK' => $lastFk,
                'dap' => $lastDap,
            ];
        } else {
            $foN = round($fo / $scheduleLength * 10) / 10;
            $lastFo = round(($fo - $foN * ($scheduleLength - 1)) * 10) / 10;
            $fkN = round($fk / $scheduleLength * 10) / 10;
            $lastFk = round(($fk - $fkN * ($scheduleLength - 1)) * 10) / 10;
            $dapN = round($dap / $scheduleLength * 10) / 10;
            $lastDap = round(($dap - $dapN * ($scheduleLength - 1)) * 10) / 10;
            $half = $scheduleLength / 2;

            for ($i = 0; $i < $half - 1; $i++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => $foN * 2, 'fermK' => 0.0, 'dap' => 0.0];
            }
            $additions[] = [
                'timing' => $snaSchedule[(int) ceil($half) - 1],
                'fermO' => $lastFo,
                'fermK' => $lastFk,
                'dap' => $lastDap,
            ];
            for ($i = (int) ceil($half); $i < $scheduleLength; $i++) {
                $additions[] = ['timing' => $snaSchedule[$i], 'fermO' => 0.0, 'fermK' => $fkN * 2, 'dap' => $dapN * 2];
            }
        }

        return ['nitrogen' => $yan, 'totalFermO' => $fo, 'totalFermK' => $fk, 'totalDAP' => $dap, 'additions' => $additions];
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
