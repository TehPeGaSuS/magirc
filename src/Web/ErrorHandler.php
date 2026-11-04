<?php

declare(strict_types=1);

namespace Magirc\Web;

use Magirc\Anope\RpcException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\Twig;
use Throwable;

/**
 * Renders errors as pages: 404 for unknown routes or objects, 503 when
 * Anope cannot be reached, 500 for the rest (with details in debug mode).
 */
final class ErrorHandler
{
    /** @param \Closure(): Twig $twig */
    public function __construct(private readonly App $app, private readonly \Closure $twig)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        [$status, $title, $message] = match (true) {
            $exception instanceof NotFound => [404, 'Not found', $exception->getMessage() ?: 'There is nothing at this address.'],
            $exception instanceof HttpNotFoundException => [404, 'Not found', 'There is nothing at this address.'],
            $exception instanceof RpcException => [503, 'Services unavailable', 'MagIRC could not get data from the IRC services. Try again in a moment.'],
            default => [500, 'Something went wrong', 'The page could not be built.'],
        };
        if ($logErrors && $status >= 500) {
            error_log(sprintf('magirc: %s: %s in %s:%d', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
        }
        $response = $this->app->getResponseFactory()->createResponse($status);
        if (str_starts_with($request->getUri()->getPath(), rtrim($this->app->getBasePath(), '/') . '/api/')) {
            return \Magirc\Web\App::json($response, ['error' => $title, 'message' => $message]);
        }
        return ($this->twig)()->render($response, 'error.twig', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'details' => $displayErrorDetails ? $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() : null,
        ]);
    }
}
