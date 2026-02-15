# Providers

Spectra currently supports OpenAI, Anthropic, Google AI, Groq, xAI, OpenRouter, Ollama, Replicate, ElevenLabs, Cohere, and Mistral. The matrix below shows the capabilities supported for each provider.

Capabilities map to the [model types](/models#model-types) Spectra tracks.

## Capability Matrix

| Provider | Text | Embedding | Image | Video | TTS | STT |
| --- | --- | --- | --- | --- | --- | --- |
| [OpenAI](#openai) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| [Anthropic](#anthropic) | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ |
| [Google AI](#google-ai) | ✅ | ✅ | ✅ | ✅ | ✅ | ➖ |
| [Groq](#groq) | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ |
| [xAI](#xai) | ✅ | ➖ | ✅ | ✅ | ➖ | ➖ |
| [OpenRouter](#openrouter) | ✅ | ➖ | ✅ | ➖ | ➖ | ➖ |
| [Ollama](#ollama) | ✅ | ✅ | ➖ | ➖ | ➖ | ➖ |
| [Replicate](#replicate) | ✅ | ➖ | ✅ | ✅ | ➖ | ➖ |
| [ElevenLabs](#elevenlabs) | ➖ | ➖ | ➖ | ➖ | ✅ | ➖ |
| [Cohere](#cohere) | ✅ | ➖ | ➖ | ➖ | ➖ | ➖ |
| [Mistral](#mistral) | ✅ | ✅ | ➖ | ➖ | ➖ | ➖ |

`✅` Supported, `➖` Not supported.

## Built-in Providers

### OpenAI

Host: `api.openai.com`

> [!NOTE]
> OpenAI is currently the only provider in Spectra with support across all primary capabilities: Text, Embedding, Image, Video, TTS, and STT.

| Capability | Endpoints |
| --- | --- |
| Text | `/v1/chat/completions`, `/v1/completions`, `/v1/responses` |
| Embedding | `/v1/embeddings` |
| Image | `/v1/images/generations`, `/v1/images/edits`, `/v1/images/variations`, `/v1/responses` (image responses) |
| Video | `/v1/videos`, `/v1/videos/{id}` |
| TTS | `/v1/audio/speech` |
| STT | `/v1/audio/transcriptions`, `/v1/audio/translations` |

### Anthropic

Host: `api.anthropic.com`

| Capability | Endpoints |
| --- | --- |
| Text | `/v1/messages` |

### Google AI

Host: `generativelanguage.googleapis.com`

| Capability | Endpoints |
| --- | --- |
| Text | `/{version}/models/{model}:generateContent`, `/{version}/models/{model}:streamGenerateContent` |
| Embedding | `/{version}/models/{model}:embedContent`, `/{version}/models/{model}:batchEmbedContents` |
| Image | `/{version}/models/{model}:generateContent`, `/{version}/models/{model}:streamGenerateContent`, `/{version}/models/{model}:generateImages` |
| Video | `/{version}/models/{model}:predictLongRunning`, `/{version}/models/{model}:fetchPredictOperation` |
| TTS | `/{version}/models/{model}:generateContent`, `/{version}/models/{model}:streamGenerateContent` |

### Groq

Host: `api.groq.com`

| Capability | Endpoints |
| --- | --- |
| Text | `/openai/v1/chat/completions` |

### xAI

Host: `api.x.ai`

| Capability | Endpoints |
| --- | --- |
| Text | `/v1/chat/completions` |
| Image | `/v1/images/generations` |
| Video | `/v1/videos/generations`, `/v1/videos/generations/{request_id}` |

### OpenRouter

Host: `openrouter.ai`

| Capability | Endpoints |
| --- | --- |
| Text | `/api/v1/chat/completions` |
| Image | `/api/v1/chat/completions` (image response shape) |

### Ollama

Hosts: `localhost:11434`, `127.0.0.1:11434`

| Capability | Endpoints |
| --- | --- |
| Text | `/api/chat`, `/api/generate` |
| Embedding | `/api/embed` |

### Replicate

Host: `api.replicate.com`

| Capability | Endpoints |
| --- | --- |
| Text | `/v1/models/{owner}/{model}/predictions` |
| Image | `/v1/models/{owner}/{model}/predictions` |
| Video | `/v1/models/{owner}/{model}/predictions` |

### ElevenLabs

Host: `api.elevenlabs.io`

| Capability | Endpoints |
| --- | --- |
| TTS | `/v1/text-to-speech/{voice_id}`, `/v1/text-to-speech/{voice_id}/stream` |

### Cohere

Host: `api.cohere.com`

| Capability | Endpoints |
| --- | --- |
| Text | `/v2/chat` |

### Mistral

Hosts: `api.mistral.ai`, `codestral.mistral.ai`

| Capability | Endpoints |
| --- | --- |
| Text | `/v1/chat/completions`, `/v1/fim/completions`, `/v1/agents/completions` |
| Embedding | `/v1/embeddings` |

## Notes

- Additional hosts can be detected through custom base URLs (`spectra.endpoint_urls` and `ai.providers.*.url` fallback).
- To support a provider not listed here, see [Custom Providers](/custom-providers).
