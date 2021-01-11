<?php

namespace Aperture\LocalDns;

use Closure;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\Dns\Config\Config;
use React\Dns\Config\HostsFile;
use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\HostsFileExecutor;
use React\Dns\Resolver\Resolver;
use React\EventLoop\Factory as EventLoopFactory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class LocalDns
{
    private LoopInterface $loop;
    private LoggerInterface $logger;
    private Parser $protoParser;
    private BinaryDumper $protoDumper;
    private HostsFileExecutor $executor;
    private Resolver $resolver;

    public function __construct(array $nameservers = [], bool $override = false)
    {
        $this->loop = EventLoopFactory::create();
        $this->logger = new Logger(
            'local-dns',
            [new StreamHandler(STDOUT) /*, new SyslogHandler('local-dns')*/],
            [new ProcessIdProcessor(), new MemoryUsageProcessor(), new MemoryPeakUsageProcessor(), new PsrLogMessageProcessor()]
        );
        $this->protoParser = new Parser();
        $this->protoDumper = new BinaryDumper();

        if (!$override) {
            $config = Config::loadSystemConfigBlocking();
            $nameservers = array_merge($config->nameservers, $nameservers);
        }

        $hosts = HostsFile::loadFromPathBlocking();

        $this->executor = new HostsFileExecutor($hosts, new RoundRobinExecutor($nameservers, $this->loop));
        $this->resolver = new Resolver($this->executor);

        $this
            ->createServer()
            ->then($this->createHandler())
            ->otherwise($this->createErrorHandler());
    }

    private function createServer($address = '0.0.0.0:53'): PromiseInterface
    {
        return (new DatagramFactory($this->loop, $this->resolver))->createServer($address);
    }

    private function createHandler(): Closure
    {
        return function (Socket $server) {
            $this->logger->info('then', func_get_args());

            $server->on(
                'message',
                function ($data, $clientAddress, Socket $server) {
                    $message = $this->protoParser->parseMessage($data);
                    $this->logger->info(
                        'DNS query from {client}',
                        ['client' => $clientAddress, 'data' => $message]
                    );

                    $messageId = $message->id;

                    foreach ($message->questions as $query) {
                        $this->executor->query($query)->then(
                            function (Message $message) use ($query, $messageId, $server, $clientAddress) {
                                $this->logger->info(
                                    'Resolved',
                                    [
                                        'query' => $query,
                                        'message' => $message,
                                        'rest' => func_get_args(),
                                    ]
                                );

                                $message->id = $messageId;
                                $server->send($this->protoDumper->toBinary($message), $clientAddress);
                            }
                        );
                    }
                }
            );
        };
    }

    private function createErrorHandler(): Closure
    {
        return function () {
            $this->logger->error('otherwise', func_get_args());
        };
    }

    public static function run(): void
    {
        $dns = new self();
        $dns->loop->run();
    }

}