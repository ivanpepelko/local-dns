<?php

namespace Aperture\LocalDns;

use React\Dns\Query\CoopExecutor;
use React\Dns\Query\ExecutorInterface;
use React\Dns\Query\Query;
use React\Dns\Query\RetryExecutor;
use React\Dns\Query\SelectiveTransportExecutor;
use React\Dns\Query\TcpTransportExecutor;
use React\Dns\Query\TimeoutExecutor;
use React\Dns\Query\UdpTransportExecutor;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

class RoundRobinExecutor implements ExecutorInterface
{
    /** @var array|ExecutorInterface[] */
    private array $executors = [];
    private LoopInterface $loop;

    public function __construct(array $nameservers, LoopInterface $loop, ?callable $executorFactory = null)
    {
        $this->loop = $loop;
        $executorFactory ??= $this->createDefaultExecutorFactory();

        foreach ($nameservers as $nameserver) {
            $this->executors[$nameserver] = $executorFactory($nameserver);
        }
    }

    private function createDefaultExecutorFactory(): callable
    {
        return function (string $nameserver) {
            $parts = parse_url($nameserver);

            if (isset($parts['scheme']) && $parts['scheme'] === 'tcp') {
                $executor = $this->createTcpExecutor($nameserver);
            } elseif (isset($parts['scheme']) && $parts['scheme'] === 'udp') {
                $executor = $this->createUdpExecutor($nameserver);
            } else {
                $executor = new SelectiveTransportExecutor(
                    $this->createUdpExecutor($nameserver),
                    $this->createTcpExecutor($nameserver)
                );
            }

            return new CoopExecutor($executor);
        };
    }

    private function createTcpExecutor($nameserver): ExecutorInterface
    {
        return new TimeoutExecutor(
            new TcpTransportExecutor($nameserver, $this->loop), 5.0, $this->loop
        );
    }

    private function createUdpExecutor($nameserver): ExecutorInterface
    {
        return new RetryExecutor(
            new TimeoutExecutor(
                new UdpTransportExecutor($nameserver, $this->loop), 5.0, $this->loop
            )
        );
    }

    public function query(Query $query): PromiseInterface
    {
        $promise = current($this->executors)->query($query);
        if (next($this->executors) === false) {
            reset($this->executors);
        }
        return $promise;
    }

}