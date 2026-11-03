---
paths:
  - 'src/Enums/**'
---

# Enums

## Fixed sets are backed enums
A fixed set of values is a string-backed enum in `src/Enums/` (`tests/Unit/ArchTest.php` checks the folder holds only enums). Name it in the singular with no `Enum` suffix; cases are PascalCase (`Stt = 'stt'`, `Hd = 'hd'`).

## Stored values are forever
Enum values are written to users' databases (`model_type`, `pricing_tier`) and their config (`default_tier`). Never rename or remove a value; add a new case instead.

## Every value a handler can produce needs a case
`PricingTier::tryFrom()` returns null for an unknown value and the caller falls back to the default, so a missing case silently bills the wrong price (`hd` once fell back to standard). When a handler or pricing class starts using a new tier or type, add the case first and test that it resolves.

## Compare cases, not strings
Compare against enum cases in code (`ModelType::Stt`, `PricingTier::Flex->value` at the edges). Use `->value` only where a string is stored or sent: columns, config, JSON, `RequestContext` fields that hold strings.

## Behaviour lives on the enum
Put logic about a case on the enum with an exhaustive `match ($this)`: no `default`, no `switch`. Labels and descriptions shown on the dashboard come from the enum (`label()`, `description()`).
