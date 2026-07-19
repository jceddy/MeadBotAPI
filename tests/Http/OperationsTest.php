<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Http;

use InvalidArgumentException;
use MeadBotApi\Calculator\CalculatorApi;
use MeadBotApi\Http\Operations;
use PHPUnit\Framework\TestCase;

/**
 * Operations wraps the same param-parsing/defaulting logic that used to live directly in
 * public/index.php's route closures (see git history) — these are light spot checks that the
 * extraction didn't change behavior; the REST routes themselves remain the primary coverage via
 * a full live smoke test of every endpoint (done manually when this was extracted).
 */
final class OperationsTest extends TestCase
{
    public function testHealth(): void
    {
        self::assertSame(['error' => false, 'status' => 'ok'], Operations::health());
    }

    public function testCalculateCaloriesRequiresAllFourParams(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Operations::calculateCalories(['percentAlcohol' => 12]);
    }

    public function testCalculateAbvMatchesCalculatorApiDirectly(): void
    {
        $result = Operations::calculateABV(['og' => 1.1, 'fg' => 1.0]);
        self::assertSame(CalculatorApi::calculateABV(1.1, 1.0), $result);
    }

    public function testGetVolumeUnitByPathParamName(): void
    {
        self::assertSame(['error' => false, 'unitId' => 1], Operations::getVolumeUnit(['name' => 'gallon']));
    }

    public function testGetVolumeUnitThrowsWhenNameIsMissing(): void
    {
        // Never hit via the REST router (the {name} path param always matches something), but
        // matters now that this is also reachable as a chat tool call, where the model could
        // omit the argument.
        $this->expectException(InvalidArgumentException::class);
        Operations::getVolumeUnit([]);
    }

    public function testGetSugarSourceIdentifierIncludesSugarSourceInfo(): void
    {
        $result = Operations::getSugarSourceIdentifier(['name' => 'honey']);
        self::assertFalse($result['error']);
        self::assertSame(79.6, $result['sugarSource']['percent']);
    }

    public function testGetSugarSourceIdentifierErrorsForUnknownName(): void
    {
        $result = Operations::getSugarSourceIdentifier(['name' => 'unobtainium']);
        self::assertTrue($result['error']);
    }

    public function testListVolumeUnitsAndListYeastRequirementsMatchCalculatorApi(): void
    {
        self::assertSame(
            ['error' => false, 'volumeUnits' => CalculatorApi::listVolumeUnits()],
            Operations::listVolumeUnits()
        );
        self::assertSame(
            ['error' => false, 'yeastRequirements' => CalculatorApi::listYeastRequirements()],
            Operations::listYeastRequirements()
        );
    }

    public function testCalculateMeadWithNoParamsMatchesDefaults(): void
    {
        $result = Operations::calculateMead([]);
        self::assertFalse($result['error']);
        self::assertSame(1.108, $result['targetGravity']);
    }

    public function testBuildBatchWithNoParamsMatchesDefaults(): void
    {
        $result = Operations::buildBatch([]);
        self::assertFalse($result['error']);
        self::assertSame(1.162, $result['og']);
    }
}
