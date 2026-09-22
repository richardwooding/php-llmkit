# llmkit

One PHP client for eleven AI back-ends. Pick a model by name, code against a
one-method interface, and swap vendors without touching call sites.

```php
use LlmKit\LlmKit;
use LlmKit\Request;

$chat = LlmKit::chatter('claude-sonnet-4-5');
$response = $chat->chat(Request::prompt('Explain generators in one paragraph.'));
echo $response->text();
```

A PHP port of [richardwooding/llmkit](https://github.com/richardwooding/llmkit)
(Go). PHP 8.3+, no extensions beyond the standard build. The package depends
only on the PSR HTTP interfaces; bring your own client.

## Why

**Small interfaces.** `Chatter`, `Streamer`, `Embedder`, `Reranker`,
`MultimodalEmbedder` and `TokenCounter` each have one method. Ask for exactly
what you need with `LlmKit::open(Chatter::class, …)`; a provider that lacks the
capability fails at construction with `UnsupportedException`, before any
network call.

**Model-name routing.** `gpt-5`, `claude-sonnet-4-5`, `gemini-2.5-pro`,
`deepseek-reasoner`, `grok-4`, `command-a-03-2025`, `voyage-3-large` and
`llama3.2:3b` resolve on their own. Anything ambiguous takes a prefix:
`groq/llama-3.3-70b-versatile`, `openrouter/openai/gpt-4o`,
`hf/meta-llama/Llama-3.3-70B-Instruct`. Bare open-weight names fall back to a
local Ollama daemon.

**One message model.** Text, images, audio, documents, reasoning, tool calls
and tool results are typed parts; each provider maps what it supports and
rejects the rest up front.

**Streaming as generators.** `stream()` returns a `Generator` of `Chunk`s; the
request is sent when you start iterating and the connection closes when you
stop.

**Escape hatches.** `Request::$extra` and `Request::$providerOptions` merge raw
fields into the wire body; every response keeps its raw JSON in `$raw`.

## Install

```sh
composer require richardwooding/llmkit

# a transport: symfony/http-client streams SSE incrementally
composer require symfony/http-client

# optional
composer require google/auth    # Application Default Credentials for Vertex AI
```

Any PSR-18 client works, but most buffer the whole response body, which delays
streamed chunks until the reply is complete. `symfony/http-client` (used
automatically when installed) hands over frames as they arrive.

```php
use LlmKit\Config;
use LlmKit\Http\Psr18Transport;

$config = (new Config())->withHttpClient($guzzle, $requestFactory, $streamFactory);
// or
$config = (new Config())->withTransport(new Psr18Transport($guzzle, $rf, $sf));
```

## Providers

| Provider | Names | Chat | Stream | Embed | Rerank | Count | Tools | Image | Audio | File/PDF | Cache hints | Auth |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| OpenAI (Responses API) | `gpt-*`, `o*`, `text-embedding-3-*` | ✅ | ✅ | ✅ | – | – | ✅ | ✅ | – | ✅ | auto | `OPENAI_API_KEY` |
| DeepSeek | `deepseek-*` | ✅ | ✅ | – | – | – | ✅¹ | – | – | – | auto | `DEEPSEEK_API_KEY` |
| Ollama | `name:tag`, bare fallback | ✅ | ✅ | ✅ | – | – | ✅ | ✅ | – | – | – | `OLLAMA_HOST` |
| Vertex AI (REST) | `gemini-*`, `text-embedding-*` | ✅ | ✅ | ✅ | – | – | ✅ | ✅ | ✅ | ✅ | auto | ADC / `GOOGLE_CLOUD_PROJECT` |
| Anthropic | `claude-*` | ✅ | ✅ | – | – | ✅ | ✅ | ✅ | – | ✅ | ✅³ | `ANTHROPIC_API_KEY` |
| Cohere | `command*`, `embed-*`, `rerank-*` | ✅ | ✅ | ✅ | ✅ | – | ✅ | ✅ | – | – | – | `COHERE_API_KEY` |
| Groq | `groq/…` | ✅ | ✅ | – | – | – | ✅ | ✅ | – | – | auto | `GROQ_API_KEY` |
| x.ai (Grok) | `grok-*` | ✅ | ✅ | – | – | – | ✅ | ✅ | – | – | auto | `XAI_API_KEY` |
| Hugging Face | `org/model`, `hf/…` | ✅ | ✅ | ✅ | – | – | ✅ | ✅ | – | – | – | `HF_TOKEN` |
| OpenRouter | `openrouter/…` | ✅ | ✅ | ✅ | – | – | ✅ | ✅ | ✅ | ✅ | auto | `OPENROUTER_API_KEY` |
| VoyageAI | `voyage-*` | – | – | ✅² | ✅ | – | – | – | – | – | – | `VOYAGE_API_KEY` |

¹ `deepseek-reasoner` rejects tool definitions; llmkit fails fast with
`UnsupportedException`.
² VoyageAI also implements `MultimodalEmbedder` for text + image + video inputs.
³ "auto" providers cache prompt prefixes on their own and ignore
`Request::$cache`; Anthropic needs explicit `cache_control` breakpoints, which
`Request::$cache` places. "Count" is the `TokenCounter` interface.

Every provider reads its key from the environment variable shown, or from
`Config::withApiKey()`. `withBaseUrl()`, `withTransport()`, `withHttpClient()`,
`withHeader()` and `withTimeout()` apply to all of them.

## Usage

### Streaming

```php
use LlmKit\ChunkKind;

foreach (LlmKit::streamer('llama3.2:3b')->stream($request) as $chunk) {
    match ($chunk->kind) {
        ChunkKind::Text => print($chunk->text),
        ChunkKind::Finish => printf("\n%d tokens\n", $chunk->usage?->outputTokens ?? 0),
        default => null,
    };
}
```

`Stream::collect($generator)` turns a stream back into a `Response`,
reassembling tool-call arguments and reasoning blocks (with their signatures)
so the result can be appended to `$request->messages` and sent back on the next
turn.

### Reasoning

```php
$request->reasoning = new ReasoningConfig(effort: 'high', summary: 'auto');
```

`effort` steers how hard the model thinks. `summary` asks for a readable
summary of the thinking instead of empty blocks: Anthropic's `thinking.display`
takes `summarized`, `omitted` or `updates`, OpenAI's `reasoning.summary` takes
`auto`, `concise` or `detailed`, and each provider translates the other's "show
me a summary" value so one setting works everywhere. Anthropic's `max_tokens`
defaults to 16384 because thinking counts against it.

### Prompt caching

```php
$request->cache = new CacheConfig(system: true, tools: true, turns: 1, ttl: '5m');
```

On Anthropic this places `cache_control` breakpoints after the tool
definitions, after the system prompt and on the last `turns` user-role turns
(at most four in total, stable prefix first). Every other provider caches
prefixes automatically and ignores the field. `Usage::$cachedInputTokens` and
`Usage::$cacheWriteTokens` report what was served from and written to the
cache; both are included in `Usage::$inputTokens`, which is the whole prompt on
every provider.

### Counting tokens

```php
$counter = LlmKit::tokenCounter('claude-opus-5');
$tokens = $counter->countTokens($request); // POST /messages/count_tokens, free
```

Only Anthropic exposes a counting endpoint; `LlmKit::tokenCounter()` on another
provider fails with `UnsupportedException`.

### Model catalog

```php
use LlmKit\Catalog\Catalog;

$model = Catalog::lookup('anthropic/claude-sonnet-4-5'); // alias → claude-sonnet-4-5-20250929
if ($model->known) {
    printf("%d %d %.4f\n", $model->contextWindow, $model->maxOutput, $model->cost($response->usage));
}
```

`Catalog` is a static table of context windows, output limits, list prices per
million tokens including cache read/write rates, and capabilities, checked
against vendor pages on `Catalog::DATA_AS_OF`. `lookup()` strips `provider/`
prefixes and Vertex `@version` suffixes and resolves dated snapshots by prefix;
anything it does not know comes back with `known === false` and a zero cost
rather than a guess. `Catalog::register()` overrides or adds rows.

### Tool calling

```php
$request = new Request(
    messages: [Message::userText('Weather in Cape Town?')],
    tools: [new Tool(
        name: 'weather',
        description: 'Current weather for a city',
        parameters: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    )],
);

$response = Tools::run($chat, $request, [
    'weather' => fn (array $args): string => lookup($args['city']),
], maxIterations: 5);
```

`Tools::run()` appends assistant and tool messages to `$request->messages`
until the model stops calling tools, feeding tool failures back as error
results.

### Multimodal input

```php
$request = new Request([Message::user(
    new TextPart('Summarise the chart and the report.'),
    new ImagePart(file_get_contents('chart.png'), 'image/png'),
    new FilePart(file_get_contents('report.pdf'), 'application/pdf', name: 'report.pdf'),
)]);
```

Providers that cannot accept a part throw `UnsupportedException` before sending
anything.

### Embeddings

```php
$out = LlmKit::embedder('voyage-3-large')->embed(new EmbedRequest(
    inputs: ['first document', 'second document'],
    inputType: EmbedInputType::Document,
));
$vectors = $out->embeddings; // list<list<float>>, one per input
```

### Rerank

```php
$out = LlmKit::reranker('rerank-v3.5')->rerank(new RerankRequest('go iterators', $docs, topN: 3));
foreach ($out->results as $result) {   // best first
    echo $docs[$result->index], ' ', $result->score, "\n";
}
```

### Command line

```sh
llmkit chat -m claude-sonnet-4-5 "Explain generators in one paragraph"
echo "Summarise this" | llmkit chat -m llama3.2:3b --stream
llmkit embed -m voyage-3-large "first" "second"
llmkit resolve gpt-5 openrouter/openai/gpt-4o meta-llama/Llama-3.3-70B-Instruct
```

### Custom OpenAI-compatible endpoints

```php
use LlmKit\Providers\OpenAiCompat\{CompatProvider, EndpointConfig, Quirks};

LlmKit::register(new CompatProvider(new EndpointConfig(
    id: 'vllm',
    baseUrl: 'http://gpu-box:8000/v1',
    keyOptional: true,
    quirks: new Quirks(images: true, streamUsage: true),
)));

$chat = LlmKit::chatter('vllm/my-finetune');
```

### Vertex AI

```php
$config = (new Config())
    ->withValue(VertexClient::OPTION_PROJECT, 'my-project')
    ->withValue(VertexClient::OPTION_LOCATION, 'europe-west1');

$chat = LlmKit::chatter('gemini-2.5-flash', $config);
```

Credentials resolve on the first request, never at construction: a fixed token
from `OPTION_ACCESS_TOKEN`, a callable from `OPTION_TOKEN_PROVIDER`, or
Application Default Credentials through `google/auth`.

## Errors, retries and rate limits

Provider failures are `LlmKit\Exception\ApiException`s carrying the HTTP
status, the provider's error code and any `Retry-After` hint. Rate limits and
context-window overflows arrive as the `RateLimitedException` and
`ContextLengthExceededException` subclasses, so one `catch` works across
vendors. llmkit does not retry; compose that into the HTTP client you pass in.

```php
try {
    $response = $chat->chat($request);
} catch (RateLimitedException $e) {
    sleep((int) ceil($e->retryAfter ?? 1.0));
} catch (ApiException $e) {
    error_log($e->provider . ' ' . $e->status . ' ' . $e->errorCode);
}
```

Everything this library throws implements `LlmKit\Exception\LlmKitException`.

## Resolution rules

1. If the text before the first `/` is a registered provider ID or alias, that
   provider gets the rest (`openrouter/openai/gpt-4o`).
2. Otherwise the first provider whose `matches()` accepts the bare name wins:
   OpenAI, Anthropic, Vertex, DeepSeek, x.ai, Cohere, VoyageAI prefixes; Ollama
   for anything containing `:`; Hugging Face for `org/model`.
3. Otherwise the fallback: `LlmKit::setFallback()`, then
   `LLMKIT_DEFAULT_PROVIDER`, then `ollama`.

## What this is not

Not an agent framework. `Tools::run()` is a small loop; bring your own
planning, memory and observability.

Not a retry or caching layer. Both belong in the HTTP client you pass in.

Not a wrapper around vendor SDKs. Every provider is written against the wire
format.

## Differences from the Go library

- Streams are `Generator`s and failures are thrown, not yielded as a second
  value.
- There is no `Context`; bound a call with `Config::withTimeout()` and stop a
  stream by abandoning the generator.
- Capabilities are checked with `LlmKit::open(Chatter::class, …)` instead of
  generics, and sentinel errors are exception classes.
- Vertex AI over gRPC is not ported.

## Development

```sh
composer install
composer test      # phpunit
composer stan      # phpstan, level max
composer lint      # php-cs-fixer --dry-run
composer check     # all three
```

## License

MIT © 2026 Richard Wooding
