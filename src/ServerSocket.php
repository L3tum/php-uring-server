<?php

namespace Uring;

class ServerSocket extends Socket
{
    public function __construct(
        public readonly int           $socketFileDescriptor,
        public readonly SocketAddress $socketAddress
    )
    {
        parent::__construct($socketFileDescriptor);
    }
}
