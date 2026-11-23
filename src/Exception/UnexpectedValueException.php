<?php

declare(strict_types=1);

namespace Hash\Exception;

/** Hash sent something this SDK cannot read: a body that is not JSON, or a field of the wrong type. */
class UnexpectedValueException extends HashException
{
}
