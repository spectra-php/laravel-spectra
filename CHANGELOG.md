# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

## 1.2.0 - 2026-06-28

### Added

- `Spectra::forUser()` now accepts an integer user ID and loads the configured auth user model (`config('auth.providers.users.model')`) automatically, in addition to a `Model` instance
- Date-suffix tolerant pricing lookups — an undated catalog entry (e.g. `claude-opus-4-8`) now matches the dated snapshot id the API returns (e.g. `claude-opus-4-8-20260528`), and OpenAI-style `gpt-4o-2024-08-06` resolves to `gpt-4o`
- `search` pricing unit and `CostCalculator::calculateBySearches()` for per-search billed models (e.g. Cohere Rerank)
- New built-in models: Anthropic (Opus 4.8/4.7, Sonnet 4.6, Fable 5, Mythos 5), OpenAI (GPT-5.5, GPT-5.5 Pro, GPT-5.4 / mini / nano / pro), Google (Gemini 3.5 Flash, 3.1 Pro, 3.1 Flash-Lite, Embedding 2, 3.1 Flash TTS/Image, Veo 3.1 preview), xAI (Grok 4.3, Grok 4.20 variants, Grok Build 0.1), Mistral (Devstral 2, Mixtral 8x7B/8x22B, NeMo, Moderation, Voxtral TTS/Transcribe)
- Facade `@method` annotations for the `SpectraFake` testing helpers (`assertTracked`, `assertRequestCount`, …)
- `PricingCatalogInvariantsTest` and per-unit cost tests guarding against models that silently bill 0
- `declare(strict_types=1)` across the codebase, enforced via a `declare_strict_types` Pint rule

### Fixed

- Cohere `rerank-v3.5` (and any `search`-unit model) silently billed $0 because the pricing unit was unsupported and fell through to the token path
- Cost was calculated twice — `RequestContext` produced a token-only cost that `RequestPersister` silently overwrote. Both now share a single `RequestCostCalculator`, so the in-memory cost is unit-aware and matches the persisted value
- Corrected stale Mistral prices (Medium 3.5, Small 4, and `mistral-embed` which was priced 10× too low)
- `PruneCommand::handle()` now returns an exit code

### Changed

- Refreshed the built-in pricing catalog to current provider rates (Anthropic, OpenAI, Google, xAI, Mistral)
- Collapsed duplicated provider handler logic into a shared `OpenAiCompatibleChatHandler` base class and an `ExtractsModelField` trait
- `composer analyse` now analyses `src` only (matching CI) instead of erroring on test-only static-analysis false positives
- Removed the dead `Provider::isStreamingResponse()` method and the unused empty `resources/pricing` directory
- Documented a "Keeping Prices Current" workflow (per-provider source URLs + last-reviewed date) in the pricing docs

## v1.1.0 - 2026-06-05

### 1.1.0

#### Fixed

- Widen `trackable_id` from UUID to string on `spectra_requests` and `spectra_daily_stats` so trackable models can use UUIDs, ULIDs, or integer primary keys (a new follow-up migration handles the schema change)
- Remove `formatted_expires_at` from `SpectraRequest::$appends` and append it explicitly in `GetRequestDetails`, preventing `MissingAttributeException` on partial-select endpoints when the consuming app enables strict-mode models

## 1.1.0

### Fixed

- Widen `trackable_id` from UUID to string on `spectra_requests` and `spectra_daily_stats` so trackable models can use UUIDs, ULIDs, or integer primary keys (a new follow-up migration handles the schema change)
- Remove `formatted_expires_at` from `SpectraRequest::$appends` and append it explicitly in `GetRequestDetails`, preventing `MissingAttributeException` on partial-select endpoints when the consuming app enables strict-mode models

## 1.0.0

### Added

- Add support for [Scaleway](https://www.scaleway.com) provider (Generative APIs: chat, embeddings, and Whisper transcription)

## 1.0.0-beta.1 - 2026-06-03

### Added

- Add support for Laravel 13

## 0.2.0 - 2026-01-26

### Breaking Changes

- Extract `extractModelFromResponse()` from `Handler` contract into a new `ExtractsModelFromResponse` interface. Custom handlers that implement this method must now implement `ExtractsModelFromResponse` separately.

### Added

- Add support for [fal.ai](https://fal.ai) provider (image generation)
- Add `ExtractsModelFromResponse` contract for handlers that extract model names from response payloads

### Changed

- Make GPT-5.2 the default model in `budget` instead of GPT-4
- Update `custom-providers.md` documentation

## 0.1.0 - 2026-01-16

- Initial release
