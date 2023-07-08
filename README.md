# FastyBird IoT Panasonic Viera connector

[![Build Status](https://badgen.net/github/checks/FastyBird/viera-connector/main?cache=300&style=flast-square)](https://github.com/FastyBird/viera-connector/actions)
[![Licence](https://badgen.net/github/license/FastyBird/viera-connector?cache=300&style=flast-square)](https://github.com/FastyBird/viera-connector/blob/main/LICENSE.md)
[![Code coverage](https://badgen.net/coveralls/c/github/FastyBird/viera-connector?cache=300&style=flast-square)](https://coveralls.io/r/FastyBird/viera-connector)
[![Mutation testing](https://img.shields.io/endpoint?style=flat-square&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FFastyBird%2Fviera-connector%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/FastyBird/viera-connector/main)

![PHP](https://badgen.net/packagist/php/FastyBird/viera-connector?cache=300&style=flast-square)
[![Latest stable](https://badgen.net/packagist/v/FastyBird/viera-connector/latest?cache=300&style=flast-square)](https://packagist.org/packages/FastyBird/viera-connector)
[![Downloads total](https://badgen.net/packagist/dt/FastyBird/viera-connector?cache=300&style=flast-square)](https://packagist.org/packages/FastyBird/viera-connector)
[![PHPStan](https://img.shields.io/badge/PHPStan-enabled-brightgreen.svg?style=flat-square)](https://github.com/phpstan/phpstan)

***

## What is Panasonic Viera connector?

Panasonic Viera connector is extension for [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) ecosystem
which is integrating [Panasonic Viera](https://www.panasonic.com) televisions.

Panasonic Viera Connector is a distributed extension that is developed in [PHP](https://www.php.net), built on the [Nette](https://nette.org) and [Symfony](https://symfony.com) frameworks,
and is licensed under [Apache2](http://www.apache.org/licenses/LICENSE-2.0).

### Features:

- Full support for 2018 and later models
- Support for televisions which need PIN authentication
- Automated device discovery feature, which automatically detects and adds Panasonic Viera televisions to the FastyBird ecosystem
- Panasonic Viera Connector management for the [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) [devices module](https://github.com/FastyBird/devices-module), allowing users to easily manage and monitor Panasonic Viera devices
- Advanced device management features, such as controlling power status, measuring energy consumption, and reading sensor data
- [{JSON:API}](https://jsonapi.org/) schemas for full API access, providing a standardized and consistent way for developers to access and manipulate Panasonic Viera device data
- Regular updates with new features and bug fixes, ensuring that the Panasonic Viera Connector is always up-to-date and reliable.


## Requirements

Panasonic Viera connector is tested against PHP 8.1 and require installed [Process Control](https://www.php.net/manual/en/book.pcntl.php),
[OpenSSL](https://www.php.net/manual/en/book.openssl.php), [SimpleXML](https://www.php.net/manual/en/book.simplexml.php) and [Iconv](https://www.php.net/manual/en/book.iconv.php)
PHP extensions.

## Installation

### Manual installation

The best way to install **fastybird/viera-connector** is using [Composer](http://getcomposer.org/):

```sh
composer require fastybird/viera-connector
```

### Marketplace installation [WIP]

You could install this connector in your [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things)
application under marketplace section.

## Documentation

Learn how to connect Panasonic Viera televisions and manage them with [FastyBird](https://www.fastybird.com) [IoT](https://en.wikipedia.org/wiki/Internet_of_things) system
in [documentation](https://github.com/FastyBird/viera-connector/wiki).

## Feedback

Use the [issue tracker](https://github.com/FastyBird/fastybird/issues) for bugs
or [mail](mailto:code@fastybird.com) or [Tweet](https://twitter.com/fastybird) us for any idea that can improve the
project.

Thank you for testing, reporting and contributing.

## Changelog

For release info check [release page](https://github.com/FastyBird/fastybird/releases).

## Contribute

The sources of this package are contained in the [FastyBird monorepo](https://github.com/FastyBird/fastybird). We welcome contributions for this package on [FastyBird/fastybird](https://github.com/FastyBird/).

## Maintainers

<table>
	<tbody>
		<tr>
			<td align="center">
				<a href="https://github.com/akadlec">
					<img alt="akadlec" width="80" height="80" src="https://avatars3.githubusercontent.com/u/1866672?s=460&amp;v=4" />
				</a>
				<br>
				<a href="https://github.com/akadlec">Adam Kadlec</a>
			</td>
		</tr>
	</tbody>
</table>

***
Homepage [https://www.fastybird.com](https://www.fastybird.com) and
repository [https://github.com/fastybird/viera-connector](https://github.com/fastybird/viera-connector).
