<?php

declare(strict_types=1);

namespace MeadBotApi\Tests;

use MeadBotApi\Calculator\BatchCalculator;
use MeadBotApi\Calculator\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Reference values were cross-checked against MeadBot's BatchCalculator.js under Node.js,
 * calling buildBatch() directly (bypassing CLI parsing) with equivalent structured options, and
 * programmatically diffed byte-for-byte identical across 16 cases.
 */
final class BatchCalculatorTest extends TestCase
{
    private const BASE_OPTIONS = [
        'units' => Constants::UNITS_US,
        'volume' => 5.0,
        'yeastAbv' => 18.0,
        'residualSugar' => 1.02,
        'yanRequirement' => Constants::YAN_REQUIREMENT_MEDIUM,
        'nutrientRegimen' => Constants::NUTRIENT_REGIMEN_BLOUNT_ELLIOTT,
        'ogOverride' => 0.0,
        'pitchRateOverride' => 0.0,
        'fruitSg' => 0.0,
        'yanOverride' => 0.0,
        'fermOEffectiveness' => 2.6,
        'enforceLimits' => true,
        'dapLimit' => 0.96,
        'fermKLimit' => 0.5,
        'fermOLimit' => 0.45,
        'yanRatioDap' => 35.0,
        'yanRatioFermK' => 25.0,
        'yanRatioFermO' => 40.0,
        'fermKYan' => 134.0,
        'gofermYan' => 77.0,
        'fillFkFirst' => true,
        'hot' => false,
        'snaScheduleOverride' => null,
    ];

    public function testBuildBatchMatchesDefaultBuildBatchCommand(): void
    {
        $result = BatchCalculator::buildBatch(self::BASE_OPTIONS);

        self::assertFalse($result['error']);
        self::assertSame(1.162, $result['og']);
        self::assertSame(1.02, $result['fg']);
        self::assertSame(18.0, $result['abv']);
        self::assertSame(76.0, $result['gofermYanContribution']);
        self::assertSame(23.1, $result['honey']['weight']);
        self::assertSame('lbs', $result['honey']['unit']);
        self::assertSame(3, $result['goferm']['numPackets']);
        self::assertCount(4, $result['nut']['additions']);
        self::assertNull($result['advancedNutrients']);
    }

    public function testBuildBatchClampsFgAndRecomputesAbvOnOgOverride(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, [
            'yeastAbv' => 20.0,
            'ogOverride' => 1.05,
        ]));

        self::assertFalse($result['error']);
        self::assertSame(1.05, $result['og']);
        self::assertSame(1.0, $result['fg']);
        self::assertLessThan(20.0, $result['abv']);
    }

    public function testBuildBatchErrorsWhenFruitSgExceedsOg(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, ['fruitSg' => 1.2]));

        self::assertTrue($result['error']);
        self::assertMatchesRegularExpression("/Fruit SG can't be higher than OG/", $result['errorMessage']);
    }

    public function testBuildBatchSubtractsFruitYanContribution(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, ['fruitSg' => 1.01]));

        self::assertFalse($result['error']);
        self::assertGreaterThan(0, $result['fruitPercent']);
        self::assertGreaterThan(0, $result['fruitYanContribution']);
    }

    public function testBuildBatchUsesFixedPitchRateWhenOverridden(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, ['pitchRateOverride' => 4.0]));

        self::assertFalse($result['error']);
        self::assertSame(4.0 * 5.0, $result['goferm']['minGrams']);
    }

    public function testBuildBatchPopulatesAdvancedNutrientsOnlyForAdvancedRegimen(): void
    {
        $advanced = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, [
            'nutrientRegimen' => Constants::NUTRIENT_REGIMEN_ADVANCED,
        ]));
        self::assertFalse($advanced['error']);
        self::assertNotNull($advanced['advancedNutrients']);

        $tosna = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, [
            'nutrientRegimen' => Constants::NUTRIENT_REGIMEN_TOSNA,
        ]));
        self::assertNull($tosna['advancedNutrients']);
    }

    public function testBuildBatchOverridesYanForAdvancedRegimen(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, [
            'nutrientRegimen' => Constants::NUTRIENT_REGIMEN_ADVANCED,
            'yanOverride' => 250.0,
        ]));

        self::assertFalse($result['error']);
        self::assertSame(250.0, $result['nut']['nitrogen'] + $result['gofermYanContribution']);
    }

    public function testBuildBatchHandlesMetricUnits(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, [
            'units' => Constants::UNITS_METRIC,
            'volume' => 18.9,
        ]));

        self::assertFalse($result['error']);
        self::assertSame('kg', $result['honey']['unit']);
    }

    public function testBuildBatchRespectsSnaScheduleOverride(): void
    {
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, [
            'snaScheduleOverride' => ['pitch', 24, 48, 'break'],
        ]));

        self::assertFalse($result['error']);
        self::assertCount(4, $result['nut']['additions']);
        self::assertSame('pitch', $result['nut']['additions'][0]['timing']);
    }

    public function testBuildBatchWithOgOverrideAndThreeElementSchedule(): void
    {
        // og=1.1 falls in the [24, 48, 'break'] (3-element, odd-length) schedule band — this is
        // the exact combination that exposed the getNutrients loop-bound regression.
        $result = BatchCalculator::buildBatch(array_merge(self::BASE_OPTIONS, ['ogOverride' => 1.1]));

        self::assertFalse($result['error']);
        self::assertCount(3, $result['nut']['additions']);
        self::assertSame(24, $result['nut']['additions'][0]['timing']);
        self::assertSame(48, $result['nut']['additions'][1]['timing']);
        self::assertSame('break', $result['nut']['additions'][2]['timing']);
    }

    /**
     * calculateMead() reference values were cross-checked against MeadBot's BatchCalculator.js
     * under Node.js, calling calculateMead() directly (bypassing CLI parsing) with equivalent
     * structured options, and programmatically diffed byte-for-byte identical across 12 cases
     * spanning unit systems, step feeding, additional sugars, and error paths. That same
     * cross-check surfaced a real bug (not introduced by this port): NutrientCalculator's
     * getAdvancedNutrients() divided by $yan unconditionally to compute yan_ratio_*, which JS
     * quietly turns into NaN (serialized as null) when $yan is exactly 0, but which PHP 8 throws
     * DivisionByZeroError for — see the targetVolumeOnly case below, and the fix in
     * NutrientCalculator::getAdvancedNutrients().
     */
    private const MEAD_BASE_OPTIONS = [
        'units' => Constants::UNITS_US,
        'mustTemperature' => null,
        'mustTemperatureUnits' => null,
        'targetGravity' => null,
        'targetGravityUnits' => null,
        'targetAbv' => null,
        'targetAbvUnits' => null,
        'targetVolume' => null,
        'targetVolumeUnits' => null,
        'additionalSugars' => null,
        'currentGravity' => null,
        'currentGravityUnits' => null,
        'currentVolume' => null,
        'currentVolumeUnits' => null,
        'targetStepFeedGravity' => null,
        'yeastAbv' => 18.0,
        'yanRequirement' => Constants::YAN_REQUIREMENT_MEDIUM,
        'hot' => false,
        'calculateAdditiveHoney' => false,
        'fermOEffectiveness' => 2.6,
        'enforceLimits' => true,
        'dapLimit' => 0.96,
        'fermKLimit' => 0.5,
        'fermOLimit' => 0.45,
        'yanRatioDap' => 35.0,
        'yanRatioFermK' => 25.0,
        'yanRatioFermO' => 40.0,
        'fermKYan' => 134.0,
        'gofermYan' => 77.0,
        'fillFkFirst' => true,
        'useGoferm' => true,
        'yeastPackGrams' => 5.0,
    ];

    public function testCalculateMeadMatchesDefaultCommandOutput(): void
    {
        $result = BatchCalculator::calculateMead(self::MEAD_BASE_OPTIONS);

        self::assertFalse($result['error']);
        self::assertSame(68.0, $result['mustTemperature']);
        self::assertSame(1.108, $result['targetGravity']);
        self::assertSame(14.13, $result['targetAbv']);
        self::assertSame(5.0, $result['targetVolume']);
        self::assertNull($result['stepFeeding']);
        self::assertSame(76.0, $result['gofermYanContribution']);
        self::assertSame(0.0, $result['fruitYanContribution']);
        self::assertCount(3, $result['nutrients']['sna']['additions']);
    }

    public function testCalculateMeadDerivesStepFeedingInfo(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'targetStepFeedGravity' => 1.17,
        ]));

        self::assertFalse($result['error']);
        self::assertSame(1.17, $result['targetStepFeedGravity']);
        self::assertSame(18.0, $result['targetAbv']);
        self::assertNotNull($result['stepFeeding']);
        self::assertSame(2, $result['stepFeeding']['numberOfSteps']);
        self::assertCount(7, $result['nutrients']['sna']['additions']);
    }

    public function testCalculateMeadErrorsWhenStepFeedGravityPutsOgBelow1_080(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'targetGravity' => 1.05,
            'targetStepFeedGravity' => 1.09,
        ]));

        self::assertTrue($result['error']);
        self::assertMatchesRegularExpression('/Step feeding is not recommended for OG < 1\.080/', $result['errorMessage']);
    }

    public function testCalculateMeadErrorsWhenStepFeedGravityNotGreaterThanTargetGravity(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'targetGravity' => 1.1,
            'targetStepFeedGravity' => 1.09,
        ]));

        self::assertTrue($result['error']);
        self::assertMatchesRegularExpression('/target_step_feed_gravity should be greater than target_gravity/', $result['errorMessage']);
    }

    public function testCalculateMeadSolvesTargetGravityFromTargetAbv(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, ['targetAbv' => 12.0]));

        self::assertFalse($result['error']);
        self::assertSame(12.0, $result['targetAbv']);
        self::assertEqualsWithDelta(1.087, $result['targetGravity'], 0.001);
    }

    public function testCalculateMeadSolvesTargetGravityFromTargetVolumeAlone(): void
    {
        // og=1 (all-water, no fermentable sugar) drives yan to exactly 0, which previously
        // crashed getAdvancedNutrients with DivisionByZeroError — see the class docblock above.
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, ['targetVolume' => 6.0]));

        self::assertFalse($result['error']);
        self::assertSame(6.0, $result['targetVolume']);
        self::assertSame(1.0, $result['targetGravity']);
        self::assertSame(0.0, $result['nutrients']['yan']);
        self::assertNull($result['nutrients']['yan_ratio_dap']);
    }

    public function testCalculateMeadAccumulatesFruitYanContributionFromFullySpecifiedSugar(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'additionalSugars' => [[
                'type' => Constants::SUGAR_SOURCES['HONEY'],
                'quantity_amount' => 15.0,
                'quantity_amount_specified' => true,
                'quantity_units' => Constants::HONEY_UNIT_POUNDS,
                'sugar_content' => 79.6,
                'yan_multiplier' => 1.0,
                'additive' => false,
            ]],
        ]));

        self::assertFalse($result['error']);
        self::assertSame(253.0, $result['fruitYanContribution']);
    }

    public function testCalculateMeadErrorsWhenAdditionalSugarVolumeExceedsTargetVolume(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'targetVolume' => 0.01,
            'additionalSugars' => [[
                'type' => Constants::SUGAR_SOURCES['HONEY'],
                'quantity_amount' => 15.0,
                'quantity_amount_specified' => true,
                'quantity_units' => Constants::HONEY_UNIT_POUNDS,
                'sugar_content' => 79.6,
                'yan_multiplier' => 1.0,
                'additive' => false,
            ]],
        ]));

        self::assertTrue($result['error']);
        self::assertMatchesRegularExpression('/Total sugar volume .* is greater than total target volume/', $result['errorMessage']);
    }

    public function testCalculateMeadHandlesMetricUnitDefaults(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'units' => Constants::UNITS_METRIC,
        ]));

        self::assertFalse($result['error']);
        self::assertSame(20.0, $result['mustTemperature']);
        self::assertSame(18.93, $result['targetVolume']);
    }

    public function testCalculateMeadUsesSinglePitchAdditionForKveik(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, [
            'yanRequirement' => Constants::YAN_REQUIREMENT_KVEIK,
        ]));

        self::assertFalse($result['error']);
        self::assertSame(0, $result['goferm']['numPackets']);
        self::assertCount(1, $result['nutrients']['sna']['additions']);
        self::assertSame('pitch', $result['nutrients']['sna']['additions'][0]['timing']);
    }

    public function testCalculateMeadUsesShorterSnaScheduleWhenHot(): void
    {
        $result = BatchCalculator::calculateMead(array_merge(self::MEAD_BASE_OPTIONS, ['hot' => true]));

        self::assertFalse($result['error']);
        self::assertCount(2, $result['nutrients']['sna']['additions']);
        self::assertSame(24, $result['nutrients']['sna']['additions'][0]['timing']);
        self::assertSame('break', $result['nutrients']['sna']['additions'][1]['timing']);
    }
}
