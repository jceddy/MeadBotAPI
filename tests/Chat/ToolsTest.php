<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use InvalidArgumentException;
use MeadBotApi\Calculator\CalculatorApi;
use MeadBotApi\Chat\Tools;
use MeadBotApi\Http\Operations;
use PHPUnit\Framework\TestCase;

final class ToolsTest extends TestCase
{
    public function testDefinitionsAreWellFormedAndUniquelyNamed(): void
    {
        $definitions = Tools::definitions();
        self::assertNotEmpty($definitions);

        // fetch_meadtools_wiki_page isn't Operations-backed -- it does network I/O, not a
        // calculation -- so it's dispatched specially in Tools::call() rather than through
        // TOOL_TO_OPERATION.
        $nonOperationTools = ['fetch_meadtools_wiki_page'];

        $names = [];
        foreach ($definitions as $definition) {
            self::assertSame('function', $definition['type']);
            $function = $definition['function'];
            self::assertIsString($function['name']);
            self::assertNotSame('', $function['name']);
            self::assertIsString($function['description']);
            self::assertNotSame('', $function['description']);
            self::assertSame('object', $function['parameters']['type']);
            $names[] = $function['name'];

            if (in_array($function['name'], $nonOperationTools, true)) {
                continue;
            }
            // Every other declared tool must actually be dispatchable.
            self::assertTrue(method_exists(Operations::class, self::operationFor($function['name'])));
        }

        self::assertSame($names, array_unique($names), 'tool names must be unique');
        self::assertCount(23, $definitions, 'expected one tool per Operations method (except health/random), plus fetch_meadtools_wiki_page');
    }

    private static function operationFor(string $toolName): string
    {
        $reflection = new \ReflectionClass(Tools::class);
        $map = $reflection->getConstant('TOOL_TO_OPERATION');
        self::assertArrayHasKey($toolName, $map);
        return $map[$toolName];
    }

    public function testCallDispatchesToTheMatchingOperationAndMatchesItDirectly(): void
    {
        $viaTool = Tools::call('get_sugar_source', ['name' => 'honey']);
        $viaOperation = Operations::getSugarSourceIdentifier(['name' => 'honey']);
        self::assertSame($viaOperation, $viaTool);
        self::assertSame(CalculatorApi::getSugarSourceIdentifier('honey'), $viaTool['unitId']);
    }

    public function testCallDispatchesCalculateAbv(): void
    {
        $result = Tools::call('calculate_abv', ['og' => 1.1, 'fg' => 1.0]);
        self::assertSame(Operations::calculateABV(['og' => 1.1, 'fg' => 1.0]), $result);
        self::assertFalse($result['error']);
    }

    public function testCallDispatchesCalculateMead(): void
    {
        $result = Tools::call('calculate_mead', []);
        self::assertSame(Operations::calculateMead([]), $result);
        self::assertFalse($result['error']);
    }

    public function testCallDispatchesZeroArgumentTools(): void
    {
        self::assertSame(Operations::listVolumeUnits(), Tools::call('list_volume_units', []));
        self::assertSame(Operations::listYeastRequirements(), Tools::call('list_yeast_requirements', []));
    }

    public function testCallThrowsForAnUnknownToolName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Tools::call('not_a_real_tool', []);
    }

    public function testCallSurfacesOperationValidationErrorsAsExceptions(): void
    {
        // calculate_calories requires percentAlcohol/fg/bottleVolume/servingVolume.
        $this->expectException(InvalidArgumentException::class);
        Tools::call('calculate_calories', []);
    }

    public function testCallDispatchesFetchMeadtoolsWikiPageAndRejectsDisallowedHostsWithoutAnyNetworkCall(): void
    {
        // Host validation happens before the transport is ever invoked (see
        // MeadToolsWikiClientTest for the transport-injected success/failure paths), so this
        // exercises Tools::call()'s wiring without making a real HTTP request.
        $result = Tools::call('fetch_meadtools_wiki_page', ['url' => 'https://evil.example.com/']);
        self::assertTrue($result['error']);
        self::assertStringContainsString('wiki.meadtools.com', $result['errorMessage']);
    }
}
