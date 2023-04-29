<?php

namespace Uring;

use Uring\Internals\FFI;

class Socket
{
    public const COMMON_BUFFER_LENGTH = 8192;

    public function __construct(
        public readonly int $socketFileDescriptor
    )
    {
    }

    public function closeSocket(): void
    {
        FFI::libc()->close($this->socketFileDescriptor);
    }
}
