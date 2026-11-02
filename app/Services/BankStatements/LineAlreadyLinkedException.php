<?php

namespace App\Services\BankStatements;

use RuntimeException;

/**
 * @internal Rolls back StatementMatcher's auto-verify transaction when a concurrent import
 * linked the line first. Never escapes the matcher; any other exception is a real failure.
 */
final class LineAlreadyLinkedException extends RuntimeException {}
