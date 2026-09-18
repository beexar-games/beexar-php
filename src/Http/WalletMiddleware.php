<?php

declare(strict_types=1);

namespace Beexar\Http;

use Beexar\Signature;
use Beexar\WalletServer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware serving the four callbacks.
 *
 * ```php
 * $app->add(new WalletMiddleware($server, $responseFactory));
 * ```
 *
 * **Register it before any body-parsing middleware.** Slim's
 * BodyParsingMiddleware and Laravel's equivalents read the stream and hand you
 * a decoded array; re-encoding it produces different bytes — different key
 * order, different escaping — and the signature can never match again.
 *
 * The middleware rewinds the stream before reading, so it also works when
 * something upstream has already read (but not replaced) the body.
 *
 * Requires psr/http-server-middleware and psr/http-factory. The class is only
 * loaded if you reference it, so the SDK itself stays dependency-free.
 */
final class WalletMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly WalletServer $server,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = WalletServer::routeFromPath($request->getUri()->getPath());
        if ($route === null || $request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $rawBody = $stream->getContents();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $out = $this->server->dispatch(
            $route,
            $rawBody,
            $request->getHeaderLine(Signature::HEADER) ?: null,
            $headers,
        );

        $response = $this->responseFactory->createResponse($out['status']);
        foreach ($out['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        $response->getBody()->write($out['body']);

        return $response;
    }
}
