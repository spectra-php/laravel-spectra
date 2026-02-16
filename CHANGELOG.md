# Changelog

All notable changes to `laravel-spectra` will be documented in this file.

## v0.3.0 - 2026-02-16

### Breaking Changes

- Renamed `configureBudget()` to `configureAiBudget()` on the `HasAiBudget` trait
- Renamed BudgetBuilder methods for clarity: `dailyLimit()` → `dailyCostLimitInCents()`, `weeklyLimit()` → `weeklyCostLimitInCents()`, `monthlyLimit()` → `monthlyCostLimitInCents()`, `totalLimit()` → `totalCostLimitInCents()`, `warningThreshold()` → `warningThresholdPercentage()`, `criticalThreshold()` → `criticalThresholdPercentage()`
- Removed shorthand methods: `setDailyBudget()`, `setWeeklyBudget()`, `setMonthlyBudget()`, `setTotalBudget()` — use `configureAiBudget()` builder instead

### Non-Breaking Changes

- Fixed incorrect namespace references in docs (`GuzzleMiddleware`, `MediaPersister`)
- Fixed `withAITracking` signature in docs to use options array
- Removed non-existent env vars from troubleshooting docs
- Merged Providers and Dashboard doc pages into Models and Installation respectively

## v0.2.0 - 2026-02-15

- Update dependencies

## 0.1.0 - 2026-01-15

- Initial release
