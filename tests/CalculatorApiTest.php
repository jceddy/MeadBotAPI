<?php

declare(strict_types=1);

namespace MeadBotApi\Tests;

use DateTimeImmutable;
use MeadBotApi\Calculator\CalculatorApi;
use MeadBotApi\Calculator\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Reference values were captured by running the original MeadBot/src/calculator/CalculatorAPI.js
 * with Node.js, to confirm this port matches its behavior exactly.
 */
final class CalculatorApiTest extends TestCase
{
    public function testCalculateAbvWithExplicitFg(): void
    {
        $result = CalculatorApi::calculateABV(1.100, 1.000);

        self::assertFalse($result['error']);
        self::assertSame(1.1, $result['og']);
        self::assertSame(1.0, $result['fg']);
        self::assertEqualsWithDelta(13.186999999999983, $result['abv'], 1e-9);
    }

    public function testCalculateAbvEstimatesDryFgWhenNull(): void
    {
        $result = CalculatorApi::calculateABV(1.100, null);

        self::assertFalse($result['error']);
        self::assertSame(0.995, $result['fg']);
        self::assertEqualsWithDelta(13.786757499999936, $result['abv'], 1e-9);
    }

    public function testCalculateAbvRejectsOgLessThanFg(): void
    {
        $result = CalculatorApi::calculateABV(1.000, 1.050);

        self::assertTrue($result['error']);
        self::assertSame(Constants::ERROR_INVALID_ARGUMENTS, $result['errorType']);
    }

    public function testCalculateAbvRejectsNonNumericOg(): void
    {
        $result = CalculatorApi::calculateABV('not-a-number', null);

        self::assertTrue($result['error']);
        self::assertSame(Constants::ERROR_IS_NAN, $result['errorType']);
    }

    public function testCalculateAbvRejectsOutOfRangeOg(): void
    {
        $result = CalculatorApi::calculateABV(2.0, null);

        self::assertTrue($result['error']);
        self::assertSame(Constants::ERROR_RANGE, $result['errorType']);
    }

    public function testEstimateDryFg(): void
    {
        self::assertSame(0.995, CalculatorApi::estimateDryFG(1.100));
    }

    public function testConvertGravityDropToAbv(): void
    {
        self::assertEqualsWithDelta(-217.013, CalculatorApi::convertGravityDropToABV(0.100), 1e-9);
    }

    public function testCalculateCalories(): void
    {
        $result = CalculatorApi::calculateCalories(12, 1.000, 750, 150);

        self::assertFalse($result['error']);
        self::assertEqualsWithDelta(9.600000000000001, $result['alcoholGramsLiter'], 1e-9);
        self::assertEqualsWithDelta(552.0000000000001, $result['totalCaloriesBottle'], 1e-9);
        self::assertEqualsWithDelta(184.00000000000006, $result['totalCalories250'], 1e-9);
        self::assertEqualsWithDelta(110.40000000000002, $result['totalCaloriesServing'], 1e-9);
    }

    public function testCalculateCaloriesRejectsOutOfRangeBottleVolume(): void
    {
        $result = CalculatorApi::calculateCalories(12, 1.000, 50, 150);

        self::assertTrue($result['error']);
        self::assertSame('bottleVolume', $result['errorArgument']);
        self::assertSame(Constants::ERROR_RANGE, $result['errorType']);
    }

    public function testConvertVolumeGallonToLiters(): void
    {
        $result = CalculatorApi::convertVolume(1, 'gallon', 'liters');

        self::assertFalse($result['error']);
        self::assertEqualsWithDelta(3.7854117891, $result['toAmount'], 1e-9);
        self::assertSame('Gallon(s) US', $result['fromUnit']['name']);
        self::assertSame('Liter(s)', $result['toUnit']['name']);
    }

    public function testConvertVolumeRejectsUnknownUnit(): void
    {
        $result = CalculatorApi::convertVolume(1, 'not-a-unit', 'liters');

        self::assertTrue($result['error']);
        self::assertSame('fromUnit', $result['errorArgument']);
        self::assertSame(Constants::ERROR_INVALID_ARGUMENTS, $result['errorType']);
    }

    public function testConvertHoneyUnitsKgToLb(): void
    {
        $result = CalculatorApi::convertHoneyUnits(1, 'kg', 'lbs');

        self::assertFalse($result['error']);
        self::assertEqualsWithDelta(2.204622620001839, $result['toAmount'], 1e-9);
    }

    public function testConvertTemperatureCelsiusToFahrenheit(): void
    {
        $result = CalculatorApi::convertTemperature(100, 'c');

        self::assertFalse($result['error']);
        self::assertSame(212.0, $result['toTemperature']);
        self::assertSame('Celsius', $result['fromUnit']);
        self::assertSame('Fahrenheit', $result['toUnit']);
    }

    public function testConvertTemperatureRejectsUnknownUnit(): void
    {
        $result = CalculatorApi::convertTemperature(100, 'kelvin');

        self::assertTrue($result['error']);
        self::assertSame(Constants::ERROR_INVALID_ARGUMENTS, $result['errorType']);
    }

    public function testConvertSgToBrix(): void
    {
        self::assertEqualsWithDelta(21.56849571300006, CalculatorApi::convertSGToBrix(1.090), 1e-9);
    }

    public function testComputeDelle(): void
    {
        $result = CalculatorApi::computeDelle(12.5, 0.998);

        self::assertFalse($result['error']);
        self::assertEqualsWithDelta(55.729977916448, $result['delle'], 1e-6);
    }

    public function testGetVolumeUnit(): void
    {
        self::assertSame(Constants::VOLUME_UNIT_GALLONS_US, CalculatorApi::getVolumeUnit('gallon'));
        self::assertNull(CalculatorApi::getVolumeUnit('not-a-unit'));
    }

    public function testGetHoneyUnit(): void
    {
        self::assertSame(Constants::HONEY_UNIT_KILOGRAMS, CalculatorApi::getHoneyUnit('kg'));
        self::assertNull(CalculatorApi::getHoneyUnit('not-a-unit'));
    }

    public function testGetSugarSourceIdentifier(): void
    {
        self::assertSame(Constants::SUGAR_SOURCES['HONEY'], CalculatorApi::getSugarSourceIdentifier('honey'));
        self::assertNull(CalculatorApi::getSugarSourceIdentifier('not-a-fruit'));
    }

    public function testMakeHoursString(): void
    {
        self::assertSame('At Pitch', CalculatorApi::makeHoursString('pitch'));
        self::assertSame('1/3 Sugar Break (1.020 - no later than 7 days)', CalculatorApi::makeHoursString('break', 1.020));
        self::assertSame('24 Hours after honey addition #1', CalculatorApi::makeHoursString('24,1'));
        self::assertSame('24 Hours after pitch', CalculatorApi::makeHoursString('24,0'));
        self::assertSame('48 Hours', CalculatorApi::makeHoursString('48'));
    }

    public function testGetDaysBetween(): void
    {
        $date1 = new DateTimeImmutable('2024-01-01T00:00:00Z');
        $date2 = new DateTimeImmutable('2024-01-10T00:00:00Z');

        self::assertSame(9.0, CalculatorApi::getDaysBetween($date1, $date2));
    }

    public function testGetMonthsBetween(): void
    {
        $date1 = new DateTimeImmutable('2024-01-01T00:00:00Z');
        $date2 = new DateTimeImmutable('2024-03-15T00:00:00Z');

        self::assertSame(2, CalculatorApi::getMonthsBetween($date1, $date2, false));
        self::assertSame(3, CalculatorApi::getMonthsBetween($date1, $date2, true));
    }

    public function testRandomIntegerStaysInRange(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $value = CalculatorApi::randomInteger(10);
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThan(10, $value);
        }
    }
}
