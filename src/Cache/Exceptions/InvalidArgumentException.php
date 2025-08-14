<?php

declare(strict_types=1);

namespace MonkeysLegion\Database\Cache\Exceptions;

use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;

class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException {}
