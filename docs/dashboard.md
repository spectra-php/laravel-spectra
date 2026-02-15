# Dashboard

Spectra includes a built-in single-page application (SPA) dashboard for exploring AI request data, analyzing costs, and viewing per-user analytics. The dashboard is served directly from your Laravel application and requires no separate frontend build or deployment.

<img src="/images/dashboard/home.png" alt="Spectra Dashboard" style="border-radius: 8px; margin-top: 16px;" />

<a name="accessing-the-dashboard"></a>
## Accessing the Dashboard

By default, the dashboard is available at `/spectra` on your application's domain. The path, domain, and middleware are all configurable in `config/spectra.php`:

```php
'dashboard' => [
    'domain' => null,                    // Optional subdomain (e.g., 'spectra')
    'enabled' => true,
    'path' => env('SPECTRA_PATH', 'spectra'),
    'middleware' => ['web'],             // Add 'auth' for authentication
    'layout' => 'full',                  // Display mode
    'date_format' => 'M j, Y g:i:s A',
],
```

<a name="authorization"></a>
## Authorization

Dashboard access is controlled by the `viewSpectra` gate. By default, only the `local` environment is permitted. To allow access in production or staging environments, define the gate in a service provider:

```php
Gate::define('viewSpectra', function ($user) {
    return $user->isAdmin();
});
```

If your application provides Spectra security via another method, such as IP restrictions or a VPN, you may need to change the closure signature to `function ($user = null)` to allow unauthenticated access to the gate check.

<a name="screens"></a>
## Screens

<a name="requests"></a>
### Requests

The main request explorer displays all tracked AI requests in a paginated, filterable list. Each row shows the provider with its display name and logo, the model used, token usage, calculated cost, latency, and HTTP status code. You can filter the list by provider, model, HTTP status, custom tags, trace ID, and date range. This screen is the primary interface for investigating individual AI operations and understanding usage patterns.

<a name="request-detail"></a>
### Request Detail

Clicking any request in the explorer opens the detail view, which provides a comprehensive breakdown of the request:

- **Overview** — Provider, model, model type, token counts, cost, latency, and status at a glance.
- **Request payload** — The full JSON body sent to the AI provider, including messages, parameters, and tool definitions.
- **Response payload** — The full JSON response with the AI's generated output.
- **Tags and metadata** — Any custom tags or context attached to the request.
- **Media** — Generated images or videos, if media persistence is enabled.

<a name="cost-analysis"></a>
### Cost Analysis

The cost analysis screen provides a breakdown of AI spending across providers, models, and time periods. You can view total spend by provider, cost per model, and spending trends over time. This screen helps you identify which models and features are driving the most cost and spot unexpected spending patterns.

<a name="providers"></a>
### Providers

The providers screen shows all configured providers with their associated models, model snapshots, and operational status.

<a name="trackables"></a>
### Trackables

The trackables screen provides per-user or per-entity analytics. You can see which users are making the most requests, view cost breakdowns per user, and drill into a specific user's request history. This is valuable for understanding usage distribution across your user base and identifying heavy consumers.

<a name="layout-modes"></a>
## Layout Modes

The `dashboard.layout` configuration option controls which model types are displayed in the dashboard. This is useful if your application only uses a subset of model types and you want a focused view:

| Layout | Shows |
| --- | --- |
| `full` | All model types with a type distribution chart |
| `text` | Text completions only |
| `embedding` | Embeddings only |
| `image` | Image generation only |
| `video` | Video generation only |
| `audio` | TTS and STT metrics |

<a name="api-endpoints"></a>
## API Endpoints

All dashboard data is served through JSON API endpoints under `/{spectra-path}/api/`. These endpoints are protected by the same `viewSpectra` gate and can be used to build custom dashboards or integrate Spectra data into other tools.

### Analytics

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/config` | Dashboard configuration and feature flags |
| GET | `/api/stats` | Aggregated usage statistics across all dimensions |
| GET | `/api/costs` | Cost breakdown by provider, model, and time period |

### Requests

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/requests` | Paginated request list with full filter support |
| GET | `/api/requests/{id}` | Single request detail with payloads and metadata |
| GET | `/api/requests/{id}/video` | Download a generated video file |
| GET | `/api/tags` | All available tags for filter dropdowns |

### Trackables

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/trackables` | List all tracked users and entities with usage summaries |
| GET | `/api/trackables/view/{id}` | Detailed analytics for a specific entity |

### Providers

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/api/providers` | Provider list with logos and metadata |

<a name="disabling-the-dashboard"></a>
## Disabling the Dashboard

To run Spectra in headless mode — tracking requests and calculating costs without serving the dashboard UI — disable it in the configuration:

```php
'dashboard' => [
    'enabled' => false,
],
```

Or via environment variable:

```bash
SPECTRA_DASHBOARD_ENABLED=false
```

When the dashboard is disabled, all tracking, cost calculation, and API functionality continues to work. Only the web routes and UI assets are not registered.
