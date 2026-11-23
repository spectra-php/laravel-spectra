# Pricing notes

What the numbers in `catalog.json` don't say: each provider's billing rules, what is deliberately left unpriced, and when a price was last checked by hand. This file is for maintainers and is not shipped with the package.

Prices are kept current from LiteLLM's cost map with `composer pricing-sync`. A provider's pricing page (its `sources` in the catalog) is where a price is checked, not where it is read from. The dates below are the last hand checks against those pages.

## Anthropic (`anthropic`)

- Claude API prices, in US cents per 1M tokens.
- Checked against: https://platform.claude.com/docs/en/about-claude/pricing
- Cache writes bill 1.25x (5 minutes) or 2x (1 hour) the input price and cache reads 0.1x (0.05x on Opus 5.5 / Sonnet 5.5, 0.025x on Fable 5.1 / Mythos 5.1). The batch and fast-mode discounts or premiums apply to those rates too, so each tier lists all five prices. Fast mode is not offered with batch.
- Claude Haiku 5.5 is priced by prompt length: a prompt over 100,000 tokens (cache reads and writes included) pays the higher row for the whole request.
- Not modelled here: Priority Tier (contract prices, it bills like standard below), the 1.1x US-only inference surcharge (applied per request from `usage.inference_geo`, see Anthropic::extractCostMultiplier()) and code execution container-hours (not visible in a response).

## Cerebras (`cerebras`)

- Cerebras Inference prices for chat completions, in US cents per 1M tokens.
- Prices come from LiteLLM's cost map and were added only where models.dev lists the same price. They have not been checked against the provider's pricing page yet.

## Cohere (`cohere`)

- Cohere prices, from cohere.com/pricing (including its legacy-model FAQ) and docs.cohere.com/docs/models.
- Units: Chat and Embed models are US cents per 1M billed tokens (`billed_units`); Rerank is cents per search unit and Parse cents per page. Command A, Embed 4, Embed v3 and Rerank 3.5 are no longer on the pricing page, so their last known prices are kept. Command A+ is left out: the pricing page shows it as free while other pages list a token price, so its price cannot be confirmed.

## DeepInfra (`deepinfra`)

- DeepInfra serverless prices for chat completions, in US cents per 1M tokens.
- DeepInfra reports each request's cost in `usage.estimated_cost`, and Spectra records that cost. These prices are the fallback for a response without it.
- Prices come from LiteLLM's cost map and were added only where models.dev lists the same price. They have not been checked against the provider's pricing page yet.

## ElevenLabs (`elevenlabs`)

- ElevenLabs API prices. API usage is billed in US dollars, not credits.
- Text to speech is cents per 1M characters ($0.08 per 1,000 characters = 8000); speech to text, sound effects and music are cents per minute. Scribe add-ons (keyterm prompting $0.05/hour, transcript editing $0.07/hour) are not included.
- Checked against: https://elevenlabs.io/pricing/api and https://elevenlabs.io/docs/overview/models (last reviewed 2026-10-15).

## fal.ai (`falai`)

- fal.ai prices, in cents per image, per megapixel or per video second.
- Megapixel models round every image up to whole megapixels of 1024 x 1024 pixels (see the fal.ai ImageHandler). Edit endpoints that bill like their base model are aliases. Video prices depend on resolution and audio; see the model's `variants`.
- Checked against: https://fal.ai/pricing and each model page's billing details (image models last reviewed 2026-10-15, video models 2026-11-04). The pricing page lists Kling v3 at a flat $0.14 per second; the model pages bill $0.168 with audio and $0.112 without, which is what is used here. Kling's voice control ($0.196 per second) is not detected.

## Fireworks AI (`fireworks`)

- Fireworks AI serverless prices for chat completions, in US cents per 1M tokens. Model ids are the full `accounts/fireworks/models/...` path the API takes.
- On-demand deployments are billed by GPU time and are not priced here.
- Prices come from LiteLLM's cost map and were added only where models.dev lists the same price. They have not been checked against the provider's pricing page yet.

## Google (Gemini) (`google`)

- Gemini Developer API prices, in US cents per 1M tokens (Veo: cents per second of video).
- Checked against: https://ai.google.dev/gemini-api/docs/pricing Shutdown dates: https://ai.google.dev/gemini-api/docs/deprecations
- Output prices include thinking tokens. `audio_input_price` is set where audio input costs more than text; cached audio (also dearer on some models) is billed at the text cache rate because usage doesn't split cache reads by modality. Image models list the text output rate as `output_price` and the image output rate as `image_output_price`. On Gemini 2.5 Pro, 3.1 Pro Preview and 2.5 Computer Use, prompts over 200K tokens pay a higher row. On several models batch and flex keep the standard cache price instead of discounting it. Context-cache storage (per token per hour) and search grounding (free allowance, then per query) are not per-request costs and are not modelled. Imagen is not listed: Google shut the Imagen models down and removed them from the pricing page, so a `:generateImages` call is recorded and reported as unpriced rather than billed at a remembered rate.

## Groq (`groq`)

- Groq prices, from console.groq.com/docs/models and the model pages.
- Production model ids carry the publisher prefix (`openai/gpt-oss-120b`); the old unprefixed ids stay as aliases. Prompt caching (GPT-OSS models) bills cached input at 50% off. The Batch API is 50% off and does not stack with the cache discount, so batch cached input bills at the batch input price. Flex costs the same as on-demand. Retirement dates come from console.groq.com/docs/deprecations; the Llama 3.x shutdown on 2026-08-16 applies to on-demand use (enterprise contracts are not affected).
- Whisper is priced per hour of audio; the catalog stores it per minute (the hourly price divided by 60). Groq bills at least 10 seconds per transcription, which the Groq TranscriptionHandler applies to the recorded duration. Orpheus text to speech is cents per 1M characters.

## Mistral (`mistral`)

- Mistral AI prices, from docs.mistral.ai (API pricing page and model cards).
- Units: chat and embedding models are US cents per 1M tokens; OCR is cents per page, Voxtral TTS cents per 1M characters and Voxtral transcription cents per audio minute (`usage.prompt_audio_seconds`).
- Cached input is 10% of the input price on every model the pricing page lists (GLM 5.3 Flash: 20%, DeepSeek V4.1 Flash: 2.3%). The Batch API is 50% off for models that support batching, and the batch discount applies to every token type. Retired models predate prompt caching, so they carry no cached price and bill cached tokens at the input rate. Model cards list each model's dated ids (`mistral-medium-2508`, ...); those resolve through aliases or their own entry when the price differs. Retirement dates are the ones the model cards publish. A priority `service_tier` is recorded, but Mistral publishes no separate priority price, so it bills at the standard rate here.

## OpenAI (`openai`)

- OpenAI price catalog, in US cents per 1M tokens (or per unit).
- Prices checked against https://developers.openai.com/api/docs/pricing and the model pages on 2026-10-15; cache-write rates, GPT-6 Rosalind and GPT-6 Astra for Law re-checked on 2026-11-04.
- `priority` holds Fast mode prices (OpenAI renamed Priority processing to Fast mode on 2026-07-30).
- Cache writes (GPT-5.6 and later): `cache_write_5m_price` holds the 30m rate (1.25x input) and `cache_write_1h_price` the 12h rate (2.5x input, Responses API only). Both are total write rates, not surcharges. The usage splits writes by lifetime (`ttl_30m_tokens`, `ttl_12h_tokens`).
- Long context: prompts over 272K input tokens are billed at 2x input and 1.5x output for the whole request (`above_input_tokens: 272000`). For the GPT-6 and GPT-5.6 families the cache rates double too ("2x input and cache rates"). For GPT-5.5 and GPT-5.4 OpenAI only states "2x input and 1.5x output", so their cached rate stays the short-context rate.
- Regional (data residency) endpoints add 10% for models released on or after 2026-03-05. That uplift is not applied here.
- Retired models stay in the catalog so stored requests keep their prices.

## Replicate (`replicate`)

- Replicate prices, in cents per 1M tokens or per unit.
- Official models are billed by input and output (tokens, images, seconds of video). Community models are billed by the time they run on their GPU; those entries are approximations from the model page. FLUX 2 Pro bills per run and per input and output megapixel, but Replicate doesn't return the output size, so it is priced as one 1-megapixel image without input images.
- Checked against: https://replicate.com/pricing and each model page's billing table (last reviewed 2026-10-15).

## Scaleway (`scaleway`)

- Scaleway Generative APIs (serverless) prices, in EURO cents per 1M tokens (Whisper: per audio minute). Costs are converted to the reporting currency with `spectra.costs.exchange_rates`.
- Not modelled per request: the free tier (the first 1,000,000 tokens and 60 audio minutes) and the 50% discount of the Batches API.
- Models only offered as dedicated deployments are not listed: those run on their own hosts and are not tracked. End-of-life models stay for history; Scaleway routes their requests to a replacement model.
- Checked against: https://www.scaleway.com/en/pricing/model-as-a-service/ (Paris) and https://www.scaleway.com/en/docs/generative-apis/reference-content/supported-models/ (last reviewed 2026-10-29).

## Together AI (`together`)

- Together AI serverless prices for chat completions, in US cents per 1M tokens.
- Together's US-only endpoint charges 1.25 times these rates, and dedicated endpoints are billed by the hour; neither is priced here.
- Prices come from LiteLLM's cost map and were added only where models.dev lists the same price. They have not been checked against the provider's pricing page yet.

## xAI (`xai`)

- xAI (Grok) prices, from docs.x.ai/developers/pricing and the model pages.
- Every xAI response reports its exact charge (`cost_in_usd_ticks`), which replaces these estimates; the catalog is the fallback and the source of display names. Rules from the pricing page:
- Long context: once the prompt reaches 200K tokens, every token of the request bills at the long-context rate (`above_input_tokens: 199999`).
- Priority: 2x standard for all token types. Batch: 20% off for grok-4.3 and the grok-4.20 family only. The long-context priority and batch rows apply those multipliers to the long-context rates.
- Image and video generation bill at standard rates, also in batch.
- The models xAI retired on 2026-05-15 keep their old prices for history; requests to those ids after that date are redirected and billed at the replacement's price, hence the dated rows.
