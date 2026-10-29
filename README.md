<p align="center">
  <img alt="Spectra" src="art/logo.svg" width="500px" />
</p>

<div align="center">

<p align="center">
<a href="https://github.com/spectra-php/laravel-spectra/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/spectra-php/laravel-spectra/tests.yml?branch=main&label=tests&style=flat" alt="Tests"></a>
<a href="https://github.com/spectra-php/laravel-spectra/actions/workflows/phpstan.yml"><img src="https://img.shields.io/github/actions/workflow/status/spectra-php/laravel-spectra/phpstan.yml?branch=main&label=phpstan&style=flat" alt="PHPStan"></a>
<a href="https://github.com/spectra-php/laravel-spectra/actions/workflows/pint.yml"><img src="https://img.shields.io/github/actions/workflow/status/spectra-php/laravel-spectra/pint.yml?branch=main&label=code%20style&style=flat" alt="Code Style"></a>
<a href="https://packagist.org/packages/spectra-php/laravel-spectra"><img src="https://img.shields.io/packagist/v/spectra-php/laravel-spectra?style=flat" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/spectra-php/laravel-spectra"><img src="https://img.shields.io/packagist/dt/spectra-php/laravel-spectra?style=flat" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/spectra-php/laravel-spectra"><img src="https://img.shields.io/packagist/dependency-v/spectra-php/laravel-spectra/php?style=flat" alt="PHP Version"></a>
<a href="https://laravel.com"><img src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat&logo=laravel&logoColor=white" alt="Laravel Version"></a>
<a href="https://spectraphp.com"><img src="https://img.shields.io/badge/docs-spectraphp.com-6366F1?style=flat" alt="Documentation"></a>
<a href="LICENSE"><img src="https://img.shields.io/packagist/l/spectra-php/laravel-spectra?style=flat" alt="License"></a>
</p>

</div>


Spectra is a comprehensive Laravel package for AI observability. It helps you track AI requests, measure usage, calculate costs across different pricing units, enforce budgets, and inspect AI operations through a unified dashboard and API. This lets you focus on shipping reliable AI features without getting buried in provider-specific operational complexity.

<p align="center">
  <img alt="Spectra dashboard" src="docs/public/images/dashboard/home.png" width="100%" />
</p>

<p align="center">
  <a href="https://mayahi.net/services"><img alt="Hire me — I'm available for consulting and development work" src="art/hire-me.svg" width="100%" /></a>
</p>

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
