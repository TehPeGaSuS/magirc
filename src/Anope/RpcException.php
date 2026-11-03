<?php

declare(strict_types=1);

namespace Magirc\Anope;

final class RpcException extends \RuntimeException
{
    /** Anope's code for a method the token may not call, or that does not exist. */
    public const METHOD_NOT_FOUND = -32601;

    public function isAuthorizationError(): bool
    {
        return $this->getCode() === self::METHOD_NOT_FOUND
            && str_contains($this->getMessage(), 'No authorization');
    }
}
