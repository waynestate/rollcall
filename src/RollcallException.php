<?php

declare(strict_types=1);

namespace Waynestate\Rollcall;

use RuntimeException;

/**
 * Every failure ROLLCALL raises on purpose. The CLI turns it into exit code 2.
 */
class RollcallException extends RuntimeException {}
