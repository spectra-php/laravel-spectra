---
paths:
  - 'resources/js/**'
  - 'resources/css/**'
  - 'resources/views/**'
  - 'dist/**'
---

# Dashboard frontend

## A Vue 3 SPA over the dashboard API
The dashboard is Vue 3 with Vue Router and Tailwind 3, in plain JavaScript (no TypeScript). Screens live in `resources/js/screens/{area}/`, shared pieces in `components/` and logic in `composables/` (`useCharts`, `usePeriodQuery`, `useProviders`, …). It reads only the API in `routes/web.php`; keep the JSON keys and the screen that reads them in step.

## Rebuild and commit dist
Users install the package without Node, so the built assets in `dist/` are committed. After changing anything under `resources/js` or `resources/css`, run `npm run build` and commit `dist/` with the change. Never edit `dist/` by hand.

## Show what the API sends
Format numbers, costs and dates in the frontend from the raw values the API returns (cents, ISO dates). The few display strings the API already sends (`formatted_created_at`, `created_at_human`, `formatted_expires_at`) are shown as they come. Costs are cents and may have sub-cent precision: pass the raw value to the shared formatter (`formatCurrency`), which picks the display precision, and never round it before that.

## Provider logos are keyed by slug
`components/ProviderLogo.vue` maps a provider slug to its logo. A new built-in provider adds its slug there. Keep the entries for providers users add themselves (bedrock, deepseek, meta, microsoft).

## Keep the bundle small
Use the libraries already installed (Chart.js, highlight.js, lodash, the date picker) before adding one; a new dependency ships to every user's dashboard.
