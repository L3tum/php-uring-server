<?php

namespace Uring;

use Uring\Exceptions\UringException;

class ClientSocket extends Socket
{
    public function __construct(
        protected readonly SocketServer $server,
        int                             $socketFileDescriptor,
        public readonly SocketAddress   $serverAddress,
        public readonly SocketAddress   $clientAddress
    )
    {
        parent::__construct($socketFileDescriptor);
    }

    /**
     * @throws UringException
     */
    public function read(int $length): string
    {
        return $this->server->readAndWait($this->socketFileDescriptor, $length);
    }

    /**
     * @throws UringException
     */
    public function write(string $buffer): void
    {
        $this->server->writeAndWait($this->socketFileDescriptor, $buffer);
    }
}
