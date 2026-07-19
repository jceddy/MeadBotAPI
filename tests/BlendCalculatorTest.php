<?php

declare(strict_types=1);

namespace MeadBotApi\Tests;

use MeadBotApi\Calculator\BlendCalculator;
use MeadBotApi\Calculator\Constants;
use PHPUnit\Framework\TestCase;

/**
 * Reference values were cross-checked against MeadBot's BlendCalculator.js under Node.js.
 */
final class BlendCalculatorTest extends TestCase
{
    public function testDisplayNumberRoundsToSigFigures(): void
    {
        self::assertSame(1.235, BlendCalculator::displayNumber(1.23456, 3));
        self::assertSame(-1.235, BlendCalculator::displayNumber(-1.23456, 3));
        self::assertSame(0.0, BlendCalculator::displayNumber(0.0004999, 3));
        self::assertSame(10.0, BlendCalculator::displayNumber(9.9996, 3));
        self::assertSame(-0.5, BlendCalculator::displayNumber(-0.5, 3));
        self::assertSame(12.001, BlendCalculator::displayNumber(12.0005, 3));
        self::assertSame(-12.001, BlendCalculator::displayNumber(-12.0005, 3));
    }

    public function testCalculateBlendSolvesVolume2(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_VOLUME2, 11, 15, 12, 3, null, null);

        self::assertFalse($result['error']);
        self::assertSame(1.0, $result['volume2']);
        self::assertSame(4.0, $result['totalVolume']);
    }

    public function testCalculateBlendSolvesVolume1AndVolume2FromTotalVolume(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_VOLUME1, 11, 15, 12, null, null, 6);

        self::assertFalse($result['error']);
        self::assertSame(4.5, $result['volume1']);
        self::assertSame(1.5, $result['volume2']);
    }

    public function testCalculateBlendSolvesBlendedValue(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_BLENDED_VALUE, 11, 15, null, 3, 3, null);

        self::assertFalse($result['error']);
        self::assertSame(13.0, $result['blendedValue']);
        self::assertSame(6.0, $result['totalVolume']);
    }

    public function testCalculateBlendSolvesValue1(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_VALUE1, null, 15, 12, 3, 3, null);

        self::assertFalse($result['error']);
        self::assertSame(9.0, $result['value1']);
    }

    public function testCalculateBlendSolvesValue2(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_VALUE2, 11, null, 12, 3, 3, null);

        self::assertFalse($result['error']);
        self::assertSame(13.0, $result['value2']);
    }

    public function testCalculateBlendSolvesTotalVolume(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_TOTAL_VOLUME, 11, 15, 12, 3, null, null);

        self::assertFalse($result['error']);
        self::assertSame(1.0, $result['volume2']);
        self::assertSame(4.0, $result['totalVolume']);
    }

    public function testCalculateBlendErrorsWhenFieldsAreMissing(): void
    {
        $result = BlendCalculator::calculateBlend(Constants::BLEND_FIELD_VOLUME2, 11, null, 12, 3, null, null);

        self::assertTrue($result['error']);
        self::assertSame('Please specify Volume #1 or total volume.', $result['errorMessage']);
    }
}
