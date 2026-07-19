<?php

declare(strict_types=1);

namespace MeadBotApi\Tests;

use MeadBotApi\Calculator\Constants;
use MeadBotApi\Calculator\GravityCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Reference values were cross-checked against an equivalent (bug-free) implementation built
 * from MeadBot/src/calculator/GravityCalculator.js's own primitives, run under Node.js.
 */
final class GravityCalculatorTest extends TestCase
{
    public function testPotentialAlcoholSolvesFgFromOgAndAbv(): void
    {
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_SG, Constants::ABV_UNIT_ABV, 1.1, null, 14.0);

        self::assertFalse($result['error']);
        self::assertSame(1.1, $result['og']);
        self::assertEqualsWithDelta(0.993, $result['fg'], 1e-9);
        self::assertSame(14.0, $result['abv']);
    }

    public function testPotentialAlcoholSolvesAbvFromOgAndDefaultFg(): void
    {
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_SG, Constants::ABV_UNIT_ABV, 1.1, null, null);

        self::assertSame(1.1, $result['og']);
        self::assertEqualsWithDelta(0.998, $result['fg'], 1e-9);
        self::assertEqualsWithDelta(13.427561199999985, $result['abv'], 1e-9);
    }

    public function testPotentialAlcoholSolvesAbvFromOgAndFg(): void
    {
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_SG, Constants::ABV_UNIT_ABV, 1.1, 1.01, null);

        self::assertEqualsWithDelta(11.97103, $result['abv'], 1e-4);
    }

    public function testPotentialAlcoholSolvesOgFromFgAndDefaultAbv(): void
    {
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_SG, Constants::ABV_UNIT_ABV, null, 1.01, null);

        self::assertEqualsWithDelta(1.12, $result['og'], 1e-9);
        self::assertSame(1.01, $result['fg']);
        self::assertSame(14.37, $result['abv']);
    }

    public function testPotentialAlcoholConvertsBrixToSgBeforeSolving(): void
    {
        // Unlike MeadBot's !potential-alcohol command, this must correctly convert BRIX to SG
        // before computing — the command has a known bug where it doesn't, for this exact
        // combination (og+abv specified, non-SG gravityUnits), producing nonsense output.
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_BRIX, Constants::ABV_UNIT_ABV, 25, null, 14.0);

        self::assertEqualsWithDelta(25.025260783596195, $result['og'], 1e-6);
        self::assertEqualsWithDelta(-0.3174252463477387, $result['fg'], 1e-6);
        self::assertSame(14.0, $result['abv']);
    }

    public function testPotentialAlcoholHandlesBaumeUnits(): void
    {
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_BAUME, Constants::ABV_UNIT_ABV, 13, 0, null);

        self::assertSame(13.0, $result['og']);
        self::assertSame(0.0, $result['fg']);
        self::assertEqualsWithDelta(13.004172405876972, $result['abv'], 1e-6);
    }

    public function testPotentialAlcoholHandlesAbwUnits(): void
    {
        $result = GravityCalculator::potentialAlcohol(Constants::GRAVITY_UNIT_SG, Constants::ABV_UNIT_ABW, null, null, 11.5);

        self::assertEqualsWithDelta(1.1089999999999876, $result['og'], 1e-9);
        self::assertEqualsWithDelta(0.998, $result['fg'], 1e-9);
        self::assertSame(11.5, $result['abv']);
    }

    public function testConvToSgIsIdentityForSg(): void
    {
        self::assertSame(1.05, GravityCalculator::convToSG(1.05, Constants::GRAVITY_UNIT_SG));
    }

    public function testStormABVtoSGMatchesConvertGravityDropToABVInverse(): void
    {
        $sgDelta = GravityCalculator::stormABVtoSG(14.37);
        self::assertEqualsWithDelta(14.37, \MeadBotApi\Calculator\CalculatorApi::convertGravityDropToABV($sgDelta), 0.05);
    }
}
