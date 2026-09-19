<?php

declare(strict_types=1);

namespace Essabu\Toli;

use Essabu\Toli\Exception\ToliAuthError;
use Essabu\Toli\Exception\ToliConfigError;
use Essabu\Toli\Exception\ToliException;
use Essabu\Toli\Exception\ToliProtocolError;
use Essabu\Toli\Exception\ToliRateLimitError;
use Essabu\Toli\Exception\ToliUnavailableError;
use Essabu\Toli\Question\Question;
use Essabu\Toli\Transport\Gateway;
use Essabu\Toli\Transport\Transport;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The Toli client.
 *
 * One call carries a state and every question at once: the model evaluates them
 * in parallel, which is an order of magnitude cheaper and faster than one
 * request per question.
 *
 * Nothing here applies a decision. `ask()` returns a Reading; the caller reads
 * the route and acts. That asymmetry is deliberate — see Route.
 */
final class Toli
{
    public const CONTRACT_VERSION = 1;

    private readonly Thresholds $thresholds;

    /** Attempts, then we hand control back. */
    private const MAX_ATTEMPTS = 5;

    /** Statuses worth trying again: quota, overload, server fault. */
    private const RETRYABLE = [429, 529];

    /**
     * @param  callable(int): void|null  $sleeper  injected in tests so the suite does not actually wait
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly Transport $transport = new Gateway,
        ?Thresholds $thresholds = null,
        private readonly string $model = 'toli-1',
        private $sleeper = null,
    ) {
        $this->thresholds = $thresholds ?? Thresholds::defaults();

        if (trim($this->apiKey) === '') {
            throw new ToliConfigError('Missing Toli API key: nothing can be asked without it.');
        }
    }

    /**
     * Ask Toli to read a state.
     *
     * Send it what is needed to judge — and nothing more. Names, phone numbers
     * and personal identifiers have no bearing on a category and no business
     * leaving your infrastructure.
     *
     * @param  string|array<string, mixed>  $state  text, or a structure serialised as JSON
     * @param  array<string, Question>  $questions  name => question, all evaluated in one call
     */
    public function ask(string|array $state, array $questions, ?string $model = null): Reading
    {
        if ($questions === []) {
            throw new ToliConfigError('Asking Toli nothing: provide at least one question.');
        }

        $wire = [];
        foreach ($questions as $name => $question) {
            $wire[(string) $name] = $question->toArray();
        }

        $model ??= $this->model;
        $body = json_encode($this->transport->body($state, $wire, $model), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $response = $this->send($body);

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new ToliProtocolError(
                'Unreadable response body: expected JSON.',
                status: $response->getStatusCode(),
                requestId: $this->requestId($response),
                bodyExcerpt: ToliException::excerpt((string) $response->getBody()),
            );
        }

        return Reading::parse($decoded, $model, $this->thresholds);
    }

    private function send(string $body): ResponseInterface
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $request = $this->requests->createRequest('POST', $this->transport->url())
                    ->withHeader('Authorization', 'Bearer '.$this->apiKey)
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('User-Agent', 'toli-php/'.self::CONTRACT_VERSION)
                    ->withBody($this->streams->createStream($body));

                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                // A dropped connection is transient and retries like a 429.
                // Three readings out of 897 were lost before this rule existed.
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ToliUnavailableError("Toli unreachable after {$attempt} attempts: ".$e->getMessage());
                }

                $this->backoff($attempt);

                continue;
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            if ($status === 401 || $status === 403) {
                throw new ToliAuthError(
                    'Toli rejected the key: retrying would change nothing.',
                    status: $status,
                    requestId: $this->requestId($response),
                    bodyExcerpt: ToliException::excerpt((string) $response->getBody()),
                );
            }

            $retryable = in_array($status, self::RETRYABLE, true) || $status >= 500;

            if (! $retryable) {
                throw new ToliProtocolError(
                    "Toli answered HTTP {$status}.",
                    status: $status,
                    requestId: $this->requestId($response),
                    bodyExcerpt: ToliException::excerpt((string) $response->getBody()),
                );
            }

            if ($attempt >= self::MAX_ATTEMPTS) {
                $message = "Toli still failing after {$attempt} attempts (HTTP {$status}).";
                $args = [
                    'status' => $status,
                    'requestId' => $this->requestId($response),
                    'bodyExcerpt' => ToliException::excerpt((string) $response->getBody()),
                ];

                throw $status === 429
                    ? new ToliRateLimitError($message, ...$args)
                    : new ToliUnavailableError($message, ...$args);
            }

            // A server that states its own retry-after knows its load better
            // than our backoff curve does.
            $this->backoff($attempt, $this->retryAfter($response));
        }
    }

    private function backoff(int $attempt, ?int $retryAfter = null): void
    {
        $seconds = $retryAfter ?? (int) (2 ** ($attempt - 1));
        $sleeper = $this->sleeper;

        $sleeper !== null ? $sleeper($seconds) : sleep($seconds);
    }

    private function retryAfter(ResponseInterface $response): ?int
    {
        $header = $response->getHeaderLine('retry-after');

        return is_numeric($header) ? (int) $header : null;
    }

    private function requestId(ResponseInterface $response): ?string
    {
        $id = $response->getHeaderLine('x-request-id');

        return $id === '' ? null : $id;
    }
}
