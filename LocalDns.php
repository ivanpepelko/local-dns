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
use Throwable;

class LocalDns
{
    private LoopInterface $loop;
    private LoggerInterface $logger;
    private Parser $protoParser;
    private BinaryDumper $protoDumper;
    private HostsFileExecutor $executor;
    private Resolver $resolver;

    public function __construct(string $address, ?string $hostsFilePath = null, array $nameservers = [], bool $override = false)
    {
        $this->loop = EventLoopFactory::create();

        $this->installSignalHandlers();

        $this->logger = new Logger(
            'local-dns',
            [new StreamHandler(STDOUT)],
            [new ProcessIdProcessor(), new MemoryUsageProcessor(), new MemoryPeakUsageProcessor(), new PsrLogMessageProcessor()]
        );
        $this->protoParser = new Parser();
        $this->protoDumper = new BinaryDumper();

        if (!$override) {
            $config = Config::loadSystemConfigBlocking();
            $nameservers = array_merge($config->nameservers, $nameservers);
        }

        $hosts = HostsFile::loadFromPathBlocking($hostsFilePath);

        $this->executor = new HostsFileExecutor($hosts, new RoundRobinExecutor($nameservers, $this->loop));
        $this->resolver = new Resolver($this->executor);

        $this->createServer($address)
            ->then(Closure::fromCallable([$this, 'handle']))
            ->otherwise(Closure::fromCallable([$this, 'handleException']));
    }

    private function createServer(string $address): PromiseInterface
    {
        return (new DatagramFactory($this->loop, $this->resolver))->createServer($address);
    }

    private function handle(Socket $server): void
    {
        $this->logger->info('then', func_get_args());
        $server->on('message', Closure::fromCallable([$this, 'handleMessage']));
    }

    private function handleException(Throwable $exception): void
    {
        $this->logger->error('otherwise', func_get_args());
    }

    public static function run(string $address, ?string $hostsFilePath = null, array $nameservers = [], bool $override = false): void
    {
        (new self($address, $hostsFilePath, $nameservers, $override))->loop->run();
    }

    private function handleMessage($data, $clientAddress, Socket $server): void
    {
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

    private function installSignalHandlers(): void
    {
        // SIGTERM -> docker stop signal

        $this->loop->addSignal(
            SIGTERM,
            function () {
                $this->loop->stop();
            }
        );
    }

}
