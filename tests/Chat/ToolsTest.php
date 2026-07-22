<?php

declare(strict_types=1);

namespace MeadBotApi\Tests\Chat;

use InvalidArgumentException;
use MeadBotApi\Calculator\CalculatorApi;
use MeadBotApi\Calculator\Constants;
use MeadBotApi\Chat\Tools;
use MeadBotApi\Http\Operations;
use PHPUnit\Framework\TestCase;

final class ToolsTest extends TestCase
{
    public function testDefinitionsAreWellFormedAndUniquelyNamed(): void
    {
        $definitions = Tools::definitions();
        self::assertNotEmpty($definitions);

        // The two MeadTools wiki tools aren't Operations-backed -- they do network/file I/O, not
        // a calculation -- so they're dispatched specially in Tools::call() rather than through
        // TOOL_TO_OPERATION.
        $nonOperationTools = ['list_meadtools_wiki_pages', 'fetch_meadtools_wiki_page'];

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
        self::assertCount(24, $definitions, 'expected one tool per Operations method (except health/random), plus the two MeadTools wiki tools');
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

    public function testCallDispatchesListMeadtoolsWikiPages(): void
    {
        $result = Tools::call('list_meadtools_wiki_pages', []);

        self::assertFalse($result['error']);
        self::assertIsArray($result['pages']);
        self::assertNotEmpty($result['pages']);
        self::assertSame('https://wiki.meadtools.com/en/home', $result['pages'][0]['url']);
    }

    /**
     * convert_volume/get_volume_unit and convert_honey_units/get_honey_unit take free-text unit
     * names in the underlying Operations methods (which also accept many aliases per unit), but
     * their tool schemas constrain the model to the canonical slugs so it doesn't have to guess
     * or burn an extra lookup call -- these assert that constraint stays in sync with the actual
     * canonical slug lists rather than drifting from them.
     */
    public function testVolumeAndHoneyUnitToolsEnumerateTheCanonicalSlugs(): void
    {
        $byName = [];
        foreach (Tools::definitions() as $definition) {
            // properties is cast to an object in Tools::tool() to serialize as a JSON object even
            // when empty; cast back to an array here for normal PHP array access.
            $byName[$definition['function']['name']] = (array) $definition['function']['parameters']['properties'];
        }

        $volumeSlugs = array_values(Constants::VOLUME_UNIT_SLUGS);
        self::assertSame($volumeSlugs, $byName['get_volume_unit']['name']['enum']);
        self::assertSame($volumeSlugs, $byName['convert_volume']['fromUnit']['enum']);
        self::assertSame($volumeSlugs, $byName['convert_volume']['toUnit']['enum']);

        $honeySlugs = array_values(Constants::HONEY_UNIT_SLUGS);
        self::assertSame($honeySlugs, $byName['get_honey_unit']['name']['enum']);
        self::assertSame($honeySlugs, $byName['convert_honey_units']['fromUnit']['enum']);
        self::assertSame($honeySlugs, $byName['convert_honey_units']['toUnit']['enum']);
    }

    /**
     * Every enumerated unit slug in the tool schemas must actually be one Operations' underlying
     * lookup accepts -- otherwise the model could be steered toward a value that then fails.
     */
    public function testEveryEnumeratedUnitSlugIsAcceptedByTheUnderlyingLookup(): void
    {
        foreach (array_values(Constants::VOLUME_UNIT_SLUGS) as $slug) {
            self::assertNotNull(CalculatorApi::getVolumeUnit($slug), "volume slug '{$slug}' should be recognized");
        }
        foreach (array_values(Constants::HONEY_UNIT_SLUGS) as $slug) {
            self::assertNotNull(CalculatorApi::getHoneyUnit($slug), "honey slug '{$slug}' should be recognized");
        }
    }
}
