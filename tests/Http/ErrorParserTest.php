<?php

declare(strict_types=1);

namespace LlmKit\Tests\Http;

use LlmKit\Exception\ApiException;
use LlmKit\Exception\ContextLengthExceededException;
use LlmKit\Exception\RateLimitedException;
use LlmKit\Http\ErrorParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorParser::class)]
#[CoversClass(ApiException::class)]
final class ErrorParserTest extends TestCase
{
    /** @return iterable<string,array{string,string,string}> */
    public static function envelopes(): iterable
    {
        yield 'openai object' => [
            '{"error":{"message":"Bad key","type":"invalid_request_error","code":"invalid_api_key"}}',
            'Bad key',
            'invalid_api_key',
        ];
        yield 'anthropic object' => [
            '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}',
            'Overloaded',
            '',
        ];
        yield 'google numeric code and status' => [
            '{"error":{"code":429,"message":"Quota exceeded","status":"RESOURCE_EXHAUSTED"}}',
            'Quota exceeded',
            '429',
        ];
        yield 'error as a string' => ['{"error":"plain failure"}', 'plain failure', ''];
        yield 'top level message' => ['{"message":"nope","code":"bad"}', 'nope', 'bad'];
        yield 'huggingface detail' => ['{"detail":"model is loading"}', 'model is loading', ''];
        yield 'unknown shape falls back to body' => ['upstream exploded', 'upstream exploded', ''];
        yield 'empty body' => ['', '', ''];
    }

    #[DataProvider('envelopes')]
    public function testDecodesEnvelopes(string $body, string $message, string $code): void
    {
        $error = ErrorParser::parse('openai', 400, [], $body);

        self::assertSame($message, $error->detail);
        self::assertSame($code, $error->errorCode);
        self::assertSame(400, $error->status);
        self::assertSame($body, $error->body);
        self::assertSame('openai', $error->provider);
    }

    public function testStatusCodeAndMessageAppearInTheExceptionMessage(): void
    {
        $error = ErrorParser::parse('anthropic', 400, [], '{"error":{"message":"too long","code":"context_length"}}');

        self::assertSame('anthropic: HTTP 400 [context_length]: too long', $error->getMessage());
    }

    public function testRateLimitedIsItsOwnException(): void
    {
        $error = ErrorParser::parse('openai', 429, ['Retry-After' => ['12']], '{"error":{"message":"slow down"}}');

        self::assertInstanceOf(RateLimitedException::class, $error);
        self::assertTrue($error->isRateLimited());
        self::assertSame(12.0, $error->retryAfter);
    }

    public function testRateLimitedByCodeWithoutStatus(): void
    {
        $error = ErrorParser::parse('groq', 400, [], '{"error":{"code":"rate_limit_exceeded","message":"wait"}}');

        self::assertInstanceOf(RateLimitedException::class, $error);
    }

    /** @return iterable<string,array{string}> */
    public static function contextLengthBodies(): iterable
    {
        yield 'code' => ['{"error":{"code":"context_length_exceeded","message":"nope"}}'];
        yield 'window code' => ['{"error":{"code":"context_window_exceeded","message":"nope"}}'];
        yield 'message' => ['{"error":{"message":"prompt is too long: 300000 tokens"}}'];
        yield 'gemini message' => ['{"error":{"message":"The input token count (1e6) exceeds the maximum"}}'];
    }

    #[DataProvider('contextLengthBodies')]
    public function testContextLengthIsItsOwnException(string $body): void
    {
        $error = ErrorParser::parse('openai', 400, [], $body);

        self::assertInstanceOf(ContextLengthExceededException::class, $error);
        self::assertTrue($error->isContextLength());
    }

    public function testRetryAfterAcceptsHttpDates(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', time() + 30);
        $error = ErrorParser::parse('openai', 503, ['retry-after' => [$at]], '{}');

        self::assertNotNull($error->retryAfter);
        self::assertGreaterThan(20.0, $error->retryAfter);
        self::assertLessThanOrEqual(30.0, $error->retryAfter);
    }

    public function testLongUnstructuredBodiesAreTruncated(): void
    {
        $error = ErrorParser::parse('openai', 500, [], str_repeat('a', 900));

        self::assertSame(512, strlen($error->detail));
    }
}
