# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

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
