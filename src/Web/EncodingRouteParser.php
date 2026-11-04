<?php

declare(strict_types=1);

namespace Magirc\Web;

use Psr\Http\Message\UriInterface;
use Slim\Interfaces\RouteParserInterface;

/**
 * Slim puts route arguments into the path as they are, so "#", "?", "%",
 * spaces or a nick's "\" broke links. This percent-encodes every argument,
 * keeping "/" (routes that take it, like channels, use a {name:.+} pattern;
 * an encoded %2F is refused by Apache's default AllowEncodedSlashes Off).
 */
final class EncodingRouteParser implements RouteParserInterface
{
    public function __construct(private readonly RouteParserInterface $inner)
    {
    }

    public function relativeUrlFor(string $routeName, array $data = [], array $queryParams = []): string
    {
        return $this->inner->relativeUrlFor($routeName, self::encode($data), $queryParams);
    }

    public function urlFor(string $routeName, array $data = [], array $queryParams = []): string
    {
        return $this->inner->urlFor($routeName, self::encode($data), $queryParams);
    }

    public function fullUrlFor(UriInterface $uri, string $routeName, array $data = [], array $queryParams = []): string
    {
        return $this->inner->fullUrlFor($uri, $routeName, self::encode($data), $queryParams);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function encode(array $data): array
    {
        return array_map(static fn (mixed $v): string => str_replace('%2F', '/', rawurlencode((string) $v)), $data);
    }
}
