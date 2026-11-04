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
    <img alt="The Spectra dashboard overview: requests, spend, tokens and latency, media usage, daily volume and a breakdown by provider" src="docs/public/images/dashboard/overview.png" width="100%" />
  </picture>
</p>

<table>
  <tr>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/requests.png" target="_blank" rel="noopener"><img alt="The requests list" src="docs/public/images/dashboard/thumbnails/requests.png" width="100%" /></a>
      <p align="center"><strong>Requests</strong><br />Every AI call with its usage, cost and latency</p>
    </td>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/request-detail.png" target="_blank" rel="noopener"><img alt="A single request" src="docs/public/images/dashboard/thumbnails/request-detail.png" width="100%" /></a>
      <p align="center"><strong>Request details</strong><br />Token breakdown, cache hits, cost split and payload</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/traces.png" target="_blank" rel="noopener"><img alt="The traces list" src="docs/public/images/dashboard/thumbnails/traces.png" width="100%" /></a>
      <p align="center"><strong>Traces</strong><br />The AI calls of one request, job or command</p>
    </td>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/trace-detail.png" target="_blank" rel="noopener"><img alt="A trace timeline" src="docs/public/images/dashboard/thumbnails/trace-detail.png" width="100%" /></a>
      <p align="center"><strong>Trace timeline</strong><br />Every call and span on one timeline</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/conversations.png" target="_blank" rel="noopener"><img alt="The conversations list" src="docs/public/images/dashboard/thumbnails/conversations.png" width="100%" /></a>
      <p align="center"><strong>Conversations</strong><br />Multi-turn chats with their turns and cost</p>
    </td>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/conversation-detail.png" target="_blank" rel="noopener"><img alt="A conversation transcript" src="docs/public/images/dashboard/thumbnails/conversation-detail.png" width="100%" /></a>
      <p align="center"><strong>Conversation transcript</strong><br />Each turn with its messages and running cost</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/costs.png" target="_blank" rel="noopener"><img alt="The costs screen" src="docs/public/images/dashboard/thumbnails/costs.png" width="100%" /></a>
      <p align="center"><strong>Costs</strong><br />Spend over time, by provider, model and user</p>
    </td>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/trackables.png" target="_blank" rel="noopener"><img alt="The trackables list" src="docs/public/images/dashboard/thumbnails/trackables.png" width="100%" /></a>
      <p align="center"><strong>Trackables</strong><br />Usage and spend per user, team or any model</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/trackable-detail.png" target="_blank" rel="noopener"><img alt="One user's usage" src="docs/public/images/dashboard/thumbnails/trackable-detail.png" width="100%" /></a>
      <p align="center"><strong>Trackable details</strong><br />One user's spend, models and latest requests</p>
    </td>
    <td width="50%">
      <a href="https://raw.githubusercontent.com/spectra-php/laravel-spectra/refs/heads/main/docs/public/images/dashboard/overview-dark.png" target="_blank" rel="noopener"><img alt="The overview in dark mode" src="docs/public/images/dashboard/thumbnails/overview-dark.png" width="100%" /></a>
      <p align="center"><strong>Dark mode</strong><br />Follows your system theme, or toggle it</p>
    </td>
  </tr>
</table>

<p align="center">
  <a href="https://mayahi.net/services"><img alt="Hire me — I'm available for consulting and development work" src="art/hire-me.svg" width="100%" /></a>
</p>

## Official Documentation

Official documentation can be found on [spectraphp.com](https://spectraphp.com).

## Upgrading to 3.0

Spectra 3.0 adds traces, spans and conversations, and fixes several billing and budget bugs. Most apps only need to run `php artisan spectra:install` once. Check the guide if you use budgets, remove built-in providers in your published config, or touch `RequestContext` in your code. See the [upgrade guide](UPGRADE.md) and the [changelog](CHANGELOG.md).

## Code of Conduct

While this project is not affiliated with Laravel, we follow the Laravel [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct). We expect all contributors to follow these guidelines.

## Author

This library is created by [Ahmad Mayahi](https://mayahi.net) with contributions from the [Open Source Community](https://github.com/spectra-php/laravel-spectra/graphs/contributors).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
