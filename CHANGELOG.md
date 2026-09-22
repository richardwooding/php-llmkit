# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

PHP port of [richardwooding/llmkit](https://github.com/richardwooding/llmkit)
v0.3.0, covering the whole library surface:

- Core vocabulary: `Message` and the seven `Part` types with a JSON codec,
  `Request`, `Response`, `Chunk`, `Usage`, `Tool`, `ToolChoice`,
  `ResponseFormat`, `ReasoningConfig`, `CacheConfig`, `EmbedRequest`,
  `RerankRequest` and `Config`.
- Capability interfaces `Chatter`, `Streamer`, `Embedder`,
  `MultimodalEmbedder`, `Reranker` and `TokenCounter`; a provider implements
  only what its API supports.
- `Registry` and the `LlmKit` facade: model-name routing, `provider/model`
  prefixes, the `LLMKIT_DEFAULT_PROVIDER` fallback chain and capability checks
  that fail before any network call.
- Providers: OpenAI (Responses API), Anthropic, Vertex AI (REST), Ollama,
  DeepSeek, Cohere, Groq, x.ai, Hugging Face, OpenRouter and VoyageAI, plus the
  `OpenAiCompat` transport for registering custom Chat Completions endpoints.
- `Stream::collect()` for turning a stream back into a `Response`, and
  `Tools::run()` for the tool-call loop.
- `Catalog`: context windows, output limits, USD-per-Mtok prices including
  cache rates, and capabilities, with `lookup()`, `register()`, `all()` and
  `Model::cost()`.
- HTTP layer with a `Transport` interface, PSR-18 and symfony/http-client
  adapters, a `MockTransport` for tests, SSE and NDJSON readers, and
  error-envelope decoding into `ApiException`, `RateLimitedException` and
  `ContextLengthExceededException`.
- `bin/llmkit` CLI with `chat`, `embed` and `resolve`.

### Changed from the Go library

- Streams are `Generator`s and failures are thrown rather than yielded.
- No `Context`: bound a call with `Config::withTimeout()` and stop a stream by
  abandoning the generator.
- Capabilities are requested by interface name instead of generics, and
  sentinel errors are exception classes.
- Vertex AI over gRPC is not ported.
