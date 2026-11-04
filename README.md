<p align="center">
  <img alt="Spectra" src="art/logo.svg" width="500px" />
</p>

<div align="center">

<p align="center">
<a href="https://github.com/spectra-php/laravel-spectra/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/spectra-php/laravel-spectra/tests.yml?branch=main&label=tests&style=flat" alt="Tests"></a>
<a href="https://github.com/spectra-php/laravel-spectra/actions/workflows/phpstan.yml"><img src="https://img.shields.io/github/actions/workflow/status/spectra-php/laravel-spectra/phpstan.yml?branch=main&label=phpstan&style=flat" alt="PHPStan"></a>
<a href="https://github.com/spectra-php/laravel-spectra/actions/workflows/pint.yml"><img src="https://img.shields.io/github/actions/workflow/status/spectra-php/laravel-spectra/pint.yml?branch=main&label=code%20style&style=flat" alt="Code Style"></a>
<a href="LICENSE"><img src="https://img.shields.io/packagist/l/spectra-php/laravel-spectra?style=flat" alt="License"></a>
</p>

<p align="center">
<a href="https://packagist.org/packages/spectra-php/laravel-spectra"><img src="https://img.shields.io/packagist/v/spectra-php/laravel-spectra?style=flat" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/spectra-php/laravel-spectra"><img src="https://img.shields.io/packagist/dt/spectra-php/laravel-spectra?style=flat" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/spectra-php/laravel-spectra"><img src="https://img.shields.io/packagist/dependency-v/spectra-php/laravel-spectra/php?style=flat" alt="PHP Version"></a>
<a href="https://laravel.com"><img src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat&logo=laravel&logoColor=white" alt="Laravel Version"></a>
<a href="https://spectraphp.com"><img src="https://img.shields.io/badge/docs-spectraphp.com-6366F1?style=flat" alt="Documentation"></a>
</p>

</div>


Spectra is a comprehensive Laravel package for AI observability. It helps you track AI requests, measure usage, calculate costs across different pricing units, enforce budgets, and inspect AI operations through a unified dashboard and API. This lets you focus on shipping reliable AI features without getting buried in provider-specific operational complexity.

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/public/images/dashboard/overview-dark.png" />
    <img alt="The Spectra dashboard overview: requests, spend, tokens and latency, media usage, daily volume and a breakdown by provider" src="docs/public/images/dashboard/overview.png" width="100%" />
  </picture>
</p>

<p align="center">
  <a href="https://mayahi.net/services"><img alt="Hire me — I'm available for consulting and development work" src="art/hire-me.svg" width="100%" /></a>
</p>

## Dashboard

Spectra ships with a dashboard at `/spectra`. Every screen can be narrowed to a period or a date range, and the lists filter by type, provider, tag and more. Click a screenshot to see it at full size.

<table>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/requests.png"><img alt="The requests list" src="docs/public/images/dashboard/requests.png" /></a>
      <p><strong>Requests</strong><br />Every AI call your app makes, with its model, tags, usage, cost, latency and status. Search by model or ID, and filter by type, provider, tag or finish reason.</p>
    </td>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/request-detail.png"><img alt="A single request" src="docs/public/images/dashboard/request-detail.png" /></a>
      <p><strong>Request details</strong><br />Cost split into input and output, latency and time to first token, and the tokens broken down into uncached, cache reads, cache writes and reasoning. Shows the trace the call belongs to and the raw request and response.</p>
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/traces.png"><img alt="The traces list" src="docs/public/images/dashboard/traces.png" /></a>
      <p><strong>Traces</strong><br />The AI calls of one HTTP request, queued job or Artisan command, grouped together. See where each trace came from, which models it used, how long it took and what it cost.</p>
    </td>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/trace-detail.png"><img alt="A trace timeline" src="docs/public/images/dashboard/trace-detail.png" /></a>
      <p><strong>Trace timeline</strong><br />Every call of a trace on one timeline, to the millisecond, nested under the spans you open with <code>Spectra::span()</code>. The side panel shows the cost and tokens per model.</p>
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/conversations.png"><img alt="The conversations list" src="docs/public/images/dashboard/conversations.png" /></a>
      <p><strong>Conversations</strong><br />Multi-turn chats, one row per conversation, with their turns, models, user, cost and last activity.</p>
    </td>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/conversation-detail.png"><img alt="A conversation transcript" src="docs/public/images/dashboard/conversation-detail.png" /></a>
      <p><strong>Conversation transcript</strong><br />Read a conversation turn by turn: the user's message, the model's reply, the calls each turn made, what the turn cost and what the conversation has cost so far.</p>
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/costs.png"><img alt="The costs screen" src="docs/public/images/dashboard/costs.png" /></a>
      <p><strong>Costs</strong><br />Where the money goes: daily spend, the share of each provider or model type, cost per model with its usage in tokens, images, videos or characters, and the users who spend the most.</p>
    </td>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/trackables.png"><img alt="The trackables list" src="docs/public/images/dashboard/trackables.png" /></a>
      <p><strong>Trackables</strong><br />Usage and spend per user, team or any Eloquent model you attribute requests to: requests, tokens, media, cost, latency and their most used model.</p>
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/trackable-detail.png"><img alt="One user's usage" src="docs/public/images/dashboard/trackable-detail.png" /></a>
      <p><strong>Trackable details</strong><br />One user's spend over time, the providers and models they use, and their latest requests.</p>
    </td>
    <td width="50%" valign="top">
      <a href="docs/public/images/dashboard/overview-dark.png"><img alt="The overview in dark mode" src="docs/public/images/dashboard/overview-dark.png" /></a>
      <p><strong>Dark mode</strong><br />The dashboard follows your system theme, and the toggle in the top bar switches it.</p>
    </td>
  </tr>
</table>

## Official Documentation

Official documentation can be found on [spectraphp.com](https://spectraphp.com).

## Upgrading to 2.0

Spectra 2.0 tracks streamed responses automatically, bills prompt-cache reads and writes and reasoning tokens for every provider, and updates every pricing catalog. Most apps only need to run one new migration. See the [upgrade guide](UPGRADE.md) and the [changelog](CHANGELOG.md).

## Code of Conduct

While this project is not affiliated with Laravel, we follow the Laravel [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct). We expect all contributors to follow these guidelines.

## Author

This library is created by [Ahmad Mayahi](https://mayahi.net) with contributions from the [Open Source Community](https://github.com/spectra-php/laravel-spectra/graphs/contributors).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
