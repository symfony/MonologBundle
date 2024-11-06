<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\Tests\DependencyInjection;

use Composer\InstalledVersions;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MonologBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigurationTest extends TestCase
{
    /**
     * Some basic tests to make sure the configuration is correctly processed in
     * the standard case.
     */
    public function testProcessSimpleCase()
    {
        $configs = [
            [
                'handlers' => ['foobar' => ['type' => 'stream', 'path' => '/foo/bar']],
            ],
        ];

        $config = $this->process($configs);

        $this->assertArrayHasKey('handlers', $config);
        $this->assertArrayHasKey('foobar', $config['handlers']);
        $this->assertEquals('stream', $config['handlers']['foobar']['type']);
        $this->assertEquals('/foo/bar', $config['handlers']['foobar']['path']);
        $this->assertFalse($config['handlers']['foobar']['nested']);
    }

    public static function provideProcessStringChannels(): array
    {
        return [
            ['foo', 'foo', true],
            ['!foo', 'foo', false],
        ];
    }

    /**
     * @dataProvider provideProcessStringChannels
     */
    public function testProcessStringChannels($string, $expectedString, $isInclusive)
    {
        $configs = [
            [
                'handlers' => [
                    'foobar' => [
                        'type' => 'stream',
                        'path' => '/foo/bar',
                        'channels' => $string,
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertEquals($isInclusive ? 'inclusive' : 'exclusive', $config['handlers']['foobar']['channels']['type']);
        $this->assertCount(1, $config['handlers']['foobar']['channels']['elements']);
        $this->assertEquals($expectedString, $config['handlers']['foobar']['channels']['elements'][0]);
    }

    public static function provideGelfPublisher(): array
    {
        return [
            [
                'gelf.publisher',
            ],
            [
                [
                    'id' => 'gelf.publisher',
                ],
            ],
        ];
    }

    /**
     * @dataProvider provideGelfPublisher
     */
    public function testGelfPublisherService($publisher)
    {
        $configs = [
            [
                'handlers' => [
                    'gelf' => [
                        'type' => 'gelf',
                        'publisher' => $publisher,
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertArrayHasKey('id', $config['handlers']['gelf']['publisher']);
        $this->assertArrayNotHasKey('hostname', $config['handlers']['gelf']['publisher']);
        $this->assertEquals('gelf.publisher', $config['handlers']['gelf']['publisher']['id']);
    }

    public function testArrays()
    {
        $configs = [
            [
                'handlers' => [
                    'foo' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'channels' => ['A', 'B'],
                    ],
                    'bar' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'channels' => ['!C', '!D'],
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        // Check foo
        $this->assertCount(2, $config['handlers']['foo']['channels']['elements']);
        $this->assertEquals('inclusive', $config['handlers']['foo']['channels']['type']);
        $this->assertEquals('A', $config['handlers']['foo']['channels']['elements'][0]);
        $this->assertEquals('B', $config['handlers']['foo']['channels']['elements'][1]);

        // Check bar
        $this->assertCount(2, $config['handlers']['bar']['channels']['elements']);
        $this->assertEquals('exclusive', $config['handlers']['bar']['channels']['type']);
        $this->assertEquals('C', $config['handlers']['bar']['channels']['elements'][0]);
        $this->assertEquals('D', $config['handlers']['bar']['channels']['elements'][1]);
    }

    public function testInvalidArrays()
    {
        $configs = [
            [
                'handlers' => [
                    'foo' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'channels' => ['A', '!B'],
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);

        $config = $this->process($configs);
    }

    public function testMergingInvalidChannels()
    {
        $configs = [
            [
                'handlers' => [
                    'foo' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'channels' => 'A',
                    ],
                ],
            ],
            [
                'handlers' => [
                    'foo' => [
                        'channels' => '!B',
                    ],
                ],
            ],
        ];

        $this->expectException(InvalidConfigurationException::class);

        $config = $this->process($configs);
    }

    /** @group legacy */
    public function testWithSwiftMailerHandler()
    {
        if (Logger::API >= 3) {
            $this->markTestSkipped('This test requires Monolog v2');
        }

        $configs = [
            [
                'handlers' => [
                    'swift' => [
                        'type' => 'swift_mailer',
                        'from_email' => 'foo@bar.com',
                        'to_email' => 'foo@bar.com',
                        'subject' => 'Subject',
                        'mailer' => 'mailer',
                        'email_prototype' => [
                            'id' => 'monolog.prototype',
                            'method' => 'getPrototype',
                        ],
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        // Check email_prototype
        $this->assertCount(2, $config['handlers']['swift']['email_prototype']);
        $this->assertEquals('monolog.prototype', $config['handlers']['swift']['email_prototype']['id']);
        $this->assertEquals('getPrototype', $config['handlers']['swift']['email_prototype']['method']);
        $this->assertEquals('mailer', $config['handlers']['swift']['mailer']);
    }

    public function testWithElasticsearchHandler()
    {
        $configs = [
            [
                'handlers' => [
                    'elasticsearch' => [
                        'type' => 'elasticsearch',
                        'elasticsearch' => [
                            'id' => 'elastica.client',
                        ],
                        'index' => 'my-index',
                        'document_type' => 'my-record',
                        'ignore_error' => true,
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertTrue($config['handlers']['elasticsearch']['ignore_error']);
        $this->assertEquals('my-record', $config['handlers']['elasticsearch']['document_type']);
        $this->assertEquals('my-index', $config['handlers']['elasticsearch']['index']);
    }

    public function testWithTelegramBotHandler()
    {
        $configs = [
            [
                'handlers' => [
                    'telegram' => [
                        'type' => 'telegram',
                        'token' => 'bot-token',
                        'channel' => '-100',
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertEquals('bot-token', $config['handlers']['telegram']['token']);
        $this->assertEquals('-100', $config['handlers']['telegram']['channel']);
    }

    public function testWithConsoleHandler()
    {
        $configs = [
            [
                'handlers' => [
                    'console' => [
                        'type' => 'console',
                        'verbosity_levels' => [
                            'VERBOSITY_NORMAL' => 'NOTICE',
                            'verbosity_verbose' => 'info',
                            'VERBOSITY_very_VERBOSE' => '200',
                        ],
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertSame('console', $config['handlers']['console']['type']);
        $this->assertSame([
            OutputInterface::VERBOSITY_NORMAL => Logger::NOTICE,
            OutputInterface::VERBOSITY_VERBOSE => Logger::INFO,
            OutputInterface::VERBOSITY_VERY_VERBOSE => 200,
            OutputInterface::VERBOSITY_QUIET => Logger::ERROR,
            OutputInterface::VERBOSITY_DEBUG => Logger::DEBUG,
        ], $config['handlers']['console']['verbosity_levels']);
    }

    public function testWithType()
    {
        $configs = [
            [
                'handlers' => [
                    'foo' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'channels' => [
                            'type' => 'inclusive',
                            'elements' => ['A', 'B'],
                        ],
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        // Check foo
        $this->assertCount(2, $config['handlers']['foo']['channels']['elements']);
        $this->assertEquals('inclusive', $config['handlers']['foo']['channels']['type']);
        $this->assertEquals('A', $config['handlers']['foo']['channels']['elements'][0]);
        $this->assertEquals('B', $config['handlers']['foo']['channels']['elements'][1]);
    }

    public function testWithFilePermission()
    {
        $configs = [
            [
                'handlers' => [
                    'foo' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'file_permission' => '0666',
                    ],
                    'bar' => [
                        'type' => 'stream',
                        'path' => '/bar',
                        'file_permission' => 0777,
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertSame(0666, $config['handlers']['foo']['file_permission']);
        $this->assertSame(0777, $config['handlers']['bar']['file_permission']);
    }

    public function testWithUseLocking()
    {
        $configs = [
            [
                'handlers' => [
                    'foo' => [
                        'type' => 'stream',
                        'path' => '/foo',
                        'use_locking' => false,
                    ],
                    'bar' => [
                        'type' => 'rotating_file',
                        'path' => '/bar',
                        'use_locking' => true,
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertFalse($config['handlers']['foo']['use_locking']);
        $this->assertTrue($config['handlers']['bar']['use_locking']);
    }

    public function testWithNestedHandler()
    {
        $configs = [
            [
                'handlers' => ['foobar' => ['type' => 'stream', 'path' => '/foo/bar', 'nested' => true]],
            ],
        ];

        $config = $this->process($configs);

        $this->assertTrue($config['handlers']['foobar']['nested']);
    }

    public function testWithRedisHandler()
    {
        $configs = [
            [
                'handlers' => [
                    'redis' => [
                        'type' => 'redis',
                        'redis' => [
                            'host' => '127.0.1.1',
                            'password' => 'pa$$w0rd',
                            'port' => 1234,
                            'database' => 1,
                            'key_name' => 'monolog_redis_test',
                        ],
                    ],
                ],
            ],
        ];
        $config = $this->process($configs);

        $this->assertEquals('127.0.1.1', $config['handlers']['redis']['redis']['host']);
        $this->assertEquals('pa$$w0rd', $config['handlers']['redis']['redis']['password']);
        $this->assertEquals(1234, $config['handlers']['redis']['redis']['port']);
        $this->assertEquals(1, $config['handlers']['redis']['redis']['database']);
        $this->assertEquals('monolog_redis_test', $config['handlers']['redis']['redis']['key_name']);

        $configs = [
            [
                'handlers' => [
                    'redis' => [
                        'type' => 'predis',
                        'redis' => [
                            'host' => '127.0.1.1',
                            'key_name' => 'monolog_redis_test',
                        ],
                    ],
                ],
            ],
        ];
        $config = $this->process($configs);

        $this->assertEquals('127.0.1.1', $config['handlers']['redis']['redis']['host']);
        $this->assertEquals('monolog_redis_test', $config['handlers']['redis']['redis']['key_name']);
    }

    /**
     * @group legacy
     */
    public function testConsoleFormatterOptionsRename()
    {
        $configs = [
            [
                'handlers' => [
                    'old' => [
                        'type' => 'console',
                        'console_formater_options' => ['foo' => 'foo'],
                    ],
                    'old2' => [
                        'type' => 'console',
                        'console_formater_options' => ['foo' => 'foo'],
                    ],
                    'new' => [
                        'type' => 'console',
                        'console_formatter_options' => ['bar' => 'bar'],
                    ],
                    'new2' => [
                        'type' => 'console',
                        'console_formatter_options' => ['bar' => 'bar'],
                    ],
                    'both' => [
                        'type' => 'console',
                        'console_formater_options' => ['foo' => 'foo'],
                        'console_formatter_options' => ['bar' => 'bar'],
                    ],
                    'both2' => [
                        'type' => 'console',
                        'console_formater_options' => ['foo' => 'foo'],
                        'console_formatter_options' => ['bar' => 'bar'],
                    ],
                ],
            ],
            [
                'handlers' => [
                    'old2' => [
                        'type' => 'console',
                        'console_formater_options' => ['baz' => 'baz'],
                    ],
                    'new2' => [
                        'type' => 'console',
                        'console_formatter_options' => ['qux' => 'qux'],
                    ],
                    'both2' => [
                        'type' => 'console',
                        'console_formater_options' => ['baz' => 'baz'],
                        'console_formatter_options' => ['qux' => 'qux'],
                    ],
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertArrayHasKey('console_formatter_options', $config['handlers']['old']);
        $this->assertSame(['foo' => 'foo'], $config['handlers']['old']['console_formatter_options']);
        $this->assertArrayNotHasKey('console_formater_options', $config['handlers']['old']);

        $this->assertArrayHasKey('console_formatter_options', $config['handlers']['new']);
        $this->assertSame(['bar' => 'bar'], $config['handlers']['new']['console_formatter_options']);
        $this->assertArrayNotHasKey('console_formater_options', $config['handlers']['new']);

        $this->assertArrayHasKey('console_formatter_options', $config['handlers']['both']);
        $this->assertSame(['bar' => 'bar'], $config['handlers']['both']['console_formatter_options']);
        $this->assertArrayNotHasKey('console_formater_options', $config['handlers']['both']);

        $this->assertArrayHasKey('console_formatter_options', $config['handlers']['old2']);
        $this->assertSame(['baz' => 'baz'], $config['handlers']['old2']['console_formatter_options']);
        $this->assertArrayNotHasKey('console_formater_options', $config['handlers']['old2']);

        $this->assertArrayHasKey('console_formatter_options', $config['handlers']['new2']);
        $this->assertSame(['qux' => 'qux'], $config['handlers']['new2']['console_formatter_options']);
        $this->assertArrayNotHasKey('console_formater_options', $config['handlers']['new2']);

        $this->assertArrayHasKey('console_formatter_options', $config['handlers']['both2']);
        $this->assertSame(['qux' => 'qux'], $config['handlers']['both2']['console_formatter_options']);
        $this->assertArrayNotHasKey('console_formater_options', $config['handlers']['both2']);
    }

    /**
     * @dataProvider processPsr3MessagesProvider
     */
    public function testWithProcessPsr3Messages(array $configuration, array $processedConfiguration): void
    {
        $configs = [
            [
                'handlers' => [
                    'main' => ['type' => 'stream'] + $configuration,
                ],
            ],
        ];

        $config = $this->process($configs);

        $this->assertEquals($processedConfiguration, $config['handlers']['main']['process_psr_3_messages']);
    }

    public static function processPsr3MessagesProvider(): iterable
    {
        yield 'Not specified' => [[], ['enabled' => null]];

        if (version_compare(InstalledVersions::getVersion('symfony/config'), '7.2', '>=')) {
            yield 'Null' => [['process_psr_3_messages' => null], ['enabled' => null]];
        } else {
            yield 'Null' => [['process_psr_3_messages' => null], ['enabled' => true]];
        }

        yield 'True' => [['process_psr_3_messages' => true], ['enabled' => true]];
        yield 'False' => [['process_psr_3_messages' => false], ['enabled' => false]];

        yield 'Date format' => [
            ['process_psr_3_messages' => ['date_format' => 'Y']],
            ['date_format' => 'Y', 'enabled' => null],
        ];
        yield 'Enabled false & remove used' => [
            ['process_psr_3_messages' => ['enabled' => false, 'remove_used_context_fields' => true]],
            ['enabled' => false, 'remove_used_context_fields' => true],
        ];
    }

    /**
     * Processes an array of configurations and returns a compiled version.
     *
     * @param array $configs An array of raw configurations
     *
     * @return array A normalized array
     */
    protected function process($configs)
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), $configs);
    }
}
