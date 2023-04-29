<?php

namespace Uring;

use Amp\Http\Rfc7230;
use Psr\Http\Message\ResponseInterface;

class HttpResponseBuilder
{
    public static function getResponse(ResponseInterface $response): string
    {
        // HTTP/1.1 404 NOT FOUND\r\nServer: Custom\r\n\r\nBODY
        $body = $response->getBody()?->__toString();

        $headerLine = Rfc7230::formatHeaders($response->getHeaders());
        if (!$response->hasHeader('content-length')) {
            $bodyLength = strlen($body);
            $headerLine .= "Content-Length: $bodyLength\r\n";
        }
        if (!$response->hasHeader('server')) {
            $headerLine .= "Server: PHP-Uring\r\n";
        }

        return "HTTP/{$response->getProtocolVersion()} {$response->getStatusCode()} {$response->getReasonPhrase()}\r\n$headerLine\r\n{$body}";
    }
}
