<?php

declare(strict_types=1);

namespace LlmKit\Cli;

use LlmKit\ChunkKind;
use LlmKit\Config;
use LlmKit\EmbedRequest;
use LlmKit\Exception\InvalidRequestException;
use LlmKit\Exception\LlmKitException;
use LlmKit\Internal\Json;
use LlmKit\LlmKit;
use LlmKit\Message;
use LlmKit\Request;
use RuntimeException;

/**
 * Command is the llmkit CLI: chat with any supported model, stream the reply,
 * embed text, or show how a model name resolves.
 *
 *     llmkit chat -m claude-sonnet-4-5 "Explain generators in one paragraph"
 *     echo "Summarise this" | llmkit chat -m llama3.2:3b --stream
 *     llmkit embed -m voyage-3-large "first" "second"
 *     llmkit resolve gpt-5 openrouter/openai/gpt-4o
 */
final class Command
{
    private const string USAGE = <<<'TXT'
        usage:
          llmkit chat    -m <model> [--system text] [--stream] [--max-tokens n] [--temperature t] [--json] [prompt...]
          llmkit embed   -m <model> [--json] [text...]
          llmkit resolve <model>...

        Prompts and texts default to stdin when no arguments are given.
        Model names are "<model>" or "<provider>/<model>"; keys come from the usual environment variables.
        TXT;

    /**
     * run executes one CLI invocation and returns the process exit code.
     *
     * @param list<string> $args
     * @param resource     $stdin
     * @param resource     $stdout
     * @param resource     $stderr
     */
    public static function run(array $args, mixed $stdin, mixed $stdout, mixed $stderr): int
    {
        try {
            return self::dispatch($args, $stdin, $stdout);
        } catch (LlmKitException $e) {
            fwrite($stderr, 'llmkit: ' . $e->getMessage() . "\n");

            return 1;
        } catch (RuntimeException $e) {
            fwrite($stderr, 'llmkit: ' . $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @param list<string> $args
     * @param resource     $stdin
     * @param resource     $stdout
     */
    private static function dispatch(array $args, mixed $stdin, mixed $stdout): int
    {
        $command = $args[0] ?? '';
        $rest = array_slice($args, 1);

        return match ($command) {
            'chat' => self::chat($rest, $stdin, $stdout),
            'embed' => self::embed($rest, $stdin, $stdout),
            'resolve' => self::resolve($rest, $stdout),
            '', '-h', '--help', 'help' => self::usage($stdout),
            default => throw new RuntimeException('unknown command "' . $command . "\"\n\n" . self::USAGE),
        };
    }

    /**
     * @param list<string> $args
     * @param resource     $stdin
     * @param resource     $stdout
     */
    private static function chat(array $args, mixed $stdin, mixed $stdout): int
    {
        $flags = Flags::parse($args, ['stream', 'json']);
        $model = $flags->string('m', $flags->string('model'));
        if ($model === '') {
            throw new RuntimeException('chat: -m <model> is required');
        }
        $request = new Request(maxTokens: $flags->int('max-tokens'));
        $system = $flags->string('system');
        if ($system !== '') {
            $request->append(Message::system($system));
        }
        $request->append(Message::userText(self::input($flags->operands(), $stdin, ' ')));
        $temperature = $flags->float('temperature');
        if ($temperature !== null) {
            $request->temperature = $temperature;
        }
        $config = (new Config())->withTimeout($flags->float('timeout') ?? 300.0);

        if ($flags->bool('stream') && !$flags->bool('json')) {
            foreach (LlmKit::streamer($model, $config)->stream($request) as $chunk) {
                if ($chunk->kind === ChunkKind::Text) {
                    fwrite($stdout, $chunk->text);
                }
            }
            fwrite($stdout, "\n");

            return 0;
        }

        $response = LlmKit::chatter($model, $config)->chat($request);
        fwrite($stdout, $flags->bool('json') ? Json::encodePretty($response) . "\n" : $response->text() . "\n");

        return 0;
    }

    /**
     * @param list<string> $args
     * @param resource     $stdin
     * @param resource     $stdout
     */
    private static function embed(array $args, mixed $stdin, mixed $stdout): int
    {
        $flags = Flags::parse($args, ['json']);
        $model = $flags->string('m', $flags->string('model'));
        if ($model === '') {
            throw new RuntimeException('embed: -m <model> is required');
        }
        $inputs = $flags->operands();
        if ($inputs === []) {
            $inputs = array_values(array_filter(
                explode("\n", rtrim(self::input([], $stdin, "\n"), "\n")),
                static fn(string $line): bool => trim($line) !== '',
            ));
        }
        $config = (new Config())->withTimeout($flags->float('timeout') ?? 120.0);
        $response = LlmKit::embedder($model, $config)->embed(new EmbedRequest($inputs));
        if ($flags->bool('json')) {
            fwrite($stdout, Json::encodePretty($response) . "\n");

            return 0;
        }
        foreach ($response->embeddings as $vector) {
            fwrite($stdout, Json::encode($vector) . "\n");
        }

        return 0;
    }

    /**
     * @param list<string> $args
     * @param resource     $stdout
     */
    private static function resolve(array $args, mixed $stdout): int
    {
        if ($args === []) {
            throw new RuntimeException('resolve: at least one model name is required');
        }
        foreach ($args as $name) {
            [$provider, $model] = LlmKit::parseModel($name);
            fwrite($stdout, $name . "\t" . $provider->id() . "\t" . $model . "\n");
        }

        return 0;
    }

    /** @param resource $stdout */
    private static function usage(mixed $stdout): int
    {
        fwrite($stdout, self::USAGE . "\n");

        return 0;
    }

    /**
     * @param list<string> $args
     * @param resource     $stdin
     */
    private static function input(array $args, mixed $stdin, string $separator): string
    {
        if ($args !== []) {
            return implode($separator, $args);
        }
        $text = stream_get_contents($stdin);
        if (!is_string($text) || trim($text) === '') {
            throw new InvalidRequestException('no input: pass text as arguments or on stdin');
        }

        return $text;
    }
}
