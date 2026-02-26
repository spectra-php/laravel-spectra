# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

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
