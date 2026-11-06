# Models

Every tracked request gets a **model type**. The model type decides which metrics are extracted, which pricing formula applies, and how the request is shown in the dashboard. Spectra detects it from the endpoint and the shape of the response, so you don't need to configure anything.

<a name="supported-providers"></a>
## Supported Providers

- OpenAI (including [Azure OpenAI](/configuration#azure-openai))
- Anthropic
- Google AI
- Groq
- xAI
- OpenRouter
- Ollama
- Replicate
- Cohere
- Mistral
- fal.ai
- Scaleway
- ElevenLabs

To support a provider not listed here, see [Custom Providers](/custom-providers).

<a name="model-types"></a>
## Model Types

<a name="text-completions"></a>
### Text Completions

Text generation is the most widely supported model type. It covers chat completions, message generation, and content generation across all major providers.

| Metric | Column | Description |
| --- | --- | --- |
| Prompt tokens | `prompt_tokens` | The full prompt: uncached tokens, cache reads, and cache writes |
| Completion tokens | `completion_tokens` | The full output, including reasoning tokens |
| Cached tokens | `cached_tokens` | Prompt tokens read from the provider's prompt cache (part of `prompt_tokens`) |
| Cache write tokens | `cache_write_tokens` | Prompt tokens written to the prompt cache (part of `prompt_tokens`) |
| Reasoning tokens | `reasoning_tokens` | Output tokens used for internal reasoning (part of `completion_tokens`) |
| Finish reason | `finish_reason` | Why generation stopped: `stop`, `length`, `tool_calls`, `end_turn`, etc. |

Spectra uses the same rule for every provider, even when the provider reports usage in another way. For example, Anthropic leaves cache tokens out of `input_tokens`, and Gemini leaves thinking tokens out of its output count. Spectra adds them back, so `prompt_tokens` and `completion_tokens` always hold the full totals.

<a name="cached-token-pricing"></a>
#### Cached Token Pricing

Providers such as OpenAI, Anthropic, Google, and xAI offer prompt caching. Tokens read from the cache are billed at a lower price. Some providers also bill cache **writes** at their own price: Anthropic has 5-minute and 1-hour writes, and newer OpenAI models have one write price. Spectra detects these tokens automatically and bills each part at its own price:

```
uncached = prompt_tokens - cached_tokens - cache_write_tokens

prompt_cost = ( uncached           × input_price
              + cached_tokens      × cached_input_price
              + cache_write_tokens × cache_write_price
              ) / 1,000,000
```

This is a short version. The full formula, with 1-hour writes, audio and image tokens, and fallbacks, is in [Costs](/costs#token-based-cost-formula).

<a name="embeddings"></a>
### Embeddings

An embedding turns a piece of text into a list of numbers that stands for its meaning. Texts that mean similar things get similar numbers, so your app can find the documents closest to a question, group similar texts, or compare two of them. Unlike text completions, embedding models produce no output tokens, so the cost is based only on the input token count.

| Metric | Column | Description |
| --- | --- | --- |
| Prompt tokens | `prompt_tokens` | Number of input tokens in the text being embedded |
| Completion tokens | `completion_tokens` | Always `0` for embeddings |

When you embed several texts in one request (batch embeddings), Spectra tracks the total token count across all inputs.

<a name="image-generation"></a>
### Image Generation

Image generation APIs typically return temporary URLs that expire after a set period. When [media persistence](/configuration#media-persistence) is enabled, Spectra downloads and stores generated images before the URLs expire.

| Metric | Column | Description |
| --- | --- | --- |
| Image count | `image_count` | Number of images generated in the request |

Image models are priced **per image**, **per megapixel**, or **per token** (with separate image token prices), depending on the model. The cost can also depend on the quality setting (for example, the `hd` tier).

<a name="video-generation"></a>
### Video Generation

Video generation is usually asynchronous: you submit a creation request and then poll for the result. Polls that return an unfinished job are not recorded (Spectra's video handlers implement the `SkipsResponse` interface). When the same job is fetched more than once under the same provider response id, Spectra updates one row instead of adding new ones. The job counts as one request with one cost in the daily stats and budgets.

| Metric | Column | Description |
| --- | --- | --- |
| Video count | `video_count` | Number of videos generated |
| Duration | `duration_seconds` | Total duration of the generated video in seconds |

Video models are priced **per second** of video or **per video**, depending on the model. The poll that fetches a result usually has no request body. So Spectra keeps the submit request's body (without inline `data:` files) in your cache for a day, and uses it to measure and price the result: its length (`duration`, or fal.ai's `num_frames`), resolution and audio. When the provider reports how long the job took to generate, that time is stored as the latency, not the time of the last poll. Media persistence works the same way as for images.

<a name="text-to-speech"></a>
### Text-to-Speech

TTS endpoints return binary audio (MP3, Opus, etc.) instead of JSON. The audio itself is not stored in the database. Spectra records only the request parameters and the extracted metrics.

| Metric | Column | Description |
| --- | --- | --- |
| Input characters | `input_characters` | Number of characters in the input text |

TTS models are priced **per million characters**, **per minute** of audio, or **per token**, depending on the model.

<a name="speech-to-text"></a>
### Speech-to-Text

STT requests send the audio file as multipart form data instead of JSON. Spectra handles this for you.

| Metric | Column | Description |
| --- | --- | --- |
| Duration | `duration_seconds` | Length of the input audio in seconds |
| Prompt tokens | `prompt_tokens` | Token count from the transcription (when available) |
| Completion tokens | `completion_tokens` | Token count from the output (when available) |

STT models are priced **per minute** of input audio, or **per token** for token-billed models.

<a name="documents"></a>
### Documents (OCR) {#documents}

Document endpoints such as Mistral OCR bill by page. They are recorded as text requests.

| Metric | Column | Description |
| --- | --- | --- |
| Page count | `page_count` | Number of pages processed |

Document models are priced **per page**.

<a name="not-yet-supported"></a>
### Not Yet Supported

| Category | Example Models | Reason |
| --- | --- | --- |
| Realtime (WebSocket) | gpt-4o-realtime-preview, gpt-4o-mini-realtime-preview | Realtime models use persistent WebSocket connections, not HTTP requests and responses |
