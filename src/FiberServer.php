<?php

namespace Uring;

use Exception;
use FFI;
use Fiber;
use FiberError;
use Throwable;
use Uring\Exceptions\BadFileDescriptorException;
use Uring\Exceptions\InvalidArgumentException;
use Uring\Exceptions\MissingWaiterException;
use Uring\States\AcceptState;
use Uring\States\CancelState;
use Uring\States\CloseState;
use Uring\States\CreateSocketState;
use Uring\States\ReadState;
use Uring\States\ShutdownState;
use Uring\States\StatesState;
use Uring\States\TimeoutState;
use Uring\States\WriteState;

class FiberServer extends SocketServer
{
    /**
     * @var array<int, Fiber>
     */
    protected array $runningClients = [];

    /** @var array<class-string, array<int, Fiber>> */
    protected array $waiters = [];

    public function __construct()
    {
        foreach (StatesState::STATES as $state) {
            $this->waiters[$state] = [];
        }
    }

    private static function finalizer(FiberServer $that, int $socket): void
    {
        foreach (StatesState::STATES as $state) {
            unset($that->waiters[$state][$socket]);
        }

        try {
            $that->closeAndWait($socket);
        } catch (Exception|Throwable $e) {
            echo "Got exception in finalizer for socket $socket: " . $e::class . " " . $e->getMessage() . PHP_EOL;
        } finally {
            Internals\FFI::libc()->close($socket);
            unset($that->runningClients[$socket]);
            foreach (StatesState::STATES as $state) {
                unset($that->waiters[$state][$socket]);
            }
            echo "Closed socket $socket" . PHP_EOL;
        }
    }

    /**
     * @inheritDoc
     */
    protected function handleUringStates(array $states): void
    {
        $index = count($states);
        try {
            foreach ($states as $index => $state) {
                $fiber = $this->waiters[$state::class][$state->fileDescriptor] ?? null;

                match ($fiber !== null) {
                    true => match (isset($state->exception)) {
                        true => $fiber->throw($state->exception),
                        false => match ($state::class) {
                            AcceptState::class => throw new BadFileDescriptorException("File descriptor for $state->fileDescriptor is already running!"),
                            ReadState::class => match ($state->bufferWritten >= 0) {
                                true => $fiber->resume(FFI::string($state->buffer, $state->bufferWritten)),
                                false => $fiber->throw(new InvalidArgumentException("Buffer for read was -1"))
                            },
                            WriteState::class => $fiber->resume($state->bufferWritten),
                            CreateSocketState::class => $fiber->resume($state->createdFileDescriptor),
                            default => $fiber->resume()
                        }
                    },
                    false => match ($state::class) {
                        AcceptState::class =>
                            (
                            $this->runningClients[$state->fileDescriptor] = $fiber = new Fiber($this->onAccept))
                            && $fiber->start(new ClientSocket($this, $state->fileDescriptor, $this->listeningSockets[$state->serverSocket]->socketAddress, SocketAddress::fromCData($state->clientAddress))
                            ),
                        default => match (isset($this->runningClients[$state->fileDescriptor])) {
                            false => null,
                            true => match ($this->runningClients[$state->fileDescriptor]->isTerminated()) {
                                true => null,
                                false => throw new MissingWaiterException("Got Event without listener on fd $state->fileDescriptor " . $state::class)
                            }
                        }
                    }
                };

                if (($fiber = $this->runningClients[$state->fileDescriptor] ?? null) !== null && $fiber->isTerminated()) {
                    $this->launchFinalizer($state->fileDescriptor);
                }

                // throw new MissingWaiterException("Got Event without listener on fd $state->fileDescriptor " . $state::class)
            }
        } catch (Exception|Throwable $e) {
            $fiber = $this->runningClients[$state->fileDescriptor] ?? null;

            if ($e instanceof FiberError) {
                $status = "None";

                if ($fiber === null) {
                    $status = "None";
                } elseif ($fiber->isTerminated()) {
                    $status = "Terminated";
                } elseif ($fiber->isSuspended()) {
                    $status = "Suspended";
                } elseif ($fiber->isRunning()) {
                    $status = "Running";
                } elseif (!$fiber->isStarted()) {
                    $status = "Not Started Yet";
                }

                echo "FiberError " . $e->getMessage() . " on Fiber $state->fileDescriptor with state " . $state::class . " in status $status" . PHP_EOL;
            } else {
                // TODO: Log
                echo "Got Exception " . $e::class . " " . $e->getMessage() . " For State " . $state::class . " with fd $state->fileDescriptor" . PHP_EOL;
                echo $e->getTraceAsString() . PHP_EOL;
//                    FFI::libc()->close($state->fileDescriptor);
            }
        } finally {
            if (count($states) > $index + 2) {
                echo "Running handleUringStates again because $index + 2 is less than " . count($states) . PHP_EOL;
                $this->handleUringStates(array_slice($states, $index + 1));
            }
        }
    }

    protected function startListeningSocketsAsync(): void
    {
        foreach ($this->wantedSockets as $wantedSocket) {
            $fiber = new Fiber(function (array $wantedSocket) {
                $socketFd = $this->uring->flags->supportsCreateSocket
                    ? $this->createSocketAndWait($wantedSocket['domain'])
                    : SocketFFI::createSocket($wantedSocket['domain']);
                SocketFFI::enableReusableAddress($socketFd);
                $sockAddr = SocketFFI::createSocketAddress($wantedSocket['address'], $wantedSocket['port'], $wantedSocket['domain']);
                SocketFFI::bindSocketAddressToSocket($socketFd, $sockAddr);
                SocketFFI::testListen($socketFd);
                $this->listeningSockets[$socketFd] = new ServerSocket($socketFd, $sockAddr);
                $this->uring->acceptRequests($socketFd);
            });
            $fiber->start($wantedSocket);
        }

        $this->wantedSockets = [];
    }

    protected function launchHousekeeper(int $referenceFd): void
    {
        $this->runningClients[$referenceFd] = $fiber = new Fiber(fn() => $this->handleHousekeeper($referenceFd));
        $fiber->start();
    }

    protected function doHousekeeping(): void
    {
        foreach ($this->runningClients as $socket => $fiber) {
            if ($fiber !== null && $fiber->isTerminated()) {
                $this->launchFinalizer($socket);
            }
        }
    }

    private function launchFinalizer(int $socket): void
    {
        $that = $this;
        $this->runningClients[$socket] = $fiber = new Fiber(static fn($that, $socket) => self::finalizer($that, $socket));
        $fiber->start($that, $socket);
    }

    /**
     * @inheritDoc
     */
    public function readAndWait(int $fileDescriptor, int $bufferLength): string
    {
        $this->uring->readRequest($fileDescriptor, $bufferLength);
        $this->waiters[ReadState::class][$fileDescriptor] =& $this->runningClients[$fileDescriptor];
        $buffer = Fiber::suspend();
        unset($this->waiters[ReadState::class][$fileDescriptor]);
        return $buffer;
    }

    /**
     * @inheritDoc
     */
    public function writeAndWait(int $fileDescriptor, string &$buffer): void
    {
        $this->uring->writeRequest($fileDescriptor, $buffer);
        $this->waiters[WriteState::class][$fileDescriptor] =& $this->runningClients[$fileDescriptor];
        Fiber::suspend();
        unset($this->waiters[WriteState::class][$fileDescriptor]);
    }

    /**
     * @inheritDoc
     */
    public function closeAndWait(int $fileDescriptor): void
    {
        if ($this->uring->flags->supportsCancelFd) {
            $this->uring->cancelRequests($fileDescriptor);
            $this->waiters[CancelState::class][$fileDescriptor] =& $this->runningClients[$fileDescriptor];
            Fiber::suspend();
            unset($this->waiters[CancelState::class][$fileDescriptor]);
        }
        if ($this->uring->flags->supportsShutdown) {
            $this->uring->shutdownRequest($fileDescriptor, STREAM_SHUT_WR);
            $this->waiters[ShutdownState::class][$fileDescriptor] =& $this->runningClients[$fileDescriptor];
            Fiber::suspend();
            $this->uring->shutdownRequest($fileDescriptor, STREAM_SHUT_RD);
            Fiber::suspend();
            unset($this->waiters[ShutdownState::class][$fileDescriptor]);
        }
        $this->uring->closeRequest($fileDescriptor);
        $this->waiters[CloseState::class][$fileDescriptor] =& $this->runningClients[$fileDescriptor];
        Fiber::suspend();
        unset($this->waiters[CloseState::class][$fileDescriptor]);
    }

    /**
     * @inheritDoc
     */
    public function timeoutAndWait(int $fileDescriptor, float $timeoutInSeconds): void
    {
        $this->uring->timeoutRequest($fileDescriptor, $timeoutInSeconds);
        $this->waiters[TimeoutState::class][$fileDescriptor] =& $this->runningClients[$fileDescriptor];
        Fiber::suspend();
        unset($this->waiters[TimeoutState::class][$fileDescriptor]);
    }

    /**
     * @inheritDoc
     */
    public function createSocketAndWait(int $domain): int
    {
        $fakeFd = $this->uring->createSocketRequest(STREAM_PF_INET, SOCK_STREAM, 0);
        $this->waiters[CreateSocketState::class][$fakeFd] = Fiber::getCurrent();
        $createdFd = Fiber::suspend();
        unset($this->waiters[CreateSocketState::class][$fakeFd]);
        return $createdFd;
    }
}
