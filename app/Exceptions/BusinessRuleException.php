<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by services when an action breaks a business rule (e.g. "an opened unit is still
 * in use"). The message is written for the end user and is shown as-is in a toast/dialog.
 */
class BusinessRuleException extends RuntimeException
{
}
