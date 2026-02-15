---
layout: home

hero:
  name: "Laravel Spectra"
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
    details: Intercepts AI requests from Laravel's HTTP client, the OpenAI PHP SDK, and raw Guzzle clients — zero code changes required.
  - title: Multimodal Support
    details: Track text completions, embeddings, image generation, video generation, text-to-speech, and speech-to-text operations across all major AI providers in a single, unified observability layer.
  - title: Multi-Unit Pricing
    details: Token, image, video, audio duration, and character-based pricing with full tier support including OpenAI batch, flex, and priority tiers as well as Anthropic batch pricing.
  - title: Budget Enforcement
    details: Define daily, weekly, and monthly spending limits per user, team, or any Eloquent model. Enforce hard blocks via middleware or fire soft warning events at configurable thresholds.
  - title: Real-Time Dashboard
    details: A built-in single-page application for exploring requests, drilling into payloads, comparing model costs, managing the pricing catalog, and viewing per-user analytics.
  - title: Streaming & Media Persistence
    details: Track streaming SSE responses with time-to-first-token metrics. Optionally download and persist generated images and videos to any Laravel filesystem disk before provider URLs expire.
---
