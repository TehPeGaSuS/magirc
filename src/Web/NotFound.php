<?php

declare(strict_types=1);

namespace Magirc\Web;

/** Thrown by controllers when the requested user, channel or server does not exist. */
final class NotFound extends \RuntimeException
{
}
