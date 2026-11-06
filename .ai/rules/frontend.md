---
paths:
  - 'resources/js/**'
  - 'resources/css/**'
  - 'resources/views/**'
  - 'dist/**'
---

# Dashboard frontend

## A Vue 3 SPA over the dashboard API
The dashboard is Vue 3 with Vue Router and Tailwind 4, in TypeScript (`<script setup lang="ts">`, strict mode). Screens live in `resources/js/screens/{area}/`, shared pieces in `components/`, logic in `composables/` (`useCharts`, `usePeriodQuery`, `useProviders`, …) and shared types in `types/`. It reads only the API in `routes/web.php`; keep the JSON keys, their type in `types/api.ts` (the PHP DTOs in `src/Data` are the source of truth) and the screen that reads them in step. Give API data its real type rather than `any`, and run `npm run typecheck` after a change. `tsconfig.json` allows ES2025 APIs, but nothing is polyfilled and the dashboard supports Safari 16.4, Chrome 111 and Firefox 128, so check a newer API works there before using it.

## Two builds
`resources/js/app.ts` is the dashboard, and `resources/js/viewer.ts` (`vite build --mode viewer`) is the page a dashboard's viewer gets, with none of the admin screens. `npm run build` runs both; each layout inlines one file into one script, so they can't share a chunk. Cards both pages show live in `components/widgets/` and take their data as props, so the Overview and a shared dashboard can't drift apart: change them there. Viewer code (`components/dashboards/` and `components/widgets/`) reaches no dashboard API and needs nothing `App.vue` provides: `useProviders` and `apiRequest` are out, and what it needs comes from the viewer's own endpoint. It reuses `dist/app.css`, whose Tailwind scan covers it; `dist/viewer.css` is ignored.

## Rebuild and commit dist
Users install the package without Node, so the built assets in `dist/` are committed. After changing anything under `resources/js` or `resources/css`, run `npm run build` and commit `dist/` with the change. Never edit `dist/` by hand.

## Show what the API sends
Format numbers, costs and dates in the frontend from the raw values the API returns (cents, ISO dates). The few display strings the API already sends (`formatted_created_at`, `created_at_human`, `formatted_expires_at`) are shown as they come, and so is a plain `Y-m-d` date: a datetime formatter reads it as UTC midnight and can show the day before. Costs are cents and may have sub-cent precision: pass the raw value to the shared formatter (`formatCurrency`), which picks the display precision, and never round it before that.

## Tailwind layers decide what wins
Tailwind 4 uses native CSS layers, so specificity no longer decides between a component rule and a utility. `resources/css/app.css` holds single-class components in `@layer components`, which a utility overrides, and rules on an element inside a component (`.table td`, `.card-header h3`) in `@layer overrides`, which a utility doesn't. A utility that must beat an override, such as a hover colour on a table cell, takes `!`. A component's `<style>` block that uses `@apply` starts with `@reference "../../css/app.css";`.

## Provider logos are keyed by slug
`components/ProviderLogo.vue` maps a provider slug to its logo. A new built-in provider adds its slug there. Keep the entries for providers users add themselves (bedrock, deepseek, meta, microsoft).

## Keep the bundle small
Use the libraries already installed (Chart.js, highlight.js, lodash, the date picker) before adding one; a new dependency ships to every user's dashboard.
