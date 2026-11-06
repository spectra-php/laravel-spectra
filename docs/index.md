---
layout: home

hero:
  name: "Spectra"
  text: "AI Observability for Laravel"
  tagline: "Track every AI request. Calculate costs. Enforce budgets. Inspect traffic end-to-end."
  image:
    src: /images/logo.svg
    alt: Spectra Logo
  actions:
    - theme: brand
      text: Get Started
      link: /introduction
    - theme: alt
      text: Installation
      link: /installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/spectra-php/laravel-spectra

features:
  - title: Automatic Tracking
    details: Tracks AI requests from Laravel's HTTP client, the OpenAI PHP SDK, and raw Guzzle clients, with no code changes.
  - title: Multimodal Support
    details: Track text completions, embeddings, image generation, video generation, text-to-speech, and speech-to-text across all major AI providers in one place.
  - title: Multi-Unit Pricing
    details: Token, image, megapixel, video, audio duration, character, page, and per-request pricing, with prompt-cache prices, long-context prices, and tier support including batch, flex, priority, and fast tiers.
  - title: Budget Enforcement
    details: Define daily, weekly, and monthly spending limits per user, team, or any Eloquent model. Enforce hard blocks via middleware or fire soft warning events at configurable thresholds.
  - title: Real-Time Dashboard
    details: A built-in dashboard for exploring requests, inspecting payloads, comparing model costs, checking which prices bill your requests, and viewing per-user analytics.
  - title: Streaming & Media Persistence
    details: Streamed responses are tracked automatically, with time-to-first-token metrics. Optionally download and persist generated images and videos to any Laravel filesystem disk before provider URLs expire.
---
