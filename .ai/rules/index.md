# Project Rules Index

Before planning or editing, find every row whose globs match the file's path and read each of those rule files; a file follows all of them. The fixtures in `tests/responses/` are data, not PHP, so only `providers.md` applies to them.

| Applies to | Rule file |
| --- | --- |
| src/Actions/** | .ai/rules/actions.md |
| src/**, config/**, database/**, routes/** | .ai/rules/architecture.md |
| config/**, src/SpectraServiceProvider.php | .ai/rules/config.md |
| src/Http/**, routes/** | .ai/rules/controllers.md |
| src/Data/** | .ai/rules/data.md |
| composer.json, package.json | .ai/rules/dependencies.md |
| docs/**, CHANGELOG.md, README.md | .ai/rules/docs.md |
| src/Enums/** | .ai/rules/enums.md |
| resources/js/**, resources/css/**, resources/views/**, dist/** | .ai/rules/frontend.md |
| src/Jobs/**, src/Events/** | .ai/rules/jobs.md |
| src/**/*.php, tests/**/*.php, database/**, config/**, routes/** | .ai/rules/laravel-first.md |
| database/migrations/** | .ai/rules/migrations.md |
| src/Models/**, src/Support/Query/**, src/Support/Concerns/**, database/factories/** | .ai/rules/models.md |
| src/Pricing/**, src/Support/Pricing/**, docs/pricing.md, workbench/app/Pricing/**, workbench/pricing-drift-baseline.json | .ai/rules/pricing.md |
| src/Providers/**, src/Concerns/**, src/Contracts/**, src/Watchers/**, src/Support/Tracking/**, tests/responses/** | .ai/rules/providers.md |
| src/**/*.php | .ai/rules/src.md |
| tests/**/*.php, database/factories/** | .ai/rules/tests.md |
