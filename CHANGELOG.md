## Unreleased

* Close handlers through `kernel.reset` on Symfony 8.2 and keep the shutdown hook on older versions

## 4.1.0 (2026-09-12)

* Allow the `WithMonologChannel` attribute to be used on a constructor argument to bind a specific channel to it
* Add a `timezone` config option to set the timezone used for the timestamp of log records
* Add `GoogleCloudLoggingFormatter` as `monolog.formatter.google` to services
* Add `expand_newlines` option to the `error_log` handler
* Add `parameters` option to the `native_mailer` handler to pass additional parameters to `mail()` (e.g. `-f sender@example.com`)
* Add `rfc` option to the `syslogudp` handler to select the syslog RFC format (RFC3164, RFC5424 or RFC5424e)
* Fix `ServiceNotFoundException` when a disabled handler is a member of a `group`, `whatfailuregroup` or `fallbackgroup` handler
* Allow using `OutputInterface::VERBOSITY_*` constants (or numeric indexes) as keys of the `console` handler `verbosity_levels` option
* Close all handlers on kernel shutdown (only handlers already instantiated are closed, so unused handlers are not instantiated just to be closed)

## 4.0.2 (2026-04-02)

* Fix `TaggedIteratorArgument` deprecation warning when using `symfony/dependency-injection` 8.1
* Import `Extension` from the `DependencyInjection` component rather than `HttpKernel`
* Add missing target to named autowiring alias
* Add info to the `monolog.channels` config

# 4.0.1 (2025-12-09)

* Fix `rollbar` handler to use `RollbarLogger`
* Fix `monolog.processor` attributes to use consecutive keys

## 4.0.0 (2025-11-27)

* Add support for Symfony 8.0
* Drop support for PHP < 8.2
* Drop support for Symfony < 7.3
* Drop support for Monolog < 3.5
* Remove abstract `monolog.activation_strategy.not_found` and `monolog.handler.fingers_crossed.error_level_activation_strategy` service definitions
* Remove `excluded_404s` option, use `excluded_http_codes` instead
* Remove `console_formater_options` option, use `console_formatter_options` instead
* Remove `elasticsearch` type, use `elastica` or `elastic_search` instead
* Remove `mongo` type, use `mongodb` instead
* Remove `sentry` and `raven` types, use a `service` type with [`sentry/sentry-symfony`](https://docs.sentry.io/platforms/php/guides/symfony/logs/) instead
* Remove `DebugHandlerPass`
* Remove support for the `DebugHandler`

## 3.11.3 unreleased

* Fix `ServiceNotFoundException` when a disabled handler is a member of a `group`, `whatfailuregroup` or `fallbackgroup` handler

## 3.11.2 (2026-04-02)

* Add missing target to named autowiring alias

## 3.11.1 (2025-12-09)

* Fix `rollbar` handler to use `RollbarLogger` with Monolog 2+
* Fix `monolog.processor` attributes to use consecutive keys

## 3.11.0 (2025-11-27)

* Reorganize files to match the "Reusable Bundles" structure
* Migrate services configuration to PHP
* Add `console.interactive_only` flag
* Add `slack.exclude_fields` and `slackwebhook.exclude_fields` configuration
* Add a processor to all loggers only when tags do not specify a channel or handler
* Deprecate abstract `monolog.activation_strategy.not_found` and `monolog.handler.fingers_crossed.error_level_activation_strategy` service definitions
* Drop support for PHP < 8.1
* Drop support for Symfony < 6.4
* Add TelegramBotHandler `topic` support
* Deprecate `sentry` and `raven` handler, use a `service` handler with [`sentry/sentry-symfony`](https://docs.sentry.io/platforms/php/guides/symfony/logs/) instead
* Add configuration for Gelf encoders
* Fix `host` configuration for `elastic_search` handler
* Add `hosts` configuration for `elastica` handler
* Add `enabled` option to `handlers` configuration
* Add `priority` field to `processor` tag
* Add `mongodb` handler and deprecate `mongo`
* Add `monolog.formatter.syslog` service definition to format RFC5424-compliant messages

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

* Fixed compatibility with Symfony 4.2

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
