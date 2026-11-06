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
    <source media="(prefers-color-scheme: dark)" srcset="docs/public/images/readme/overview-dark.png" />
    <img alt="The Spectra dashboard overview: requests, spend, tokens and latency, the alerts that are firing, and media usage" src="docs/public/images/readme/overview.png" width="100%" />
  </picture>
</p>

<p align="center">
  <a href="https://spectraphp.com/dashboard"><img alt="A tour of the dashboard, one screen at a time: requests, request details, traces, the trace timeline, conversations, a conversation transcript, errors, prompts, evals, review, costs, trackables, one trackable's details, and dark mode" src="docs/public/images/readme/tour.gif" width="100%" /></a>
  <br />
  <sub>Every screen of the dashboard, three seconds each. <a href="https://spectraphp.com/dashboard">See them at full size in the docs.</a></sub>
</p>

<p align="center">
  <a href="https://mayahi.net/services"><img alt="Hire me — I'm available for consulting and development work" src="art/hire-me.svg" width="100%" /></a>
</p>

## Official Documentation

Official documentation can be found on [spectraphp.com](https://spectraphp.com).

## Upgrading to 4.0

Spectra 4.0 adds scores, alerts, redaction, payload sampling, prompt versions, tool spans, error grouping, review queues, evaluations and a maintenance screen. Most apps only need to run `php artisan spectra:install` once. Check the guide if `spectra:install` runs in a deploy script, if others use your dashboard in production, if your code reads `RequestContext::$id`, or if you wrote a pricing class. See the [upgrade guide](UPGRADE.md) and the [changelog](CHANGELOG.md).

## Code of Conduct

While this project is not affiliated with Laravel, we follow the Laravel [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct). We expect all contributors to follow these guidelines.

## Author

This library is created by [Ahmad Mayahi](https://mayahi.net) with contributions from the [Open Source Community](https://github.com/spectra-php/laravel-spectra/graphs/contributors).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
