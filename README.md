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


Spectra is an AI observability package for Laravel, built to track AI requests, measure usage, calculate costs across different pricing units, enforce budgets, and inspect AI operations through a unified dashboard and API. This lets you focus on shipping reliable AI features without getting buried in provider-specific operational complexity.

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/public/images/dashboard/overview-dark.png" />
    <img alt="The Spectra dashboard overview: requests, spend, tokens and latency, alerts, media usage, daily volume, a breakdown by provider, performance per model and scores" src="docs/public/images/dashboard/overview.png" width="100%" />
  </picture>
</p>

<table>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/requests.png?raw=true" target="_blank" rel="noopener"><img alt="The requests list" src="docs/public/images/dashboard/thumbnails/requests.png" width="100%" /></a>
      <p align="center"><strong>Requests</strong><br />Usage, cost and latency per call</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/request-detail.png?raw=true" target="_blank" rel="noopener"><img alt="A single request" src="docs/public/images/dashboard/thumbnails/request-detail.png" width="100%" /></a>
      <p align="center"><strong>Request details</strong><br />Trace, tokens, cache hits and payload</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/traces.png?raw=true" target="_blank" rel="noopener"><img alt="The traces list" src="docs/public/images/dashboard/thumbnails/traces.png" width="100%" /></a>
      <p align="center"><strong>Traces</strong><br />AI calls grouped by request or job</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/trace-detail.png?raw=true" target="_blank" rel="noopener"><img alt="A trace timeline" src="docs/public/images/dashboard/thumbnails/trace-detail.png" width="100%" /></a>
      <p align="center"><strong>Trace timeline</strong><br />Calls, spans and tools in order</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/conversations.png?raw=true" target="_blank" rel="noopener"><img alt="The conversations list" src="docs/public/images/dashboard/thumbnails/conversations.png" width="100%" /></a>
      <p align="center"><strong>Conversations</strong><br />Multi-turn chats and their cost</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/conversation-detail.png?raw=true" target="_blank" rel="noopener"><img alt="A conversation transcript" src="docs/public/images/dashboard/thumbnails/conversation-detail.png" width="100%" /></a>
      <p align="center"><strong>Conversation transcript</strong><br />Each turn and its running cost</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/errors.png?raw=true" target="_blank" rel="noopener"><img alt="The errors screen" src="docs/public/images/dashboard/thumbnails/errors.png" width="100%" /></a>
      <p align="center"><strong>Errors</strong><br />Failed calls grouped by cause</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/prompts.png?raw=true" target="_blank" rel="noopener"><img alt="The prompts screen" src="docs/public/images/dashboard/thumbnails/prompts.png" width="100%" /></a>
      <p align="center"><strong>Prompts</strong><br />Cost, latency and scores per version</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/eval-run.png?raw=true" target="_blank" rel="noopener"><img alt="An evaluation run" src="docs/public/images/dashboard/thumbnails/eval-run.png" width="100%" /></a>
      <p align="center"><strong>Evals</strong><br />Datasets, runs and comparisons</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/review-queue.png?raw=true" target="_blank" rel="noopener"><img alt="A review queue" src="docs/public/images/dashboard/thumbnails/review-queue.png" width="100%" /></a>
      <p align="center"><strong>Review</strong><br />Label traces by hand</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/costs.png?raw=true" target="_blank" rel="noopener"><img alt="The costs screen" src="docs/public/images/dashboard/thumbnails/costs.png" width="100%" /></a>
      <p align="center"><strong>Costs</strong><br />Spend by provider, model and user</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/trackables.png?raw=true" target="_blank" rel="noopener"><img alt="The trackables list" src="docs/public/images/dashboard/thumbnails/trackables.png" width="100%" /></a>
      <p align="center"><strong>Trackables</strong><br />Spend per user or team</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="docs/public/images/dashboard/trackable-detail.png?raw=true" target="_blank" rel="noopener"><img alt="One user's usage" src="docs/public/images/dashboard/thumbnails/trackable-detail.png" width="100%" /></a>
      <p align="center"><strong>Trackable details</strong><br />One user's spend and requests</p>
    </td>
    <td width="50%">
      <a href="docs/public/images/dashboard/overview-dark.png?raw=true" target="_blank" rel="noopener"><img alt="The overview in dark mode" src="docs/public/images/dashboard/thumbnails/overview-dark.png" width="100%" /></a>
      <p align="center"><strong>Dark mode</strong><br />Follows your system theme</p>
    </td>
  </tr>
</table>

<p align="center">
  <a href="https://mayahi.net/services"><img alt="Hire me — I'm available for consulting and development work" src="art/hire-me.svg" width="100%" /></a>
</p>

## Official Documentation

Official documentation can be found on [spectraphp.com](https://spectraphp.com).

## Upgrading to 4.0

Spectra 4.0 adds scores, alerts, redaction, payload sampling, prompt versions, tool spans, error grouping, review queues, evaluations and a maintenance screen. Most apps only need to run `php artisan spectra:install` once. Check the guide if `spectra:install` runs in a deploy script, if others use your dashboard in production, or if your code reads `RequestContext::$id`. See the [upgrade guide](UPGRADE.md) and the [changelog](CHANGELOG.md).

## Code of Conduct

While this project is not affiliated with Laravel, we follow the Laravel [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct). We expect all contributors to follow these guidelines.

## Author

This library is created by [Ahmad Mayahi](https://mayahi.net) with contributions from the [Open Source Community](https://github.com/spectra-php/laravel-spectra/graphs/contributors).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
