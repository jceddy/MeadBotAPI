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

    // unitDefaults(units) - the must-temperature/target-volume defaults that !calculate-mead's
    // own -u/--units handling sets as a side effect of selecting metric/us/imperial
    //
    // @return array<string, mixed>
    public static function unitDefaults(int $units): array
    {
        return match ($units) {
            Constants::UNITS_METRIC => [
                'mustTemperature' => 20.0,
                'mustTemperatureUnits' => Constants::TEMPERATURE_UNIT_CELSIUS,
                'targetVolume' => 18.93,
                'targetVolumeUnits' => Constants::VOLUME_UNIT_LITERS,
                'currentVolumeUnits' => Constants::VOLUME_UNIT_LITERS,
            ],
            Constants::UNITS_IMPERIAL => [
                'mustTemperature' => 68.0,
                'mustTemperatureUnits' => Constants::TEMPERATURE_UNIT_FAHRENHEIT,
                'targetVolume' => 5.0,
                'targetVolumeUnits' => Constants::VOLUME_UNIT_GALLONS_IMP,
                'currentVolumeUnits' => Constants::VOLUME_UNIT_GALLONS_IMP,
            ],
            default => [
                'mustTemperature' => 68.0,
                'mustTemperatureUnits' => Constants::TEMPERATURE_UNIT_FAHRENHEIT,
                'targetVolume' => 5.0,
                'targetVolumeUnits' => Constants::VOLUME_UNIT_GALLONS_US,
                'currentVolumeUnits' => Constants::VOLUME_UNIT_GALLONS_US,
            ],
        };
    }

    // pickMeadSnaSchedule(yanRequirement, hot, og, targetStepFeedGravity, numberOfSteps) - the
    // default staggered-nutrient-addition schedule for !calculate-mead, which (unlike
    // pickSnaSchedule) has its own step-feeding-aware branch
    //
    // @return array<int, int|string>
    public static function pickMeadSnaSchedule(
        int $yanRequirement,
        bool $hot,
        float $og,
        ?float $targetStepFeedGravity,
        int $numberOfSteps
    ): array {
        if ($targetStepFeedGravity !== null) {
            if ($yanRequirement === Constants::YAN_REQUIREMENT_KVEIK) {
                $schedule = ['pitch'];
                for ($j = 0; $j < $numberOfSteps + 1; $j++) {
                    $schedule[] = 24 + 24 * $j;
                }
                return $schedule;
            }
            if ($hot) {
                $schedule = [24];
                for ($j = 0; $j <= $numberOfSteps + 1; $j++) {
                    $schedule[] = 48 + 24 * $j;
                }
                return $schedule;
            }
            $schedule = ['24,0', '48,0', '72,0'];
            if ($numberOfSteps >= 1) {
                $schedule[] = '24,1';
            }
            if ($numberOfSteps >= 2) {
                $schedule[] = '48,1';
                $schedule[] = '72,1';
                $schedule[] = '24,2';
            }
            return $schedule;
        }
        return self::pickSnaSchedule($yanRequirement, $hot, $og);
    }

    /**
     * calculateMead(options) - orchestrates !calculate-mead's full computation: resolving a
     * target OG/FG/ABV/volume (any two of gravity/volume/ABV may be given, with the third solved
     * for; sugar quantity is solved for when both gravity and volume are given without a full
     * sugar specification), optional step-feeding, fruit/grain YAN contribution, and the nutrient
     * schedule. See BatchCalculator.js's calculateMead for the option field list; keys here are
     * the same, camelCase — targetGravity/targetGravityUnits/targetAbv/targetAbvUnits/
     * targetVolume/targetVolumeUnits/currentGravity/currentGravityUnits/currentVolume/
     * currentVolumeUnits/targetStepFeedGravity are nullable (null means "not specified, use the
     * default"), and additionalSugars is either null or an array of sugar arrays, each with keys
     * type, quantity_amount, quantity_amount_specified, quantity_units, sugar_content,
     * yan_multiplier, additive.
     *
     * Diverges from the MeadBot command in one place: the `additionalSugars` entries this
     * function builds internally (when the caller didn't supply any) always include an
     * `additive` key and spell `quantity_amount_specified` correctly — the original command's
     * equivalent code has a typo (`quanity_amount_specified`) and omits `additive` in one branch,
     * which doesn't affect any computed number but would be a confusing typo to reproduce in a
     * fresh API response.
     *
     * Returns {error: true, errorMessage} on failure, or a result object with the resolved
     * targets, step-feeding info (null unless targetStepFeedGravity was given), Go-Ferm/nutrient
     * breakdown, and the full getAdvancedNutrients result.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function calculateMead(array $options): array
    {
        $units = $options['units'];
        $mustTemperatureInput = $options['mustTemperature'];
        $mustTemperatureUnitsInput = $options['mustTemperatureUnits'];
        $targetGravityInput = $options['targetGravity'];
        $targetGravityUnitsInput = $options['targetGravityUnits'];
        $targetAbvInput = $options['targetAbv'];
        $targetAbvUnitsInput = $options['targetAbvUnits'];
        $targetVolumeInput = $options['targetVolume'];
        $targetVolumeUnitsInput = $options['targetVolumeUnits'];
        $additionalSugarsInput = $options['additionalSugars'];
        $currentGravityInput = $options['currentGravity'];
        $currentGravityUnitsInput = $options['currentGravityUnits'];
        $currentVolumeInput = $options['currentVolume'];
        $currentVolumeUnitsInput = $options['currentVolumeUnits'];
        $targetStepFeedGravity = $options['targetStepFeedGravity'];
        $yeastAbv = $options['yeastAbv'];
        $yanRequirement = $options['yanRequirement'];
        $hot = $options['hot'];
        $calculateAdditiveHoney = $options['calculateAdditiveHoney'];
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
        $useGoferm = $options['useGoferm'];
        $yeastPackGrams = $options['yeastPackGrams'];

        $defaults = self::unitDefaults($units);

        $targetGravitySpecified = $targetGravityInput !== null;
        $targetVolumeSpecified = $targetVolumeInput !== null;
        $targetAbvSpecified = $targetAbvInput !== null;
        $currentGravitySpecified = $currentGravityInput !== null;
        $currentVolumeSpecified = $currentVolumeInput !== null;

        $currentGravityUnits = $currentGravityUnitsInput ?? Constants::GRAVITY_UNIT_SG;
        $currentGravity = $currentGravityInput ?? 1.0;
        $currentVolumeUnits = $currentVolumeUnitsInput ?? $defaults['currentVolumeUnits'];
        $currentVolume = $currentVolumeInput ?? 0.0;
        $targetGravityUnits = $targetGravityUnitsInput ?? Constants::GRAVITY_UNIT_SG;
        $targetGravity = $targetGravityInput ?? 1.108;
        $targetVolumeUnits = $targetVolumeUnitsInput ?? $defaults['targetVolumeUnits'];
        $targetVolume = $targetVolumeInput ?? $defaults['targetVolume'];
        $targetAbvUnits = $targetAbvUnitsInput ?? Constants::ABV_UNIT_ABV;
        $targetAbv = $targetAbvInput ?? 14.13;
        $mustTemperature = $mustTemperatureInput ?? $defaults['mustTemperature'];
        $mustTemperatureUnits = $mustTemperatureUnitsInput ?? $defaults['mustTemperatureUnits'];
        $additionalSugars = $additionalSugarsInput;

        $additionalSugarsVolumeCheck = 0.0;
        if ($additionalSugars !== null) {
            foreach ($additionalSugars as &$sugar) {
                $targetLiters =
                    ($sugar['quantity_amount'] / Constants::HONEY_UNIT_INFO[Constants::HONEY_UNIT_LITERS]['conversion']) *
                    Constants::HONEY_UNIT_INFO[$sugar['quantity_units']]['conversion'];
                $sugarVolumeInTargetVolumeUnits = $targetLiters / Constants::VOLUME_UNIT_INFO[$targetVolumeUnits]['conversion'];

                if ($sugar['additive']) {
                    $targetVolume += $sugarVolumeInTargetVolumeUnits;
                }
                $additionalSugarsVolumeCheck += $sugarVolumeInTargetVolumeUnits;
            }
            unset($sugar);
        }
        if ($additionalSugarsVolumeCheck >= $targetVolume) {
            $targetVolumeUnitName = Constants::VOLUME_UNIT_INFO[$targetVolumeUnits]['name'];
            return [
                'error' => true,
                'errorMessage' => sprintf(
                    "Total sugar volume (%.3f %s) is greater than total target volume (%.3f %s).\nIt must be less than or equal.",
                    $additionalSugarsVolumeCheck,
                    $targetVolumeUnitName,
                    $targetVolume,
                    $targetVolumeUnitName
                ),
            ];
        }

        $vSugar = 0.0;
        $vCSg = GravityCalculator::convToSG($currentGravity, $currentGravityUnits);
        $vCVol = GravityCalculator::toVol($currentVolumeUnits, $currentVolume);
        $vTSg = GravityCalculator::convToSG($targetGravity, $targetGravityUnits);
        $vTVol = GravityCalculator::toVol($targetVolumeUnits, $targetVolume);
        $tempCoeff = GravityCalculator::getTempCoeff($mustTemperature, $mustTemperatureUnits);
        $vTSfSg = null;
        if ($targetStepFeedGravity !== null) {
            $vTSfSg = GravityCalculator::convToSG($targetStepFeedGravity, $targetGravityUnits);
            if ($targetGravitySpecified && $vTSfSg <= $vTSg) {
                return [
                    'error' => true,
                    'errorMessage' => 'target_step_feed_gravity should be greater than target_gravity',
                ];
            }
        }

        $dryFg = CalculatorApi::estimateDryFG($vTSg);

        if ($targetStepFeedGravity !== null) {
            $targetAbv = CalculatorApi::convertGravityDropToABV($vTSfSg);
            if ($targetAbvUnits === Constants::ABV_UNIT_ABW) {
                $targetAbv = GravityCalculator::abvToABW($targetAbv);
            }
            if ($targetAbv > $yeastAbv) {
                $targetAbv = $yeastAbv;
            }
        } elseif ($targetGravitySpecified) {
            $targetAbv = CalculatorApi::convertGravityDropToABV($vTSg - $dryFg + 1);
            if ($targetAbvUnits === Constants::ABV_UNIT_ABW) {
                $targetAbv = GravityCalculator::abvToABW($targetAbv);
            }
            if ($targetAbv > $yeastAbv) {
                $targetAbv = $yeastAbv;
            }
        } elseif ($targetAbvSpecified) {
            $vTSg = $targetAbvUnits === Constants::ABV_UNIT_ABW
                ? GravityCalculator::stormABVtoSG(GravityCalculator::abwToABV($targetAbv))
                : GravityCalculator::stormABVtoSG($targetAbv);

            $dryFg = CalculatorApi::estimateDryFG($vTSg);
            $vTSg = $vTSg + $dryFg - 1;
            $targetGravity = $vTSg;
            $targetGravitySpecified = true; // we just specified it
        }

        $unspecifiedSugar = false;
        if ($additionalSugars !== null) {
            foreach ($additionalSugars as $sugar) {
                if ($sugar['quantity_amount_specified']) {
                    $vSugar += GravityCalculator::getSugars($sugar);
                } else {
                    $unspecifiedSugar = true;
                }
            }
        }

        // do the calculations
        if ($targetVolumeSpecified && !$targetGravitySpecified) {
            $mygpl = ($vSugar * 1000 + GravityCalculator::sgToSugarConc($vCSg - $tempCoeff) * $vCVol) / $vTVol;
            $vTSg = GravityCalculator::sugarConcToSG($mygpl) + $tempCoeff;
            $targetGravity = GravityCalculator::doConvertSG(Constants::GRAVITY_UNIT_SG, $targetGravityUnits, $vTSg);
            $targetGravity = round($targetGravity * 1000) / 1000;

            $dryFg = CalculatorApi::estimateDryFG($vTSg);

            if ($targetStepFeedGravity === null) {
                $targetAbv = $targetAbvUnits === Constants::ABV_UNIT_ABW
                    ? GravityCalculator::abvToABW(CalculatorApi::convertGravityDropToABV($vTSg - $dryFg + 1))
                    : CalculatorApi::convertGravityDropToABV($vTSg - $dryFg + 1);

                if ($targetAbv > $yeastAbv) {
                    $targetAbv = $yeastAbv;
                }
            } elseif ($vTSfSg <= $vTSg) {
                return [
                    'error' => true,
                    'errorMessage' => 'target_step_feed_gravity should be greater than target_gravity',
                ];
            }
        } elseif (!$targetVolumeSpecified && $targetGravitySpecified && $additionalSugars !== null) {
            $vTVol = ($vSugar * 1000 + GravityCalculator::sgToSugarConc($vCSg) * $vCVol) / GravityCalculator::sgToSugarConc($vTSg);
            $targetVolume = $vTVol / Constants::VOLUME_UNIT_INFO[$targetVolumeUnits]['conversion'];
        } elseif ($targetGravitySpecified && $targetVolumeSpecified && ($additionalSugars === null || $unspecifiedSugar)) {
            // calculate sugar quantity - we either add a honey specification, or specify an
            // unspecified sugar
            $myQuant =
                (GravityCalculator::sgToSugarConc($vTSg) * $vTVol) / 1000 -
                (GravityCalculator::sgToSugarConc($vCSg) * $vCVol) / 1000 -
                $vSugar;
            if ($additionalSugars === null) {
                if ($calculateAdditiveHoney) {
                    $honeyAmount = 0.0;
                    $quantityUnits =
                        $units === Constants::UNITS_METRIC ? Constants::HONEY_UNIT_KILOGRAMS : Constants::HONEY_UNIT_POUNDS;
                    $sugarContent = Constants::SUGAR_SOURCE_INFO[Constants::SUGAR_SOURCES['HONEY']]['percent'];
                    $yanMultiplier = Constants::SUGAR_SOURCE_INFO[Constants::SUGAR_SOURCES['HONEY']]['yan'];

                    $testQuant = function (float $amount) use (
                        $vTSg,
                        $vTVol,
                        $quantityUnits,
                        $vCSg,
                        $vCVol,
                        $vSugar,
                        $sugarContent
                    ): float {
                        return (GravityCalculator::sgToSugarConc($vTSg) *
                            ($vTVol +
                                ($amount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion']) /
                                    Constants::HONEY_UNIT_INFO[Constants::HONEY_UNIT_LITERS]['conversion'])) /
                            1000 -
                            (GravityCalculator::sgToSugarConc($vCSg) * $vCVol) / 1000 -
                            $vSugar -
                            ($amount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion'] * $sugarContent) / 100;
                    };

                    while ($testQuant($honeyAmount) > 0) {
                        $honeyAmount += 0.001;
                    }

                    $additionalSugars = [
                        [
                            'type' => Constants::SUGAR_SOURCES['HONEY'],
                            'quantity_amount' => $honeyAmount,
                            'quantity_amount_specified' => true,
                            'quantity_units' => $quantityUnits,
                            'sugar_content' => $sugarContent,
                            'yan_multiplier' => $yanMultiplier,
                            'additive' => true,
                        ],
                    ];

                    $vTVol +=
                        ($honeyAmount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion']) /
                        Constants::HONEY_UNIT_INFO[Constants::HONEY_UNIT_LITERS]['conversion'];
                    $targetVolume +=
                        ($honeyAmount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion']) /
                        Constants::HONEY_UNIT_INFO[GravityCalculator::volumeUnitsToHoneyUnits($targetVolumeUnits)]['conversion'];
                    $vSugar += ($honeyAmount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion'] * $sugarContent) / 100;
                } else {
                    $quantityUnits =
                        $units === Constants::UNITS_METRIC ? Constants::HONEY_UNIT_KILOGRAMS : Constants::HONEY_UNIT_POUNDS;
                    $sugarContent = Constants::SUGAR_SOURCE_INFO[Constants::SUGAR_SOURCES['HONEY']]['percent'];
                    $yanMultiplier = Constants::SUGAR_SOURCE_INFO[Constants::SUGAR_SOURCES['HONEY']]['yan'];

                    $additionalSugars = [
                        [
                            'type' => Constants::SUGAR_SOURCES['HONEY'],
                            'quantity_amount' => round(
                                ($myQuant /
                                    ((Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion'] * $sugarContent) / 100)) *
                                    1000
                            ) / 1000,
                            'quantity_amount_specified' => true,
                            'quantity_units' => $quantityUnits,
                            'sugar_content' => $sugarContent,
                            'yan_multiplier' => $yanMultiplier,
                            'additive' => false,
                        ],
                    ];
                }
            } else {
                $foundSugar = false;
                foreach ($additionalSugars as &$sugar) {
                    if (!$sugar['quantity_amount_specified']) {
                        if ($foundSugar) {
                            $sugar['quantity_amount'] = 0.0;
                        } else {
                            $sugar['quantity_amount'] = round(
                                ($myQuant /
                                    ((Constants::HONEY_UNIT_INFO[$sugar['quantity_units']]['conversion'] * $sugar['sugar_content']) / 100)) *
                                    1000
                            ) / 1000;
                            $foundSugar = true;
                        }
                        $sugar['quantity_amount_specified'] = true;
                    }
                }
                unset($sugar);
            }
        }

        $numberOfSteps = 0;
        $honeyVolsPerStep = 0.0;
        $honeyStepUnits = $units === Constants::UNITS_METRIC ? Constants::HONEY_UNIT_KILOGRAMS : Constants::HONEY_UNIT_POUNDS;
        $volumeAfterSteps = 0.0;
        $stepAddSg = $vTSg;
        if ($targetStepFeedGravity !== null) {
            if ($vTSg < 1.08) {
                return ['error' => true, 'errorMessage' => 'Step feeding is not recommended for OG < 1.080.'];
            }

            $stepSgDiff = $vTSfSg - $vTSg;
            $numberOfSteps = min((int) ceil($stepSgDiff / 0.04), 3);

            $honeyPounds = 0.0;
            $quantityUnits = Constants::HONEY_UNIT_POUNDS;
            $sugarContent = Constants::SUGAR_SOURCE_INFO[Constants::SUGAR_SOURCES['HONEY']]['percent'];

            $testQuant = function (float $amount) use (
                $vTSfSg,
                $vTVol,
                $quantityUnits,
                $vCSg,
                $vCVol,
                $vSugar,
                $sugarContent
            ): float {
                return (GravityCalculator::sgToSugarConc($vTSfSg) *
                    ($vTVol +
                        ($amount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion']) /
                            Constants::HONEY_UNIT_INFO[Constants::HONEY_UNIT_LITERS]['conversion'])) /
                    1000 -
                    (GravityCalculator::sgToSugarConc($vCSg) * $vCVol) / 1000 -
                    $vSugar -
                    ($amount * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion'] * $sugarContent) / 100;
            };

            while ($testQuant($honeyPounds) > 0) {
                $honeyPounds += 0.001;
            }

            $honeyVolsPerStep = $honeyStepUnits === Constants::HONEY_UNIT_POUNDS
                ? $honeyPounds / $numberOfSteps
                : ($honeyPounds * Constants::HONEY_UNIT_INFO[Constants::HONEY_UNIT_POUNDS]['conversion']) / $numberOfSteps;

            $volumeAfterSteps =
                $targetVolume +
                ($honeyPounds * Constants::HONEY_UNIT_INFO[$quantityUnits]['conversion']) /
                    Constants::HONEY_UNIT_INFO[GravityCalculator::volumeUnitsToHoneyUnits($targetVolumeUnits)]['conversion'];
            $stepAddSg = $stepAddSg - $stepSgDiff / $numberOfSteps - 0.01;
        }

        // values needed for yan calculations
        $og = $targetStepFeedGravity === null
            ? GravityCalculator::convToSG($targetGravity, $targetGravityUnits)
            : GravityCalculator::convToSG($targetStepFeedGravity, $targetGravityUnits);

        $fg =
            $og -
            GravityCalculator::stormABVtoSG(
                $targetAbvUnits === Constants::ABV_UNIT_ABV ? $targetAbv : GravityCalculator::abwToABV($targetAbv)
            ) +
            1;
        if ($fg < $dryFg) {
            $fg = $dryFg;
        }
        $gravityDropSg = $og - $fg + 1;

        // apply yan from fruit/grain...
        $fruitYanConcentrationPercent = 0.0;
        if ($additionalSugars !== null) {
            foreach ($additionalSugars as $sugar) {
                if ($sugar['yan_multiplier'] !== 0) {
                    $sSugar = GravityCalculator::getSugars($sugar);
                    $sGpl = ($sSugar * 1000 + GravityCalculator::sgToSugarConc($vCSg - $tempCoeff) * $vCVol) / $vTVol;
                    $sSG = GravityCalculator::sugarConcToSG($sGpl) + $tempCoeff;

                    $fruitPercent = ($sugar['yan_multiplier'] * ($sSG - 1)) / ($gravityDropSg - 1);
                    if ($yanRequirement === Constants::YAN_REQUIREMENT_KVEIK) {
                        $fruitPercent = $fruitPercent / 1.5;
                    }

                    $fruitYanConcentrationPercent += $fruitPercent;
                }
            }
        }

        // now calculate nutrients
        $targetFinalGravity = $fg;
        if ($targetGravityUnits === Constants::GRAVITY_UNIT_BAUME) {
            $targetFinalGravity = GravityCalculator::sgToBaume($fg);
        } elseif ($targetGravityUnits === Constants::GRAVITY_UNIT_BRIX) {
            $targetFinalGravity = CalculatorApi::convertSGToBrix($fg);
        }

        $deltaFG = $og - $fg + 1;
        if ($targetStepFeedGravity !== null) {
            $deltaFG += 0.01;
        }
        $yan = GravityCalculator::stormSGtoYAN($deltaFG, $yanRequirement);
        $fruitYanContribution = floor($yan * $fruitYanConcentrationPercent);
        $yan -= $fruitYanContribution;

        $liters =
            ($targetStepFeedGravity === null ? $targetVolume : $volumeAfterSteps) *
            Constants::VOLUME_UNIT_INFO[$targetVolumeUnits]['conversion'];
        $gallons = $liters / 3.784;

        $snaSchedule = self::pickMeadSnaSchedule($yanRequirement, $hot, $og, $targetStepFeedGravity, $numberOfSteps);

        $gf = NutrientCalculator::getGoferm(
            $gallons,
            $og,
            $fruitYanContribution,
            $useGoferm,
            $yeastPackGrams,
            $yanRequirement === Constants::YAN_REQUIREMENT_KVEIK
        );
        $gofermYanContribution = floor(($gf[2] * $gofermYan) / $liters);
        $yan -= $gofermYanContribution;
        if ($yan < 0) {
            $yan = 0.0;
        }

        $nutrients = NutrientCalculator::getAdvancedNutrients(
            Constants::UNITS_METRIC,
            $liters,
            $yan,
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

        $ogPts = $og - 1;
        $fgPts = $fg - 1;
        $sgDiff = $ogPts - $fgPts;
        $break3 = 1 + ($fgPts + ($sgDiff * 2) / 3);

        return [
            'error' => false,
            'mustTemperature' => $mustTemperature,
            'mustTemperatureUnits' => $mustTemperatureUnits,
            'targetGravity' => $targetGravity,
            'targetGravityUnits' => $targetGravityUnits,
            'targetStepFeedGravity' => $targetStepFeedGravity,
            'targetFinalGravity' => $targetFinalGravity,
            'targetAbv' => $targetAbv,
            'targetAbvUnits' => $targetAbvUnits,
            'targetVolume' => $targetVolume,
            'targetVolumeUnits' => $targetVolumeUnits,
            'stepFeeding' => $targetStepFeedGravity === null ? null : [
                'volumeAfterSteps' => $volumeAfterSteps,
                'honeyVolsPerStep' => $honeyVolsPerStep,
                'numberOfSteps' => $numberOfSteps,
                'stepAddSg' => $stepAddSg,
            ],
            'currentGravity' => $currentGravity,
            'currentGravityUnits' => $currentGravityUnits,
            'currentGravitySpecified' => $currentGravitySpecified,
            'currentVolume' => $currentVolume,
            'currentVolumeUnits' => $currentVolumeUnits,
            'currentVolumeSpecified' => $currentVolumeSpecified,
            'additionalSugars' => $additionalSugars,
            'goferm' => [
                'minGrams' => $gf[0],
                'numPackets' => $gf[1],
                'grams' => $gf[2],
                'dilutionWaterMl' => $gf[3],
                'yeastPackGrams' => $gf[4],
            ],
            'gofermYanContribution' => $gofermYanContribution,
            'fruitYanContribution' => $fruitYanContribution,
            'nutrients' => $nutrients,
            'break3' => $break3,
        ];
    }
}
