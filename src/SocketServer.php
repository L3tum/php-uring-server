<?php

namespace Uring;

use Closure;
use RuntimeException;
use Uring\Exceptions\UringException;
use Uring\States\InputOutputState;

abstract class SocketServer
{
    /**
     * @var array<int, ServerSocket>
     */
    protected array $listeningSockets = [];
    protected UringFFI $uring;
    protected bool $stopped = false;
    protected Closure $onAccept;
    protected int $queueDepth;

    /**
     * @var array<array-key, array{address: string, port: int, domain: int}>
     */
    protected array $wantedSockets = [];

    final public function addListeningSocket(string $address, int $port, int $domain = STREAM_PF_INET): void
    {
        $this->wantedSockets[] = ['address' => $address, 'port' => $port, 'domain' => $domain];
    }

    protected function initializeBackend(int $queueDepth, ?int $masterFd = null): void
    {
        if (!isset($this->uring)) {
            $this->queueDepth = $queueDepth;
            $this->uring = new UringFFI($queueDepth, Socket::COMMON_BUFFER_LENGTH);
            $this->uring->initializeQueue($masterFd);
        }
    }

    public function run(int $queueDepth = 1024): void
    {
        if (count($this->wantedSockets) === 0 && count($this->listeningSockets) === 0) {
            // TODO
            throw new RuntimeException("Need some sockets man");
        }

        if (!isset($this->onAccept)) {
            throw new RuntimeException("No accept callback man");
        }

        $this->initializeBackend($queueDepth);
        $this->launchHousekeeper(-1);
        $this->startListeningSocketsAsync();

        while (!$this->stopped) {
            if ($this->hasRunningClients()) {
                $this->uring->conditionalSubmit();
                $states = $this->uring->peekBatch();
            } else {
                $states = $this->uring->submitAndWait();
            }

            $this->handleUringStates($states);
            $this->uring->freeRequestStates($states);
        }
    }

    abstract protected function startListeningSocketsAsync(): void;

    final public function setOnAccept(Closure $callback): void
    {
        $this->onAccept = $callback;
    }

    protected function hasRunningClients(): bool
    {
        return false;
    }

    abstract protected function launchHousekeeper(int $referenceFd): void;

    abstract protected function doHousekeeping(): void;

    protected function handleHousekeeper(int $referenceFd): void
    {
        while (!$this->stopped) {
            try {
                $this->timeoutAndWait($referenceFd, $this->getHousekeepingInterval());
            } catch (UringException) {
                // Intentionally left blank
            }
            $this->doHousekeeping();
        }
    }

    protected function getHousekeepingInterval(): float
    {
        return 10.0;
    }

    /**
     * @param InputOutputState[] $states
     */
    abstract protected function handleUringStates(array $states): void;

    /**
     * @throws UringException
     * @internal
     */
    abstract public function readAndWait(int $fileDescriptor, int $bufferLength): string;

    /**
     * @throws UringException
     * @internal
     */
    abstract public function writeAndWait(int $fileDescriptor, string &$buffer): void;

    /**
     * @throws UringException
     * @internal
     */
    abstract public function closeAndWait(int $fileDescriptor): void;

    /**
     * @throws UringException
     * @internal
     */
    abstract public function timeoutAndWait(int $fileDescriptor, float $timeoutInSeconds): void;

    /**
     * Returns the created filedescriptor
     * @throws UringException
     * @internal
     */
    abstract public function createSocketAndWait(int $domain): int;

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        echo PHP_EOL . "DIE DIE DIE" . PHP_EOL;
        if (isset($this->uring)) {
            $this->uring->stop();
        }

        foreach ($this->listeningSockets as $socket) {
            $socket->closeSocket();
        }
    }
}
