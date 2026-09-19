<?php

declare(strict_types=1);

namespace Essabu\Toli\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A scripted HTTP client: hand it the responses, read back the requests.
 *
 * It doubles as the PSR-17 factories so a test can build the client with one
 * object instead of three.
 */
final class FakeHttpClient implements ClientInterface, RequestFactoryInterface, StreamFactoryInterface
{
    /** @var list<RequestInterface> every request actually sent */
    public array $requests = [];

    private readonly Psr17Factory $psr17;

    /** @var list<ResponseInterface|NetworkFailure> */
    private array $scripted;

    /** @param list<ResponseInterface|NetworkFailure> $scripted */
    public function __construct(array $scripted)
    {
        $this->psr17 = new Psr17Factory;
        $this->scripted = $scripted;
    }

    /** @param array<string, mixed> $body */
    public static function ok(array $body): ResponseInterface
    {
        return self::status(200, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function status(int $status, array $body, array $headers = []): ResponseInterface
    {
        return self::raw($status, json_encode($body, JSON_THROW_ON_ERROR), $headers);
    }

    /** @param array<string, string> $headers */
    public static function raw(int $status, string $body, array $headers = []): ResponseInterface
    {
        $factory = new Psr17Factory;
        $response = $factory->createResponse($status)->withBody($factory->createStream($body));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    public static function networkFailure(): NetworkFailure
    {
        return new NetworkFailure;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $next = array_shift($this->scripted)
            ?? throw new RuntimeException('The fake client ran out of scripted responses: the SDK sent more requests than the test expected.');

        if ($next instanceof NetworkFailure) {
            throw new FakeNetworkException($request);
        }

        return $next;
    }

    public function createRequest(string $method, $uri): RequestInterface
    {
        return $this->psr17->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return $this->psr17->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return $this->psr17->createStreamFromFile($filename, $mode);
    }

    /** @param resource $resource */
    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->psr17->createStreamFromResource($resource);
    }
}

/** Marker: this turn of the script drops the connection instead of answering. */
final class NetworkFailure {}

final class FakeNetworkException extends RuntimeException implements ClientExceptionInterface, NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request)
    {
        parent::__construct('connection reset by peer');
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
