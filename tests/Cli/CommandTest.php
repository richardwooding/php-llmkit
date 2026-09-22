<?php

declare(strict_types=1);

namespace LlmKit\Tests\Cli;

use LlmKit\Cli\Command;
use LlmKit\Cli\Flags;
use LlmKit\LlmKit;
use LlmKit\Registry;
use LlmKit\Tests\Support\FakeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Command::class)]
#[CoversClass(Flags::class)]
final class CommandTest extends TestCase
{
    protected function setUp(): void
    {
        $registry = new Registry(new FakeProvider('fake', ['fake-']));
        $registry->setFallback('fake');
        LlmKit::setRegistry($registry);
    }

    protected function tearDown(): void
    {
        LlmKit::setRegistry(null);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int,string,string}
     */
    private function invoke(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $stdin);
        rewind($in);

        $code = Command::run($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testUsageIsPrintedWithoutArguments(): void
    {
        [$code, $out] = $this->invoke([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('llmkit chat', $out);
    }

    public function testUnknownCommandFails(): void
    {
        [$code, , $err] = $this->invoke(['fly']);

        self::assertSame(1, $code);
        self::assertStringContainsString('unknown command "fly"', $err);
    }

    public function testResolvePrintsProviderAndModel(): void
    {
        [$code, $out] = $this->invoke(['resolve', 'fake-1', 'fake/other', 'mystery']);

        self::assertSame(0, $code);
        self::assertSame(
            "fake-1\tfake\tfake-1\nfake/other\tfake\tother\nmystery\tfake\tmystery\n",
            $out,
        );
    }

    public function testResolveNeedsAModel(): void
    {
        [$code, , $err] = $this->invoke(['resolve']);

        self::assertSame(1, $code);
        self::assertStringContainsString('at least one model name', $err);
    }

    public function testChatNeedsAModel(): void
    {
        [$code, , $err] = $this->invoke(['chat', 'hello']);

        self::assertSame(1, $code);
        self::assertStringContainsString('-m <model> is required', $err);
    }

    public function testChatReportsUnsupportedCapabilities(): void
    {
        // FakeProvider's client only implements Chatter.
        [$code, , $err] = $this->invoke(['embed', '-m', 'fake-1', 'hello']);

        self::assertSame(1, $code);
        self::assertStringContainsString('does not implement', $err);
    }

    public function testChatReadsStdinWhenNoPromptIsGiven(): void
    {
        [$code, , $err] = $this->invoke(['chat', '-m', 'fake-1'], '   ');

        self::assertSame(1, $code);
        self::assertStringContainsString('no input', $err);
    }

    public function testFlagsParsing(): void
    {
        $flags = Flags::parse(
            ['-m', 'gpt-5', '--system=Be brief.', '--max-tokens', '128', '--stream', 'hello', 'world'],
            ['stream', 'json'],
        );

        self::assertSame('gpt-5', $flags->string('m'));
        self::assertSame('Be brief.', $flags->string('system'));
        self::assertSame(128, $flags->int('max-tokens'));
        self::assertTrue($flags->bool('stream'));
        self::assertFalse($flags->bool('json'));
        self::assertSame(['hello', 'world'], $flags->operands());
        self::assertNull($flags->float('temperature'));
    }

    public function testFlagsStopAtDoubleDash(): void
    {
        $flags = Flags::parse(['-m', 'x', '--', '--not-a-flag'], []);

        self::assertSame(['--not-a-flag'], $flags->operands());
    }
}
