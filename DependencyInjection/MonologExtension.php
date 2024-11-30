<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\DependencyInjection;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\Attribute\WithMonologChannel;
use Monolog\Handler\FingersCrossed\ErrorLevelActivationStrategy;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\ResettableInterface;
use Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy;
use Symfony\Bridge\Monolog\Logger as LegacyLogger;
use Symfony\Bridge\Monolog\Processor\SwitchUserTokenProcessor;
use Symfony\Bridge\Monolog\Processor\TokenProcessor;
use Symfony\Bridge\Monolog\Processor\WebProcessor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\HttpKernel\Log\DebugLoggerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * MonologExtension is an extension for the Monolog library.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Christophe Coevoet <stof@notk.org>
 *
 * @final since 3.9.0
 */
class MonologExtension extends Extension
{
    private $nestedHandlers = [];

    private $swiftMailerHandlers = [];

    /**
     * Loads the Monolog configuration.
     *
     * @param array            $configs   An array of configuration settings
     * @param ContainerBuilder $container A ContainerBuilder instance
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        if (isset($config['handlers'])) {
            $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
            $loader->load('monolog.xml');

            if (!class_exists(DebugLoggerConfigurator::class)) {
                $container->getDefinition('monolog.logger_prototype')->setClass(LegacyLogger::class);
            }

            $container->setParameter('monolog.use_microseconds', $config['use_microseconds']);

            $handlers = [];

            foreach ($config['handlers'] as $name => $handler) {
                $handlers[$handler['priority']][] = [
                    'id' => $this->buildHandler($container, $name, $handler),
                    'channels' => empty($handler['channels']) ? null : $handler['channels'],
                ];
            }

            $container->setParameter(
                'monolog.swift_mailer.handlers',
                $this->swiftMailerHandlers
            );

            ksort($handlers);
            $sortedHandlers = [];
            foreach ($handlers as $priorityHandlers) {
                foreach (array_reverse($priorityHandlers) as $handler) {
                    $sortedHandlers[] = $handler;
                }
            }

            $handlersToChannels = [];
            foreach ($sortedHandlers as $handler) {
                if (!\in_array($handler['id'], $this->nestedHandlers)) {
                    $handlersToChannels[$handler['id']] = $handler['channels'];
                }
            }
            $container->setParameter('monolog.handlers_to_channels', $handlersToChannels);
        }

        $container->setParameter('monolog.additional_channels', $config['channels'] ?? []);

        if (interface_exists(ProcessorInterface::class)) {
            $container->registerForAutoconfiguration(ProcessorInterface::class)
                ->addTag('monolog.processor');
        } else {
            $container->registerForAutoconfiguration(WebProcessor::class)
                ->addTag('monolog.processor');
        }
        if (interface_exists(ResettableInterface::class)) {
            $container->registerForAutoconfiguration(ResettableInterface::class)
                ->addTag('kernel.reset', ['method' => 'reset']);
        }
        $container->registerForAutoconfiguration(TokenProcessor::class)
            ->addTag('monolog.processor');
        if (interface_exists(HttpClientInterface::class)) {
            $handlerAutoconfiguration = $container->registerForAutoconfiguration(HandlerInterface::class);
            $handlerAutoconfiguration->setBindings($handlerAutoconfiguration->getBindings() + [
                HttpClientInterface::class => new BoundArgument(new Reference('monolog.http_client'), false),
            ]);
        }

        if (80000 <= \PHP_VERSION_ID) {
            $container->registerAttributeForAutoconfiguration(AsMonologProcessor::class, static function (ChildDefinition $definition, AsMonologProcessor $attribute, \Reflector $reflector): void {
                $tagAttributes = get_object_vars($attribute);
                if ($reflector instanceof \ReflectionMethod) {
                    if (isset($tagAttributes['method'])) {
                        throw new \LogicException(\sprintf('AsMonologProcessor attribute cannot declare a method on "%s::%s()".', $reflector->class, $reflector->name));
                    }

                    $tagAttributes['method'] = $reflector->getName();
                }

                $definition->addTag('monolog.processor', $tagAttributes);
            });
            $container->registerAttributeForAutoconfiguration(WithMonologChannel::class, static function (ChildDefinition $definition, WithMonologChannel $attribute): void {
                $definition->addTag('monolog.logger', ['channel' => $attribute->channel]);
            });
        }
    }

    /**
     * Returns the base path for the XSD files.
     *
     * @return string The XSD base path
     */
    public function getXsdValidationBasePath()
    {
        return __DIR__.'/../Resources/config/schema';
    }

    public function getNamespace()
    {
        return 'http://symfony.com/schema/dic/monolog';
    }

    private function buildHandler(ContainerBuilder $container, $name, array $handler)
    {
        $handlerId = $this->getHandlerId($name);
        if ('service' === $handler['type']) {
            $container->setAlias($handlerId, $handler['id']);

            if (!empty($handler['nested']) && true === $handler['nested']) {
                $this->markNestedHandler($handlerId);
            }

            return $handlerId;
        }

        $handlerClass = $this->getHandlerClassByType($handler['type']);
        $definition = new Definition($handlerClass);

        if ($handler['include_stacktraces']) {
            $definition->setConfigurator(['Symfony\\Bundle\\MonologBundle\\MonologBundle', 'includeStacktraces']);
        }

        if (null === $handler['process_psr_3_messages']['enabled']) {
            $handler['process_psr_3_messages']['enabled'] = !isset($handler['handler']) && !$handler['members'];
        }

        if ($handler['process_psr_3_messages']['enabled'] && method_exists($handlerClass, 'pushProcessor')) {
            $processorId = $this->buildPsrLogMessageProcessor($container, $handler['process_psr_3_messages']);
            $definition->addMethodCall('pushProcessor', [new Reference($processorId)]);
        }

        switch ($handler['type']) {
            case 'stream':
                $definition->setArguments([
                    $handler['path'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['file_permission'],
                    $handler['use_locking'],
                ]);
                break;

            case 'console':
                $definition->setArguments([
                    null,
                    $handler['bubble'],
                    $handler['verbosity_levels'] ?? [],
                    $handler['console_formatter_options'],
                ]);
                $definition->addTag('kernel.event_subscriber');
                break;

            case 'chromephp':
            case 'firephp':
                $definition->setArguments([
                    $handler['level'],
                    $handler['bubble'],
                ]);
                $definition->addTag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onKernelResponse']);
                break;

            case 'gelf':
                if (isset($handler['publisher']['id'])) {
                    $publisher = new Reference($handler['publisher']['id']);
                } elseif (class_exists('Gelf\Transport\UdpTransport')) {
                    $transport = new Definition("Gelf\Transport\UdpTransport", [
                        $handler['publisher']['hostname'],
                        $handler['publisher']['port'],
                        $handler['publisher']['chunk_size'],
                    ]);
                    $transport->setPublic(false);

                    $publisher = new Definition('Gelf\Publisher', []);
                    $publisher->addMethodCall('addTransport', [$transport]);
                    $publisher->setPublic(false);
                } elseif (class_exists('Gelf\MessagePublisher')) {
                    $publisher = new Definition('Gelf\MessagePublisher', [
                        $handler['publisher']['hostname'],
                        $handler['publisher']['port'],
                        $handler['publisher']['chunk_size'],
                    ]);

                    $publisher->setPublic(false);
                } else {
                    throw new \RuntimeException('The gelf handler requires the graylog2/gelf-php package to be installed');
                }

                $definition->setArguments([
                    $publisher,
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'mongo':
                if (isset($handler['mongo']['id'])) {
                    $client = new Reference($handler['mongo']['id']);
                } else {
                    $server = 'mongodb://';

                    if (isset($handler['mongo']['user'])) {
                        $server .= $handler['mongo']['user'].':'.$handler['mongo']['pass'].'@';
                    }

                    $server .= $handler['mongo']['host'].':'.$handler['mongo']['port'];

                    $client = new Definition('MongoDB\Client', [
                        $server,
                    ]);

                    $client->setPublic(false);
                }

                $definition->setArguments([
                    $client,
                    $handler['mongo']['database'],
                    $handler['mongo']['collection'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'elasticsearch':
                @trigger_error('The "elasticsearch" handler type is deprecated in MonologBundle since version 3.8.0, use the "elastica" type instead, or switch to the official Elastic client using the "elastic_search" type.', \E_USER_DEPRECATED);
                // no break

            case 'elastica':
            case 'elastic_search':
                if (isset($handler['elasticsearch']['id'])) {
                    $client = new Reference($handler['elasticsearch']['id']);
                } else {
                    if ('elastic_search' === $handler['type']) {
                        // v8 has a new Elastic\ prefix
                        $client = new Definition(class_exists('Elastic\Elasticsearch\Client') ? 'Elastic\Elasticsearch\Client' : 'Elasticsearch\Client');
                        $factory = class_exists('Elastic\Elasticsearch\ClientBuilder') ? 'Elastic\Elasticsearch\ClientBuilder' : 'Elasticsearch\ClientBuilder';
                        $client->setFactory([$factory, 'fromConfig']);
                        $clientArguments = [
                            'host' => $handler['elasticsearch']['host'],
                        ];

                        if (isset($handler['elasticsearch']['user'], $handler['elasticsearch']['password'])) {
                            $clientArguments['basicAuthentication'] = [$handler['elasticsearch']['user'], $handler['elasticsearch']['password']];
                        }
                    } else {
                        $client = new Definition('Elastica\Client');

                        $clientArguments = [
                            'host' => $handler['elasticsearch']['host'],
                            'port' => $handler['elasticsearch']['port'],
                            'transport' => $handler['elasticsearch']['transport'],
                        ];

                        if (isset($handler['elasticsearch']['user'], $handler['elasticsearch']['password'])) {
                            $clientArguments['headers'] = [
                                'Authorization' => 'Basic '.base64_encode($handler['elasticsearch']['user'].':'.$handler['elasticsearch']['password']),
                            ];
                        }
                    }

                    $client->setArguments([
                        $clientArguments,
                    ]);

                    $client->setPublic(false);
                }

                // elastica handler definition
                $definition->setArguments([
                    $client,
                    [
                        'index' => $handler['index'],
                        'type' => $handler['document_type'],
                        'ignore_error' => $handler['ignore_error'],
                    ],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'telegram':
                if (!class_exists('Monolog\Handler\TelegramBotHandler')) {
                    throw new \RuntimeException('The TelegramBotHandler is not available. Please update "monolog/monolog" to 2.2.0');
                }

                $definition->setArguments([
                    $handler['token'],
                    $handler['channel'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['parse_mode'],
                    $handler['disable_webpage_preview'],
                    $handler['disable_notification'],
                    $handler['split_long_messages'],
                    $handler['delay_between_messages'],
                    $handler['topic'],
                ]);
                break;

            case 'redis':
            case 'predis':
                if (isset($handler['redis']['id'])) {
                    $clientId = $handler['redis']['id'];
                } elseif ('redis' === $handler['type']) {
                    if (!class_exists(\Redis::class)) {
                        throw new \RuntimeException('The \Redis class is not available.');
                    }

                    $client = new Definition(\Redis::class);
                    $client->addMethodCall('connect', [$handler['redis']['host'], $handler['redis']['port']]);
                    $client->addMethodCall('auth', [$handler['redis']['password']]);
                    $client->addMethodCall('select', [$handler['redis']['database']]);
                    $client->setPublic(false);
                    $clientId = uniqid('monolog.redis.client.', true);
                    $container->setDefinition($clientId, $client);
                } else {
                    if (!class_exists(\Predis\Client::class)) {
                        throw new \RuntimeException('The \Predis\Client class is not available.');
                    }

                    $client = new Definition(\Predis\Client::class);
                    $client->setArguments([
                        $handler['redis']['host'],
                    ]);
                    $client->setPublic(false);

                    $clientId = uniqid('monolog.predis.client.', true);
                    $container->setDefinition($clientId, $client);
                }
                $definition->setArguments([
                    new Reference($clientId),
                    $handler['redis']['key_name'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'rotating_file':
                $definition->setArguments([
                    $handler['path'],
                    $handler['max_files'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['file_permission'],
                    $handler['use_locking'],
                ]);
                $definition->addMethodCall('setFilenameFormat', [
                    $handler['filename_format'],
                    $handler['date_format'],
                ]);
                break;

            case 'fingers_crossed':
                $nestedHandlerId = $this->getHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);

                $activation = $handler['action_level'];
                if (class_exists(SwitchUserTokenProcessor::class)) {
                    $activation = new Definition(ErrorLevelActivationStrategy::class, [$activation]);
                }

                if (isset($handler['activation_strategy'])) {
                    $activation = new Reference($handler['activation_strategy']);
                } elseif (!empty($handler['excluded_404s'])) {
                    if (class_exists(HttpCodeActivationStrategy::class)) {
                        @trigger_error('The "excluded_404s" option is deprecated in MonologBundle since version 3.4.0, you should rely on the "excluded_http_codes" option instead.', \E_USER_DEPRECATED);
                    }
                    $activationDef = new Definition('Symfony\Bridge\Monolog\Handler\FingersCrossed\NotFoundActivationStrategy', [
                        new Reference('request_stack'),
                        $handler['excluded_404s'],
                        $activation,
                    ]);
                    $container->setDefinition($handlerId.'.not_found_strategy', $activationDef);
                    $activation = new Reference($handlerId.'.not_found_strategy');
                } elseif (!empty($handler['excluded_http_codes'])) {
                    $activationDef = new Definition('Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy', [
                        new Reference('request_stack'),
                        $handler['excluded_http_codes'],
                        $activation,
                    ]);
                    $container->setDefinition($handlerId.'.http_code_strategy', $activationDef);
                    $activation = new Reference($handlerId.'.http_code_strategy');
                }

                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $activation,
                    $handler['buffer_size'],
                    $handler['bubble'],
                    $handler['stop_buffering'],
                    $handler['passthru_level'],
                ]);
                break;

            case 'filter':
                $nestedHandlerId = $this->getHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $minLevelOrList = !empty($handler['accepted_levels']) ? $handler['accepted_levels'] : $handler['min_level'];

                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $minLevelOrList,
                    $handler['max_level'],
                    $handler['bubble'],
                ]);
                break;

            case 'buffer':
                $nestedHandlerId = $this->getHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);

                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['buffer_size'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['flush_on_overflow'],
                ]);
                break;

            case 'deduplication':
                $nestedHandlerId = $this->getHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);
                $defaultStore = '%kernel.cache_dir%/monolog_dedup_'.sha1($handlerId);

                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['store'] ?? $defaultStore,
                    $handler['deduplication_level'],
                    $handler['time'],
                    $handler['bubble'],
                ]);
                break;

            case 'group':
            case 'whatfailuregroup':
            case 'fallbackgroup':
                $references = [];
                foreach ($handler['members'] as $nestedHandler) {
                    $nestedHandlerId = $this->getHandlerId($nestedHandler);
                    $this->markNestedHandler($nestedHandlerId);
                    $references[] = new Reference($nestedHandlerId);
                }

                $definition->setArguments([
                    $references,
                    $handler['bubble'],
                ]);
                break;

            case 'syslog':
                $definition->setArguments([
                    $handler['ident'],
                    $handler['facility'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['logopts'],
                ]);
                break;

            case 'syslogudp':
                $definition->setArguments([
                    $handler['host'],
                    $handler['port'],
                    $handler['facility'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if ($handler['ident']) {
                    $definition->addArgument($handler['ident']);
                }
                break;

            case 'swift_mailer':
                $mailer = $handler['mailer'] ?: 'mailer';
                if (isset($handler['email_prototype'])) {
                    if (!empty($handler['email_prototype']['method'])) {
                        $prototype = [new Reference($handler['email_prototype']['id']), $handler['email_prototype']['method']];
                    } else {
                        $prototype = new Reference($handler['email_prototype']['id']);
                    }
                } else {
                    $messageFactory = new Definition('Symfony\Bundle\MonologBundle\SwiftMailer\MessageFactory');
                    $messageFactory->setLazy(true);
                    $messageFactory->setPublic(false);
                    $messageFactory->setArguments([
                        new Reference($mailer),
                        $handler['from_email'],
                        $handler['to_email'],
                        $handler['subject'],
                        $handler['content_type'],
                    ]);

                    $messageFactoryId = \sprintf('%s.mail_message_factory', $handlerId);
                    $container->setDefinition($messageFactoryId, $messageFactory);
                    // set the prototype as a callable
                    $prototype = [new Reference($messageFactoryId), 'createMessage'];
                }
                $definition->setArguments([
                    new Reference($mailer),
                    $prototype,
                    $handler['level'],
                    $handler['bubble'],
                ]);

                $this->swiftMailerHandlers[] = $handlerId;
                $definition->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onKernelTerminate']);
                $definition->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onCliTerminate']);
                break;

            case 'native_mailer':
                $definition->setArguments([
                    $handler['to_email'],
                    $handler['subject'],
                    $handler['from_email'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if (!empty($handler['headers'])) {
                    $definition->addMethodCall('addHeader', [$handler['headers']]);
                }
                break;

            case 'symfony_mailer':
                $mailer = $handler['mailer'] ?: 'mailer.mailer';
                if (isset($handler['email_prototype'])) {
                    if (!empty($handler['email_prototype']['method'])) {
                        $prototype = [new Reference($handler['email_prototype']['id']), $handler['email_prototype']['method']];
                    } else {
                        $prototype = new Reference($handler['email_prototype']['id']);
                    }
                } else {
                    $prototype = (new Definition('Symfony\Component\Mime\Email'))
                        ->setPublic(false)
                        ->addMethodCall('from', [$handler['from_email']])
                        ->addMethodCall('to', $handler['to_email'])
                        ->addMethodCall('subject', [$handler['subject']]);
                }
                $definition->setArguments([
                    new Reference($mailer),
                    $prototype,
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'socket':
                $definition->setArguments([
                    $handler['connection_string'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if (isset($handler['timeout'])) {
                    $definition->addMethodCall('setTimeout', [$handler['timeout']]);
                }
                if (isset($handler['connection_timeout'])) {
                    $definition->addMethodCall('setConnectionTimeout', [$handler['connection_timeout']]);
                }
                if (isset($handler['persistent'])) {
                    $definition->addMethodCall('setPersistent', [$handler['persistent']]);
                }
                break;

            case 'pushover':
                $definition->setArguments([
                    $handler['token'],
                    $handler['user'],
                    $handler['title'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if (isset($handler['timeout'])) {
                    $definition->addMethodCall('setTimeout', [$handler['timeout']]);
                }
                if (isset($handler['connection_timeout'])) {
                    $definition->addMethodCall('setConnectionTimeout', [$handler['connection_timeout']]);
                }
                break;

            case 'hipchat':
                $definition->setArguments([
                    $handler['token'],
                    $handler['room'],
                    $handler['nickname'],
                    $handler['notify'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['use_ssl'],
                    $handler['message_format'],
                    !empty($handler['host']) ? $handler['host'] : 'api.hipchat.com',
                    !empty($handler['api_version']) ? $handler['api_version'] : 'v1',
                ]);
                if (isset($handler['timeout'])) {
                    $definition->addMethodCall('setTimeout', [$handler['timeout']]);
                }
                if (isset($handler['connection_timeout'])) {
                    $definition->addMethodCall('setConnectionTimeout', [$handler['connection_timeout']]);
                }
                break;

            case 'slack':
                $definition->setArguments([
                    $handler['token'],
                    $handler['channel'],
                    $handler['bot_name'],
                    $handler['use_attachment'],
                    $handler['icon_emoji'],
                    $handler['level'],
                    $handler['bubble'],
                    $handler['use_short_attachment'],
                    $handler['include_extra'],
                ]);
                if (isset($handler['timeout'])) {
                    $definition->addMethodCall('setTimeout', [$handler['timeout']]);
                }
                if (isset($handler['connection_timeout'])) {
                    $definition->addMethodCall('setConnectionTimeout', [$handler['connection_timeout']]);
                }
                break;

            case 'slackwebhook':
                $definition->setArguments([
                    $handler['webhook_url'],
                    $handler['channel'],
                    $handler['bot_name'],
                    $handler['use_attachment'],
                    $handler['icon_emoji'],
                    $handler['use_short_attachment'],
                    $handler['include_extra'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'slackbot':
                $definition->setArguments([
                    $handler['team'],
                    $handler['token'],
                    urlencode($handler['channel']),
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'cube':
                $definition->setArguments([
                    $handler['url'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'amqp':
                $definition->setArguments([
                    new Reference($handler['exchange']),
                    $handler['exchange_name'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'error_log':
                $definition->setArguments([
                    $handler['message_type'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'sentry':
                if (null !== $handler['hub_id']) {
                    $hub = new Reference($handler['hub_id']);
                } else {
                    if (null !== $handler['client_id']) {
                        $clientId = $handler['client_id'];
                    } else {
                        $options = new Definition(
                            'Sentry\\Options',
                            [['dsn' => $handler['dsn']]]
                        );

                        if (!empty($handler['environment'])) {
                            $options->addMethodCall('setEnvironment', [$handler['environment']]);
                        }

                        if (!empty($handler['release'])) {
                            $options->addMethodCall('setRelease', [$handler['release']]);
                        }

                        $builder = new Definition('Sentry\\ClientBuilder', [$options]);

                        $client = new Definition('Sentry\\Client');
                        $client->setFactory([$builder, 'getClient']);

                        $clientId = 'monolog.sentry.client.'.sha1($handler['dsn']);
                        $container->setDefinition($clientId, $client);

                        if (!$container->hasAlias('Sentry\\ClientInterface')) {
                            $container->setAlias('Sentry\\ClientInterface', $clientId);
                        }
                    }

                    $hub = new Definition(
                        'Sentry\\State\\Hub',
                        [new Reference($clientId)]
                    );
                    $container->setDefinition(\sprintf('monolog.handler.%s.hub', $name), $hub);

                    // can't set the hub to the current hub, getting into a recursion otherwise...
                    // $hub->addMethodCall('setCurrent', array($hub));
                }

                $definition->setArguments([
                    $hub,
                    $handler['level'],
                    $handler['bubble'],
                    $handler['fill_extra_context'],
                ]);
                break;

            case 'raven':
                if (null !== $handler['client_id']) {
                    $clientId = $handler['client_id'];
                } else {
                    $client = new Definition('Raven_Client', [
                        $handler['dsn'],
                        [
                            'auto_log_stacks' => $handler['auto_log_stacks'],
                            'environment' => $handler['environment'],
                        ],
                    ]);
                    $client->setPublic(false);
                    $clientId = 'monolog.raven.client.'.sha1($handler['dsn']);
                    $container->setDefinition($clientId, $client);
                }
                $definition->setArguments([
                    new Reference($clientId),
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if (!empty($handler['release'])) {
                    $definition->addMethodCall('setRelease', [$handler['release']]);
                }
                break;

            case 'loggly':
                $definition->setArguments([
                    $handler['token'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if (!empty($handler['tags'])) {
                    $definition->addMethodCall('setTag', [implode(',', $handler['tags'])]);
                }
                break;

            case 'logentries':
                $definition->setArguments([
                    $handler['token'],
                    $handler['use_ssl'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                if (isset($handler['timeout'])) {
                    $definition->addMethodCall('setTimeout', [$handler['timeout']]);
                }
                if (isset($handler['connection_timeout'])) {
                    $definition->addMethodCall('setConnectionTimeout', [$handler['connection_timeout']]);
                }
                break;

            case 'insightops':
                $definition->setArguments([
                    $handler['token'],
                    $handler['region'] ?: 'us',
                    $handler['use_ssl'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            case 'flowdock':
                $definition->setArguments([
                    $handler['token'],
                    $handler['level'],
                    $handler['bubble'],
                ]);

                if (empty($handler['formatter'])) {
                    $formatter = new Definition("Monolog\Formatter\FlowdockFormatter", [
                        $handler['source'],
                        $handler['from_email'],
                    ]);
                    $formatterId = 'monolog.flowdock.formatter.'.sha1($handler['source'].'|'.$handler['from_email']);
                    $formatter->setPublic(false);
                    $container->setDefinition($formatterId, $formatter);

                    $definition->addMethodCall('setFormatter', [new Reference($formatterId)]);
                }
                break;

            case 'rollbar':
                if (!empty($handler['id'])) {
                    $rollbarId = $handler['id'];
                } else {
                    $config = $handler['config'] ?: [];
                    $config['access_token'] = $handler['token'];
                    $rollbar = new Definition('RollbarNotifier', [
                        $config,
                    ]);
                    $rollbarId = 'monolog.rollbar.notifier.'.sha1(json_encode($config));
                    $rollbar->setPublic(false);
                    $container->setDefinition($rollbarId, $rollbar);
                }

                $definition->setArguments([
                    new Reference($rollbarId),
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;
            case 'newrelic':
                $definition->setArguments([
                    $handler['level'],
                    $handler['bubble'],
                    $handler['app_name'],
                ]);
                break;
            case 'server_log':
                $definition->setArguments([
                    $handler['host'],
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;
            case 'sampling':
                $nestedHandlerId = $this->getHandlerId($handler['handler']);
                $this->markNestedHandler($nestedHandlerId);

                $definition->setArguments([
                    new Reference($nestedHandlerId),
                    $handler['factor'],
                ]);
                break;

                // Handlers using the constructor of AbstractHandler without adding their own arguments
            case 'browser_console':
            case 'test':
            case 'null':
            case 'noop':
            case 'debug':
                $definition->setArguments([
                    $handler['level'],
                    $handler['bubble'],
                ]);
                break;

            default:
                $nullWarning = '';
                if ('' == $handler['type']) {
                    $nullWarning = ', if you meant to define a null handler in a yaml config, make sure you quote "null" so it does not get converted to a php null';
                }

                throw new \InvalidArgumentException(\sprintf('Invalid handler type "%s" given for handler "%s"'.$nullWarning, $handler['type'], $name));
        }

        if (!empty($handler['nested']) && true === $handler['nested']) {
            $this->markNestedHandler($handlerId);
        }

        if (!empty($handler['formatter'])) {
            $definition->addMethodCall('setFormatter', [new Reference($handler['formatter'])]);
        }

        if (!\in_array($handlerId, $this->nestedHandlers) && is_subclass_of($handlerClass, ResettableInterface::class)) {
            $definition->addTag('kernel.reset', ['method' => 'reset']);
        }

        $container->setDefinition($handlerId, $definition);

        return $handlerId;
    }

    private function markNestedHandler($nestedHandlerId)
    {
        if (\in_array($nestedHandlerId, $this->nestedHandlers)) {
            return;
        }

        $this->nestedHandlers[] = $nestedHandlerId;
    }

    private function getHandlerId($name)
    {
        return \sprintf('monolog.handler.%s', $name);
    }

    private function getHandlerClassByType($handlerType)
    {
        $typeToClassMapping = [
            'stream' => 'Monolog\Handler\StreamHandler',
            'console' => 'Symfony\Bridge\Monolog\Handler\ConsoleHandler',
            'group' => 'Monolog\Handler\GroupHandler',
            'buffer' => 'Monolog\Handler\BufferHandler',
            'deduplication' => 'Monolog\Handler\DeduplicationHandler',
            'rotating_file' => 'Monolog\Handler\RotatingFileHandler',
            'syslog' => 'Monolog\Handler\SyslogHandler',
            'syslogudp' => 'Monolog\Handler\SyslogUdpHandler',
            'null' => 'Monolog\Handler\NullHandler',
            'test' => 'Monolog\Handler\TestHandler',
            'gelf' => 'Monolog\Handler\GelfHandler',
            'rollbar' => 'Monolog\Handler\RollbarHandler',
            'flowdock' => 'Monolog\Handler\FlowdockHandler',
            'browser_console' => 'Monolog\Handler\BrowserConsoleHandler',
            'firephp' => 'Symfony\Bridge\Monolog\Handler\FirePHPHandler',
            'chromephp' => 'Symfony\Bridge\Monolog\Handler\ChromePhpHandler',
            'debug' => 'Symfony\Bridge\Monolog\Handler\DebugHandler',
            'swift_mailer' => 'Symfony\Bridge\Monolog\Handler\SwiftMailerHandler',
            'native_mailer' => 'Monolog\Handler\NativeMailerHandler',
            'symfony_mailer' => 'Symfony\Bridge\Monolog\Handler\MailerHandler',
            'socket' => 'Monolog\Handler\SocketHandler',
            'pushover' => 'Monolog\Handler\PushoverHandler',
            'raven' => 'Monolog\Handler\RavenHandler',
            'sentry' => 'Sentry\Monolog\Handler',
            'newrelic' => 'Monolog\Handler\NewRelicHandler',
            'hipchat' => 'Monolog\Handler\HipChatHandler',
            'slack' => 'Monolog\Handler\SlackHandler',
            'slackwebhook' => 'Monolog\Handler\SlackWebhookHandler',
            'slackbot' => 'Monolog\Handler\SlackbotHandler',
            'cube' => 'Monolog\Handler\CubeHandler',
            'amqp' => 'Monolog\Handler\AmqpHandler',
            'error_log' => 'Monolog\Handler\ErrorLogHandler',
            'loggly' => 'Monolog\Handler\LogglyHandler',
            'logentries' => 'Monolog\Handler\LogEntriesHandler',
            'whatfailuregroup' => 'Monolog\Handler\WhatFailureGroupHandler',
            'fingers_crossed' => 'Monolog\Handler\FingersCrossedHandler',
            'filter' => 'Monolog\Handler\FilterHandler',
            'mongo' => 'Monolog\Handler\MongoDBHandler',
            'elasticsearch' => 'Monolog\Handler\ElasticSearchHandler',
            'telegram' => 'Monolog\Handler\TelegramBotHandler',
            'server_log' => 'Symfony\Bridge\Monolog\Handler\ServerLogHandler',
            'redis' => 'Monolog\Handler\RedisHandler',
            'predis' => 'Monolog\Handler\RedisHandler',
            'insightops' => 'Monolog\Handler\InsightOpsHandler',
            'sampling' => 'Monolog\Handler\SamplingHandler',
        ];

        $v2HandlerTypesAdded = [
            'elastica' => 'Monolog\Handler\ElasticaHandler',
            'elasticsearch' => 'Monolog\Handler\ElasticaHandler',
            'elastic_search' => 'Monolog\Handler\ElasticsearchHandler',
            'fallbackgroup' => 'Monolog\Handler\FallbackGroupHandler',
            'noop' => 'Monolog\Handler\NoopHandler',
        ];

        $v2HandlerTypesRemoved = [
            'hipchat',
            'raven',
            'slackbot',
        ];

        $v3HandlerTypesRemoved = [
            'swift_mailer',
        ];

        if (Logger::API >= 2) {
            $typeToClassMapping = array_merge($typeToClassMapping, $v2HandlerTypesAdded);
            foreach ($v2HandlerTypesRemoved as $v2HandlerTypeRemoved) {
                unset($typeToClassMapping[$v2HandlerTypeRemoved]);
            }
        }

        if (Logger::API >= 3) {
            foreach ($v3HandlerTypesRemoved as $v3HandlerTypeRemoved) {
                unset($typeToClassMapping[$v3HandlerTypeRemoved]);
            }
        }

        if (!isset($typeToClassMapping[$handlerType])) {
            if (Logger::API === 1 && \array_key_exists($handlerType, $v2HandlerTypesAdded)) {
                throw new \InvalidArgumentException(\sprintf('"%s" was added in Monolog v2, please upgrade if you wish to use it.', $handlerType));
            }

            if (Logger::API >= 2 && \array_key_exists($handlerType, $v2HandlerTypesRemoved)) {
                throw new \InvalidArgumentException(\sprintf('"%s" was removed in Monolog v2.', $handlerType));
            }

            if (Logger::API >= 3 && \array_key_exists($handlerType, $v3HandlerTypesRemoved)) {
                throw new \InvalidArgumentException(\sprintf('"%s" was removed in Monolog v3.', $handlerType));
            }

            throw new \InvalidArgumentException(\sprintf('There is no handler class defined for handler "%s".', $handlerType));
        }

        return $typeToClassMapping[$handlerType];
    }

    private function buildPsrLogMessageProcessor(ContainerBuilder $container, array $processorOptions): string
    {
        static $hasConstructorArguments;

        if (!isset($hasConstructorArguments)) {
            $reflectionConstructor = (new \ReflectionClass(PsrLogMessageProcessor::class))->getConstructor();
            $hasConstructorArguments = null !== $reflectionConstructor && $reflectionConstructor->getNumberOfParameters() > 0;
            unset($reflectionConstructor);
        }

        $processorId = 'monolog.processor.psr_log_message';
        $processorArguments = [];

        unset($processorOptions['enabled']);

        if (!empty($processorOptions)) {
            if (!$hasConstructorArguments) {
                throw new \RuntimeException('Monolog 1.26 or higher is required for the "date_format" and "remove_used_context_fields" options to be used.');
            }
            $processorArguments = [
                $processorOptions['date_format'] ?? null,
                $processorOptions['remove_used_context_fields'] ?? false,
            ];
            $processorId .= '.'.ContainerBuilder::hash($processorArguments);
        }

        if (!$container->hasDefinition($processorId)) {
            $processor = new Definition(PsrLogMessageProcessor::class);
            $processor->setPublic(false);
            $processor->setArguments($processorArguments);
            $container->setDefinition($processorId, $processor);
        }

        return $processorId;
    }
}
