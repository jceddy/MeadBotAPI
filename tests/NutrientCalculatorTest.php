<?php

declare(strict_types=1);

namespace MeadBotApi\Tests;

use MeadBotApi\Calculator\Constants;
use MeadBotApi\Calculator\NutrientCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Reference values were cross-checked against MeadBot's NutrientCalculator.js under Node.js,
 * including a schedule length > 4 case that !calculate-nutrients itself never exercises (its
 * schedule is fixed at length 4) but !build-batch and !calculate-mead will need.
 */
final class NutrientCalculatorTest extends TestCase
{
    private const BASE_OPTIONS = [
        'units' => Constants::UNITS_US,
        'volume' => 5.0,
        'yan' => 175.0,
        'fermOEffectiveness' => 2.6,
        'enforceLimits' => true,
        'dapLimit' => 0.96,
        'fermKLimit' => 0.5,
        'fermOLimit' => 0.45,
        'yanRatioDap' => 35.0,
        'yanRatioFermK' => 25.0,
        'yanRatioFermO' => 40.0,
        'fermKYan' => 134.0,
        'fillFkFirst' => true,
        'gofermYan' => 77.0,
        'gofermGrams' => 0.0,
    ];

    public function testCalculateNutrientsMatchesDefaultCalculateNutrientsCommand(): void
    {
        $result = NutrientCalculator::calculateNutrients(self::BASE_OPTIONS);

        self::assertSame(0.0, $result['gofermYanContribution']);
        self::assertSame(175.0, $result['yan']);
        self::assertSame(5.52, $result['dapG']);
        self::assertSame(9.46, $result['fkG']);
        self::assertSame(8.52, $result['foG']);
        self::assertSame('calculate all three with dap remainder', $result['debug']);
        self::assertCount(4, $result['sna']['additions']);
        self::assertSame('break', $result['sna']['additions'][3]['timing']);
    }

    public function testCalculateNutrientsSubtractsGofermYanContribution(): void
    {
        $result = NutrientCalculator::calculateNutrients(array_merge(self::BASE_OPTIONS, ['gofermGrams' => 6.25]));

        self::assertSame(25.0, $result['gofermYanContribution']);
        self::assertSame(150.0, $result['yan']);
        self::assertSame(3.26, $result['dapG']);
    }

    public function testCalculateNutrientsHandlesCustomLimitsRatios(): void
    {
        $result = NutrientCalculator::calculateNutrients(array_merge(self::BASE_OPTIONS, [
            'enforceLimits' => false,
            'yanRatioDap' => 30.0,
            'yanRatioFermK' => 20.0,
            'yanRatioFermO' => 50.0,
        ]));

        self::assertSame('custom limits', $result['debug']);
        self::assertSame(4.73, $result['dapG']);
        self::assertSame(4.94, $result['fkG']);
        self::assertSame(15.92, $result['foG']);
    }

    public function testCalculateNutrientsHandlesMetricUnits(): void
    {
        $result = NutrientCalculator::calculateNutrients(array_merge(self::BASE_OPTIONS, [
            'units' => Constants::UNITS_METRIC,
            'volume' => 19.0,
            'gofermGrams' => 6.25,
        ]));

        self::assertSame(25.0, $result['gofermYanContribution']);
        self::assertSame(3.28, $result['dapG']);
        self::assertSame(9.5, $result['fkG']);
    }

    public function testGetAdvancedNutrientsHandlesLongScheduleFillFkFirst(): void
    {
        $result = NutrientCalculator::getAdvancedNutrients(
            Constants::UNITS_US,
            5,
            300,
            2.6,
            true,
            0.96,
            0.5,
            0.45,
            35,
            25,
            40,
            [24, 48, 72, 96, 120, 'break'],
            134,
            true
        );

        self::assertCount(5, $result['sna']['additions']);
        self::assertSame(16.78, $result['dapG']);
        self::assertSame(0.0, $result['sna']['additions'][0]['fermO']);
        self::assertSame(4.26, $result['sna']['additions'][4]['fermO']);
    }

    public function testGetAdvancedNutrientsHandlesLongScheduleFillFkFalse(): void
    {
        $result = NutrientCalculator::getAdvancedNutrients(
            Constants::UNITS_METRIC,
            19,
            350,
            2.6,
            true,
            0.96,
            0.5,
            0.45,
            35,
            25,
            40,
            [12, 24, 36, 48, 60, 72, 'break'],
            134,
            false
        );

        self::assertCount(7, $result['sna']['additions']);
        self::assertSame(18.24, $result['dapG']);
        self::assertSame('break', $result['sna']['additions'][6]['timing']);
        self::assertSame(7.43, $result['sna']['additions'][6]['fermO']);
    }

    public function testYanContributionFromGrams(): void
    {
        self::assertSame(0.0, NutrientCalculator::yanContributionFromGrams(0, 77, 18.92));
        self::assertSame(25.0, NutrientCalculator::yanContributionFromGrams(6.25, 77, 18.92));
    }
}
