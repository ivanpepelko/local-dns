#!/usr/bin/env php
<?php

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use React\Datagram\Factory as DatagramFactory;
use React\Datagram\Socket;
use React\Dns\Config\Config;
use React\Dns\Config\HostsFile;
use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\HostsFileExecutor;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Dns\Query\TcpTransportExecutor;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\Factory as LoopFactory;

require_once __DIR__ . '/vendor/autoload.php';

$loop = LoopFactory::create();

$factory = new DatagramFactory($loop);
$logger = new Logger(
    'local-dns',
    [new StreamHandler(STDOUT)],
    [new ProcessIdProcessor(), new MemoryUsageProcessor(), new MemoryPeakUsageProcessor(), new PsrLogMessageProcessor()]
);
$parser = new Parser();

$config = Config::loadSystemConfigBlocking();
$hosts = HostsFile::loadFromPathBlocking();
//$resolver = (new ResolverFactory())->create(reset($config->nameservers), $loop);
$executor = new SelectiveTransportExecutor(
    new UdpTransportExecutor($config->nameservers[0], $loop),
    new TcpTransportExecutor($config->nameservers[0], $loop)
);
$dns = new HostsFileExecutor($hosts, $executor);
$dumper = new BinaryDumper();

$factory->createServer('0.0.0.0:53')
    ->then(
        function (Socket $server) use ($logger, $parser, $dns, $dumper) {
            $logger->info('then', func_get_args());

            $server->on(
                'message',
                function ($data, $clientAddress, Socket $server) use ($logger, $parser, $dns, $dumper) {
                    $message = $parser->parseMessage($data);
                    $logger->info(
                        'DNS query from {client}',
                        ['client' => $clientAddress, 'data' => $message]
                    );

                    $messageId = $message->id;

                    foreach ($message->questions as $query) {
                        $dns->query($query)->then(
                            function (Message $message) use ($query, $logger, $clientAddress, $dumper, $server, $messageId) {
                                $logger->info(
                                    'Resolved',
                                    [
                                        'query' => $query,
                                        'message' => $message,
                                        'rest' => func_get_args(),
                                    ]
                                );

                                $message->id = $messageId;
                                $server->send($dumper->toBinary($message), $clientAddress);
                            }
                        );
                    }
                }
            );
        }
    )
    ->otherwise(
        function () use ($logger) {
            $logger->error('otherwise', func_get_args());
        }
    );

$loop->run();
