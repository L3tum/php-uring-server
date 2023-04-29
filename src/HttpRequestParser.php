<?php

namespace Uring;

use Amp\Http\Rfc7230;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class HttpRequestParser
{
    public static function getRequest(string &$buffer): RequestInterface
    {
        $requestParts = explode("\r\n", $buffer, 2);

        if (count($requestParts) < 2) {
            throw new RuntimeException("Malformed request $buffer");
        }

        $requestLine =& $requestParts[0];
        $requestBody =& $requestParts[1];

        if (mb_strlen($requestLine) > 256) {
            throw new RuntimeException("Request-Line too long");
        }

        if (preg_match("/^([A-Z]+) ([^ ]+) HTTP\/(1|1\.0|1\.1|2|2\.0)$/", $requestLine, $matches) === 1) {
            $method =& $matches[1];
            $requestUri =& $matches[2];
            $version =& $matches[3];
            self::verifyHttpMethod($method);
        } else {
            throw new RuntimeException("Invalid Request-Line");
        }

        unset($requestLine);
        $requestParts = explode("\r\n\r\n", $requestBody, 2);
        $headers = Rfc7230::parseHeaders($requestParts[0] . "\r\n");
        $rawBody =& $requestParts[1];

        if (isset($headers['host'])) {
            $requestUri = $headers['host'][0] . $requestUri;
        }

        return new \Nyholm\Psr7\Request($method, $requestUri, $headers, $rawBody, $version);
    }

    public static function verifyHttpMethod(string &$method): void
    {
        match ($method) {
            'HEAD', 'GET' => null,
            default => throw new RuntimeException("Unsupported HTTP Method $method")
        };
    }
}
