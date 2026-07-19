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

    public function testHoCalc(): void
    {
        $us = NutrientCalculator::hoCalc(1.162, 5, Constants::UNITS_US);
        self::assertEqualsWithDelta(23.119873798660315, $us[0], 1e-9);
        self::assertSame('lbs', $us[1]);

        $metric = NutrientCalculator::hoCalc(1.162, 5, Constants::UNITS_METRIC);
        self::assertEqualsWithDelta(10.487105959657223, $metric[0], 1e-9);
        self::assertSame('kg', $metric[1]);

        self::assertNull(NutrientCalculator::hoCalc(1.162, 5, 99));
    }

    public function testGetGoferm(): void
    {
        self::assertSame([15.0, 3, 18.75, 375.0, 5.0], NutrientCalculator::getGoferm(5, 1.162, 0));
        // og >= 1.144 bumps the pitch rate, same result here since it's already the higher tier
        self::assertSame([15.0, 3, 18.75, 375.0, 5.0], NutrientCalculator::getGoferm(5, 1.15, 0));
        // negative fruitYan also bumps the pitch rate
        self::assertSame([15.0, 3, 18.75, 375.0, 5.0], NutrientCalculator::getGoferm(5, 1.05, -5));
        self::assertSame([5.0, 0, 6.25, 125.0, 0.0], NutrientCalculator::getGoferm(5, 1.05, 0, true, 5, true));
        self::assertSame([10.0, 2, 0.0, 0.0, 5.0], NutrientCalculator::getGoferm(5, 1.05, 0, false));
    }

    public function testGetFermO(): void
    {
        $result = NutrientCalculator::getFermO(18.92, 260, [24, 48, 72, 'break']);
        self::assertSame(98.4, $result['totalFermO']);
        self::assertSame(24.6, $result['additions'][0]['fermO']);
    }

    public function testGetFermK(): void
    {
        $result = NutrientCalculator::getFermK(18.92, 260, [24, 48, 72, 'break']);
        self::assertSame(49.2, $result['totalFermK']);
        self::assertSame(12.3, $result['additions'][0]['fermK']);
    }

    public function testGetFermKdap(): void
    {
        $result = NutrientCalculator::getFermKdap(18.92, 260, [24, 48, 72, 'break'], 134);
        self::assertSame(26.7, $result['totalFermK']);
        self::assertSame(68.2, $result['totalDAP']);

        // nit is clamped to 250, so 260 and 300 produce the same result
        $highNit = NutrientCalculator::getFermKdap(18.92, 300, [24, 48, 72, 'break'], 134);
        self::assertSame($result, $highNit);
    }

    /** @return array<string, array{0: int, 1: array<string, mixed>}> */
    public static function fermOkScheduleLengths(): array
    {
        return [
            'length 1' => [1, ['totalFermO' => 49.2, 'totalFermK' => 24.6]],
            'length 2' => [2, ['totalFermO' => 49.2, 'totalFermK' => 24.6]],
            'length 3' => [3, ['totalFermO' => 66.0, 'totalFermK' => 16.0]],
            'length 4' => [4, ['totalFermO' => 49.2, 'totalFermK' => 24.6]],
            'length 5' => [5, ['totalFermO' => 49.2, 'totalFermK' => 30.0]],
            'length 6' => [6, ['totalFermO' => 49.2, 'totalFermK' => 33.0]],
            'length 7' => [7, ['totalFermO' => 49.2, 'totalFermK' => 35.0]],
        ];
    }

    /** @dataProvider fermOkScheduleLengths */
    public function testGetFermOKAcrossScheduleLengths(int $length, array $expected): void
    {
        $schedule = [];
        for ($i = 0; $i < $length; $i++) {
            $schedule[] = $i === $length - 1 ? 'break' : ($i + 1) * 24;
        }

        $result = NutrientCalculator::getFermOK(18.92, 260, $schedule);

        self::assertSame($expected['totalFermO'], $result['totalFermO']);
        self::assertSame($expected['totalFermK'], $result['totalFermK']);
        self::assertCount($length, $result['additions']);
    }

    /** @return array<string, array{0: int}> */
    public static function nutrientsScheduleLengths(): array
    {
        return [
            'length 1' => [1], 'length 2' => [2], 'length 3' => [3], 'length 4' => [4],
            'length 5' => [5], 'length 6' => [6], 'length 7' => [7], 'length 8' => [8],
        ];
    }

    /**
     * @dataProvider nutrientsScheduleLengths
     * Regression test: the odd-length branch previously dropped the first addition due to an
     * incorrect (int) cast on a loop bound that JS evaluates as a float comparison.
     */
    public function testGetNutrientsAcrossScheduleLengths(int $length): void
    {
        $schedule = [];
        for ($i = 0; $i < $length; $i++) {
            $schedule[] = $i === $length - 1 ? 'break' : ($i + 1) * 24;
        }

        $result = NutrientCalculator::getNutrients(5, 18, 260, $schedule, 134, 2.6);

        self::assertSame(8.5, $result['totalFermO']);
        self::assertSame(9.5, $result['totalFermK']);
        self::assertSame(13.2, $result['totalDAP']);
        self::assertCount($length, $result['additions']);
        self::assertSame('break', $result['additions'][$length - 1]['timing']);
    }
}
