## Unreleased

* Drop support for Monolog 1

## 3.10.0 (2023-11-06)

* Add configuration support for SamplingHandler

## 3.9.0 (2023-11-06)

* Add support for the `WithMonologChannel` attribute of Monolog 3.5.0 to autoconfigure the `monolog.logger` tag
* Add support for Symfony 7
* Remove support for Symfony 4
* Mark classes as internal when relevant
* Add support for env placeholders in the `level` option of handlers

## 3.8.0 (2022-05-10)

* Deprecated ambiguous `elasticsearch` type, use `elastica` instead
* Added support for Monolog 3.0 (requires symfony/monolog-bridge 6.1)
* Added support for `AsMonologProcessor` to autoconfigure processors
* Added support for `FallbackGroupHandler`
* Added support for `ElasticsearchHandler` as `elastic_search` type
* Added support for `ElasticaHandler` as `elastica` type
* Added support for `TelegramBotHandler` as `telegram`
* Added `fill_extra_context` flag for `sentry` handlers
* Added support for configuring PsrLogMessageProcessor (`date_format` and `remove_used_context_fields`)
* Fixed issue on Windows + PHP 8, workaround for https://github.com/php/php-src/issues/8315
* Fixed MongoDBHandler support when no client id is provided

## 3.7.1 (2021-11-05)

* Indicate compatibility with Symfony 6

## 3.7.0 (2021-03-31)

* Use `ActivationStrategy` instead of `actionLevel` when available
* Register resettable processors (`ResettableInterface`) for autoconfiguration (tag: `kernel.reset`)
* Drop support for Symfony 3.4
* Drop support for PHP < 7.1
* Fix call to undefined method pushProcessor on handler that does not implement ProcessableHandlerInterface
* Use "use_locking" option with rotating file handler
* Add ability to specify custom Sentry hub service

## 3.6.0 (2020-10-06)

* Added support for Symfony Mailer
* Added support for setting log levels from parameters or environment variables

## 3.5.0 (2019-11-13)

* Added support for Monolog 2.0
* Added `sentry` type to use sentry 2.0 client
* Added `insightops` handler
* Added possibility for auto-wire monolog channel according to the type-hinted aliases, introduced in the Symfony 4.2

## 3.4.0 (2019-06-20)

* Deprecate "excluded_404s" option
* Flush loggers on `kernel.reset`
* Register processors (`ProcessorInterface`) for autoconfiguration (tag: `monolog.processor`)
* Expose configuration for the `ConsoleHandler`
* Fixed psr-3 processing being applied to all handlers, only leaf ones are now processing
* Fixed regression when `app` channel is defined explicitly
* Fixed handlers marked as nested not being ignored properly from the stack
* Added support for Redis configuration
* Drop support for Symfony <3

## 3.3.1 (2018-11-04)

* Fixed compatiblity with Symfony 4.2

## 3.3.0 (2018-06-04)

* Fixed the autowiring of the channel logger in autoconfigured services
* Added timeouts to the pushover, hipchat, slack handlers
* Dropped support for PHP 5.3, 5.4, and HHVM
* Added configuration for HttpCodeActivationStrategy
* Deprecated "excluded_404s" option for Symfony >= 3.4

## 3.2.0 (2018-03-05)

* Removed randomness from the container build
* Fixed support for the `monolog.logger` tag specifying a channel in combination with Symfony 3.4+ autowiring
* Fixed visibility of channels configured explicitly in the bundle config (they are now public in Symfony 4 too)
* Fixed invalid service definitions

## 3.1.2 (2017-11-06)

* fix invalid usage of count()

## 3.1.1 (2017-09-26)

* added support for Symfony 4

## 3.1.0 (2017-03-26)

* Added support for server_log handler
* Allow configuring VERBOSITY_QUIET in console handlers
* Fixed autowiring
* Fixed slackbot handler not escaping channel names properly
* Fixed slackbot handler requiring `slack_team` instead of `team` to be configured

## 3.0.3 (2017-01-10)

* Fixed deprecation notices when using Symfony 3.3+ and PHP7+

## 3.0.2 (2017-01-03)

* Revert disabling DebugHandler in CLI environments
* Update configuration for slack handlers for Monolog 1.22 new options
* Revert the removal of the DebugHandlerPass (needed for Symfony <3.2)

## 3.0.1 (2016-11-15)

* Removed obsolete code (DebugHandlerPass)

## 3.0.0 (2016-11-06)

* Removed class parameters for the container configuration
* Bumped minimum version of supported Symfony version to 2.7
* Removed `NotFoundActivationStrategy` (the bundle now uses the class from MonologBridge)
