# Dashboard

Spectra ships with a dashboard for exploring your AI traffic. It lives at `/spectra` and, by default, opens only in the `local` environment. To open it elsewhere, define the `viewSpectra` gate (see [Dashboard Authorization](/installation#dashboard-authorization)). To change its path, middleware or layout, see [Configuration](/configuration#dashboard).

Every screen has a period picker (today, this week, this month, this year, all time) and a date range picker. The lists filter by model type, provider, tag, finish reason and more, and keep the filters in the URL, so you can share a filtered view.

As everywhere in Spectra, request counts include failed requests, while cost and latency cover successful requests only (see [Costs](/costs#querying-costs)).

<a name="overview"></a>
## Overview

<img class="screenshot light-only" src="/images/dashboard/overview.png" alt="The dashboard overview" />
<img class="screenshot dark-only" src="/images/dashboard/overview-dark.png" alt="The dashboard overview in dark mode" />

The overview answers "how much AI are we using, and what does it cost?" at a glance:

- **Requests, spend, tokens and average latency** for the period. Click **By type** to see the latency of each model type.
- **Media usage**: images, videos, text-to-speech characters and speech-to-text audio, each with its cost. A kind only shows when the period has that traffic.
- **Requests over time**, one point per day.
- **Breakdown** of requests by provider or by model type.
- **Top models** by request volume, and the **recent requests**.
- **Alerts**: each [alert rule](/alerts) with its value now and whether it is firing. It shows once you configure rules.
- **Performance**: each model's error rate and its p50, p95 and p99 latency, and the median time to first token of streamed calls.
- **Scores**: each [score](/scores) name given in the period, with the share of yes, the average, or the most common labels. It shows once your app records scores.

The request, trace and conversation pages list their own scores too, with any comment, who gave each one, and a link to the call a score was given to.

The `dashboard.layout` option narrows the dashboard to one model type, for example `text` or `image`. See [Layout Modes](/installation#layout-modes).

<a name="requests"></a>
## Requests

<img class="screenshot" src="/images/dashboard/requests.png" alt="The requests list" />

The requests list shows every tracked AI call: its model and type, its tags, its usage (tokens, images, characters or seconds), its cost, latency and status. Search by model, or paste a request ID, response ID or trace ID. Filter by model type, provider, tag, finish reason and more, and sort by time, cost, usage or latency.

<a name="request-details"></a>
### Request Details

<img class="screenshot" src="/images/dashboard/request-detail.png" alt="A single request" />

Open a request to see everything Spectra recorded about it:

- **Cost**, split into input and output, **latency** with the time to first token for streamed responses, **tokens** in and out, and the **throughput** of the stream.
- **Trace**: when the call is part of a [trace](/usage#traces) with more than one call, the other calls of the trace and a link to open it.
- **Token usage**: input split into uncached tokens, cache reads and cache writes, and output split into the answer and reasoning, each with its cost.
- **Details**: the request and response IDs, endpoint, finish reason, pricing tier, trace, conversation and source.
- **Payload**: the stored response and request. Generated images, audio and video show in place when [media persistence](/configuration#media-persistence) is on.

The payload shows only what your [storage settings](/configuration#storage) keep.

<a name="traces"></a>
## Traces

<img class="screenshot" src="/images/dashboard/traces.png" alt="The traces list" />

A trace groups the AI calls of one HTTP request, queued job or Artisan command. The traces list shows one row per trace: where it came from, the models it used, its trackable, its calls and failed calls, its duration and its cost. Above the list are totals for every trace you filter for. See [Traces](/usage#traces) for how calls are grouped.

<a name="trace-timeline"></a>
### Trace Timeline

<img class="screenshot" src="/images/dashboard/trace-detail.png" alt="A trace timeline" />

Open a trace to see its calls on one timeline, in the order they started, to the millisecond. The request, job or command sits at the top, and the calls are nested under the [spans](/usage#spans) they ran in, such as the `retrieve-context` span above. A [tool](/tools) shows with a wrench between the calls around it; click it to see its arguments and result. The side panel shows the cost, calls and tokens per model, the trackable, the origins and the tags.

<a name="conversations"></a>
## Conversations

<img class="screenshot" src="/images/dashboard/conversations.png" alt="The conversations list" />

The conversations list shows one row per [conversation](/usage#conversation-tracking): its turns and failed calls, the models it used, its trackable, its cost and its last activity.

<a name="conversation-transcript"></a>
### Conversation Transcript

<img class="screenshot" src="/images/dashboard/conversation-detail.png" alt="A conversation transcript" />

Open a conversation to read it as a transcript. Each turn shows the user's message and the model's reply, the calls the turn made, what the turn cost and what the conversation has cost so far. A failed call is marked in red. The transcript needs `store_prompts` and `store_responses`; with either off, the turns still show their calls and costs.

<a name="prompts"></a>
## Prompts

The prompts screen compares the versions of each [prompt](/prompts) your calls were tagged with: their calls, error rate, average cost, tokens and latency, their top scores, and when each was last used. Click a version to see its calls. The screen lists the prompts once your app tags calls with `Spectra::withPrompt()`.

<a name="errors"></a>
## Errors

The errors screen groups the period's failed calls by cause, so a rate limit that hit 400 times is one row, not 400. It shows how many calls failed and the error rate, then each group with the most failures first:

- the provider, status code and error type, and the latest message;
- how many calls failed this way, and their share of all failures;
- failures per day over the last 14 days of the period;
- the models it hit, and when it was first and last seen.

Click a group to see its calls in the requests list, or its last-seen time to open the latest one. A failed call's page links to the calls that failed the same way.

Two failures are in the same group when they have the same provider, status code and error type, and the same message once the parts that change from call to call are taken out: ids, numbers, URLs, emails, IP addresses, masked keys and the model's name. "Rate limit reached for gpt-4o … try again in 2s" and "Rate limit reached for gpt-4o-mini … try again in 14s" are one group. A call counts as failed when it got no 2xx status.

Failures saved before the [error fingerprint migration](/migrations#error-fingerprint-migration) aren't grouped until you run [`spectra:group-errors`](/commands#spectra-group-errors); the screen says how many there are.

<a name="costs"></a>
## Costs

<img class="screenshot" src="/images/dashboard/costs.png" alt="The costs screen" />

The costs screen shows where the money goes:

- **Total spend**, the **top provider** and **top model**, and how many models you used.
- **Spend over time**, one point per day.
- **Breakdown** of spend by provider or by model type.
- **Cost by model**, with the usage in the model's own unit: tokens, images, videos or characters.
- **Top users** by spend.

See [Costs](/costs) for how each cost is calculated.

<a name="trackables"></a>
## Trackables

<img class="screenshot" src="/images/dashboard/trackables.png" alt="The trackables list" />

A trackable is the model a request is attributed to: the signed-in user by default, or a team, project or any other Eloquent model you choose (see [User Attribution](/usage#user-attribution)). The trackables list shows each one's requests, tokens, media, cost, average latency and most used model. Filter by trackable type and provider, and sort by cost, requests or usage.

<a name="trackable-details"></a>
### Trackable Details

<img class="screenshot" src="/images/dashboard/trackable-detail.png" alt="One user's usage" />

Open a trackable to see its spend and requests over time, the providers and models it uses, and its latest requests. This is the page to check when one user's spend looks off, or before you set them a [budget](/budgets).

<a name="maintenance"></a>
## Maintenance

The wrench icon in the top bar opens the Maintenance screen. It shows how many requests, spans and daily stats are stored and when the oldest request was made, and it runs the upkeep that is otherwise on the command line:

- **Prune old data.** Pick how much to keep (24 hours to 1 year) and, optionally, how many days of daily stats to keep. The screen counts what would be deleted before you confirm. It runs [`spectra:prune`](/commands#spectra-prune) on the queue.
- **Pause recording.** While paused, AI calls keep working but nothing is stored, so their cost doesn't count toward budgets. Same as [`spectra:pause`](/commands#spectra-pause).
- **Rebuild stats.** Recomputes the daily stats of the days whose requests are still stored, on the queue. Days whose requests were pruned keep their stats.
- **Prices.** Reads the providers' pricing pages with Firecrawl (the same check as [`spectra:pricing-scrape`](/commands#spectra-pricing-scrape)) and lists, per model, each price that differs from the built-in catalog. Nothing changes until you tick the models to apply: they are written to the [scraped prices file](/pricing#scraping-prices), which the other models in the file keep. Check each change against the provider's page first, since Firecrawl's extraction can misread a page. The file is written on the server that handled the request, so commit it, and restart queue workers to pick up the new prices. Requests are billed with the file only when `SPECTRA_USE_SCRAPED_PRICES=true`.

Prunes, rebuilds and price checks run as queued jobs on Spectra's queue connection (`queue.connection` and `queue.queue`), one of each at a time, and the screen refreshes while one is queued. Without a queue worker they wait in the queue; with the `sync` driver they run during the request.

Anyone who can open the dashboard sees this screen. Running its actions also needs the `manageSpectra` gate, which allows only the `local` environment unless you define it (see [Dashboard Authorization](/installation#dashboard-authorization)). Set `dashboard.maintenance` to `false` to keep these actions on the command line. The pause flag, the pending price check and the last runs are kept in your default cache store.

<a name="prices"></a>
## Prices

Every price on the dashboard uses the same format: two decimals from $1 (`$1,234.56`), four decimals below $1 (`$0.0579`, `$0.0007`), and `<$0.0001` for anything smaller. Hover a rounded price to see the exact amount (`$0.00000544`). Spectra stores costs with more precision than it shows; see [Costs](/costs).

<a name="dark-mode"></a>
## Dark Mode

The dashboard follows your system theme. The moon and sun icon in the top bar switches it, and the dashboard remembers your choice in the browser.

<a name="demo-data"></a>
## Trying It With Demo Data

To try the dashboard without sending any AI requests, clone the repository and build the workbench. It seeds a month of traffic across 9 providers and 8 users, recorded through the real tracking pipeline:

```bash
composer install
vendor/bin/testbench workbench:build
vendor/bin/testbench serve
```

Then open `http://localhost:8000/spectra`.
