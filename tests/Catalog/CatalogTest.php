<?php

declare(strict_types=1);

namespace LlmKit\Tests\Catalog;

use LlmKit\Catalog\Capabilities;
use LlmKit\Catalog\Catalog;
use LlmKit\Catalog\Model;
use LlmKit\Catalog\Pricing;
use LlmKit\Usage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Catalog::class)]
#[CoversClass(Model::class)]
final class CatalogTest extends TestCase
{
    protected function tearDown(): void
    {
        Catalog::reset();
    }

    /** @return iterable<string,array{string,string}> */
    public static function lookups(): iterable
    {
        yield 'exact id' => ['claude-sonnet-4-5-20250929', 'claude-sonnet-4-5-20250929'];
        yield 'alias' => ['claude-sonnet-4-5', 'claude-sonnet-4-5-20250929'];
        yield 'provider prefix' => ['anthropic/claude-sonnet-4-5', 'claude-sonnet-4-5-20250929'];
        yield 'case insensitive' => ['GPT-5', 'gpt-5'];
        yield 'vertex version suffix' => ['gemini-2.5-pro@20250101', 'gemini-2.5-pro'];
        yield 'dated snapshot by prefix' => ['gpt-5-20260101', 'gpt-5'];
        yield 'groq style id kept whole' => ['openai/gpt-oss-120b', 'openai/gpt-oss-120b'];
        yield 'openrouter prefix stripped' => ['openrouter/openai/gpt-oss-20b', 'openai/gpt-oss-20b'];
    }

    #[DataProvider('lookups')]
    public function testLookupResolves(string $name, string $expected): void
    {
        $model = Catalog::lookup($name);

        self::assertTrue($model->known);
        self::assertSame($expected, $model->id);
    }

    /** @return iterable<string,array{string}> */
    public static function unknowns(): iterable
    {
        yield 'variant the catalog lacks' => ['gpt-5-turbo-mini'];
        yield 'unseeded vendor' => ['command-a-03-2025'];
        yield 'local model' => ['llama3.2:3b'];
        yield 'empty' => [''];
    }

    #[DataProvider('unknowns')]
    public function testUnknownModelsAreNotGuessed(string $name): void
    {
        $model = Catalog::lookup($name);

        self::assertFalse($model->known);
        self::assertSame($name, $model->id);
        self::assertSame(0.0, $model->cost(new Usage(1000, 1000, 2000)));
    }

    public function testLongestPrefixWins(): void
    {
        self::assertSame('gpt-5-mini', Catalog::lookup('gpt-5-mini-20260101')->id);
    }

    public function testCostSplitsCachedAndWrittenTokens(): void
    {
        $model = Catalog::lookup('claude-sonnet-4-5');
        // 10k prompt: 6k uncached at $3, 3k cache reads at $0.30, 1k writes at $3.75, 2k output at $15.
        $usage = new Usage(
            inputTokens: 10_000,
            outputTokens: 2_000,
            totalTokens: 12_000,
            cachedInputTokens: 3_000,
            cacheWriteTokens: 1_000,
        );

        $expected = (6_000 * 3 + 3_000 * 0.3 + 1_000 * 3.75 + 2_000 * 15) / 1e6;
        self::assertEqualsWithDelta($expected, $model->cost($usage), 1e-12);
    }

    public function testRegisterOverridesARowAndItsAliases(): void
    {
        Catalog::register(new Model(
            id: 'gpt-5',
            provider: 'openai',
            displayName: 'Custom GPT-5',
            contextWindow: 123,
            pricing: new Pricing(input: 1, output: 2),
            capabilities: new Capabilities(tools: true),
            aliases: ['house-model'],
        ));

        $model = Catalog::lookup('house-model');
        self::assertTrue($model->known);
        self::assertSame('gpt-5', $model->id);
        self::assertSame(123, $model->contextWindow);
        self::assertSame('Custom GPT-5', Catalog::lookup('gpt-5')->displayName);
    }

    public function testRegisterDropsStaleAliases(): void
    {
        self::assertSame('grok-4.5', Catalog::lookup('grok-build-latest')->id);

        Catalog::register(new Model(id: 'grok-4.5', provider: 'xai', aliases: ['grok-4.5-latest']));

        self::assertFalse(Catalog::lookup('grok-build-latest')->known);
        self::assertSame('grok-4.5', Catalog::lookup('grok-4.5-latest')->id);
    }

    public function testAllIsSortedByProviderThenId(): void
    {
        $rows = Catalog::all();
        self::assertNotEmpty($rows);

        $keys = array_map(static fn(Model $m): array => [$m->provider, $m->id], $rows);
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys);
        self::assertSame('anthropic', $rows[0]->provider);
    }

    public function testSeedRowsCarryLimitsAndCapabilities(): void
    {
        $model = Catalog::lookup('gemini-2.5-pro');

        self::assertSame('vertex', $model->provider);
        self::assertSame(1_048_576, $model->contextWindow);
        self::assertSame(65_536, $model->maxOutput);
        self::assertTrue($model->capabilities->tools);
        self::assertTrue($model->capabilities->promptCache);
        self::assertSame(1.25, $model->pricing->input);
    }
}
