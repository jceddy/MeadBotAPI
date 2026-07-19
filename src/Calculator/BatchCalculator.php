<?php

declare(strict_types=1);

namespace MeadBotApi\Calculator;

/**
 * Ported from MeadBot's src/calculator/BatchCalculator.js — full-recipe orchestration
 * (honey/yeast/nutrients) shared by the build-batch and calculate-mead commands.
 */
final class BatchCalculator
{
    // pickSnaSchedule(yanRequirement, hot, og) - the default staggered-nutrient-addition
    // schedule for a batch, absent an explicit override
    //
    // @return array<int, int|string>
    public static function pickSnaSchedule(int $yanRequirement, bool $hot, float $og): array
    {
        if ($yanRequirement === Constants::YAN_REQUIREMENT_KVEIK) {
            return ['pitch'];
        }
        if ($hot) {
            if ($og >= 1.1) {
                return [24, 'break'];
            }
            if ($og >= 1.08) {
                return [24];
            }
            return ['pitch'];
        }
        if ($og >= 1.12) {
            return [24, 48, 72, 'break'];
        }
        if ($og >= 1.1) {
            return [24, 48, 'break'];
        }
        if ($og >= 1.08) {
            return [24, 'break'];
        }
        if ($og >= 1.06) {
            return [24];
        }
        return ['pitch'];
    }

    /**
     * buildBatch(options) - orchestrates !build-batch's full computation (target OG/FG/ABV, SNA
     * schedule selection, fruit YAN contribution, honey weight, yeast/Go-Ferm requirements, and
     * the nutrient schedule for whichever regimen was selected). See BatchCalculator.js's
     * buildBatch for the option field list; keys here are the same, camelCase. Returns
     * {error: true, errorMessage} on failure (fruitSg > og, or an unrecognized nutrientRegimen),
     * or a result object with the resolved og/fg/abv, yan/nutrient breakdown, honey/yeast/Go-Ferm
     * requirements, and (for the ADVANCED regimen) the full getAdvancedNutrients result.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function buildBatch(array $options): array
    {
        $units = $options['units'];
        $volume = $options['volume'];
        $yeastAbv = $options['yeastAbv'];
        $residualSugar = $options['residualSugar'];
        $yanRequirement = $options['yanRequirement'];
        $nutrientRegimen = $options['nutrientRegimen'];
        $ogOverride = $options['ogOverride'];
        $pitchRateOverride = $options['pitchRateOverride'];
        $fruitSg = $options['fruitSg'];
        $yanOverride = $options['yanOverride'];
        $fermOEffectiveness = $options['fermOEffectiveness'];
        $enforceLimits = $options['enforceLimits'];
        $dapLimit = $options['dapLimit'];
        $fermKLimit = $options['fermKLimit'];
        $fermOLimit = $options['fermOLimit'];
        $yanRatioDap = $options['yanRatioDap'];
        $yanRatioFermK = $options['yanRatioFermK'];
        $yanRatioFermO = $options['yanRatioFermO'];
        $fermKYan = $options['fermKYan'];
        $gofermYan = $options['gofermYan'];
        $fillFkFirst = $options['fillFkFirst'];
        $hot = $options['hot'];
        $snaScheduleOverride = $options['snaScheduleOverride'];

        $fg = $residualSugar;
        $abv = $yeastAbv;
        $bV = $volume;
        if ($units === Constants::UNITS_METRIC) {
            $bV = $bV / 3.784; // convert to gallons
        }

        if ($ogOverride > 0) {
            $og = $ogOverride;
            $sg = GravityCalculator::stormABVtoSG($abv);
            $fg = $og - $sg + 1;
            if ($fg < 1) {
                $fg = 1.0;
                $abv = CalculatorApi::convertGravityDropToABV($og);
            }
        } else {
            $sg = GravityCalculator::stormABVtoSG($abv);
            $og = $sg + $fg - 1;
        }

        $sgDelta = $og - $fg + 1;
        $yan = GravityCalculator::stormSGtoYAN($sgDelta, $yanRequirement);

        $snaSchedule = $snaScheduleOverride ?? self::pickSnaSchedule($yanRequirement, $hot, $og);

        $fruitPercent = 0.0;
        $fruitPercent100 = 0.0;
        $fruitYanContribution = 0.0;
        if ($fruitSg > 0) {
            if ($fruitSg > $og) {
                return [
                    'error' => true,
                    'errorMessage' => sprintf(
                        "Fruit SG can't be higher than OG. (%.3f > %.3f)",
                        $fruitSg,
                        $og
                    ),
                ];
            }
            $fruitPercent = ($fruitSg - 1) / ($og - 1);
            if ($yanRequirement === Constants::YAN_REQUIREMENT_KVEIK) {
                $fruitPercent = $fruitPercent / 1.5;
            }
            $fruitPercent100 = $fruitPercent * 100;
            $fruitYanContribution = floor($yan * $fruitPercent);
            $yan -= $fruitYanContribution;
        }

        $ho = NutrientCalculator::hoCalc($og, $bV, $units);
        if ($ho === null) {
            return ['error' => true, 'errorMessage' => 'Error calculating Honey Weight: Unknown Units.'];
        }
        $og = round($og * 1000) / 1000;

        if ($pitchRateOverride > 0) {
            $yst = $pitchRateOverride * $volume;
            $numPacket = (int) ceil($yst / 5);
            $gfGrams = 1.25 * $numPacket * 5;
            $reh = $gfGrams * 20;
            $gf = [$yst, $numPacket, $gfGrams, $reh];
        } else {
            $gf = NutrientCalculator::getGoferm($bV, $og, $fruitYanContribution);
        }

        $gofermYanContribution = NutrientCalculator::yanContributionFromGrams($gf[2], $gofermYan, $bV * 3.784);
        $yan -= $gofermYanContribution;

        $honeyWeight = $ho[0];
        if ($honeyWeight > 100) {
            $honeyWeight = round($honeyWeight);
        } elseif ($honeyWeight > 10) {
            $honeyWeight = round($honeyWeight * 10) / 10;
        } else {
            $honeyWeight = round($honeyWeight * 100) / 100;
        }

        $advancedNutrients = null;
        switch ($nutrientRegimen) {
            case Constants::NUTRIENT_REGIMEN_TOSNA:
                $nut = NutrientCalculator::getFermO($bV, $yan, $snaSchedule);
                break;
            case Constants::NUTRIENT_REGIMEN_K_DAP:
                $nut = NutrientCalculator::getFermKdap($bV, $yan, $snaSchedule, $fermKYan);
                break;
            case Constants::NUTRIENT_REGIMEN_BLOUNT_ELLIOTT:
                $nut = NutrientCalculator::getNutrients($bV, $abv, $yan, $snaSchedule, $fermKYan, $fermOEffectiveness);
                break;
            case Constants::NUTRIENT_REGIMEN_TOSNA_K:
                $nut = NutrientCalculator::getFermK($bV, $yan, $snaSchedule);
                break;
            case Constants::NUTRIENT_REGIMEN_O_K:
                $nut = NutrientCalculator::getFermOK($bV, $yan, $snaSchedule);
                break;
            case Constants::NUTRIENT_REGIMEN_ADVANCED:
                $advYan = $yanOverride > 0 ? $yanOverride - $gofermYanContribution : $yan;
                $advancedNutrients = NutrientCalculator::getAdvancedNutrients(
                    $units,
                    $volume,
                    $advYan,
                    $fermOEffectiveness,
                    $enforceLimits,
                    $dapLimit,
                    $fermKLimit,
                    $fermOLimit,
                    $yanRatioDap,
                    $yanRatioFermK,
                    $yanRatioFermO,
                    $snaSchedule,
                    $fermKYan,
                    $fillFkFirst
                );
                $nut = $advancedNutrients['sna'];
                break;
            default:
                return ['error' => true, 'errorMessage' => 'Calculation failed: Unknown Nutrient Regimen.'];
        }

        $ogPts = $og - 1;
        $fgPts = $fg - 1;
        $sgDiff = $ogPts - $fgPts;
        $break3 = 1 + ($fgPts + ($sgDiff * 2) / 3);

        return [
            'error' => false,
            'volume' => $volume,
            'units' => $units,
            'og' => $og,
            'fg' => $fg,
            'abv' => $abv,
            'yanRequirement' => $yanRequirement,
            'nutrientRegimen' => $nutrientRegimen,
            'gofermYanContribution' => $gofermYanContribution,
            'fruitPercent' => $fruitPercent,
            'fruitPercent100' => $fruitPercent100,
            'fruitYanContribution' => $fruitYanContribution,
            'honey' => ['weight' => $honeyWeight, 'unit' => $ho[1]],
            'goferm' => ['minGrams' => $gf[0], 'numPackets' => $gf[1], 'grams' => $gf[2], 'dilutionWaterMl' => $gf[3]],
            'nut' => $nut,
            'advancedNutrients' => $advancedNutrients,
            'break3' => $break3,
        ];
    }
}
