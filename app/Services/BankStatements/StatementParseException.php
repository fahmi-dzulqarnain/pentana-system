<?php

namespace App\Services\BankStatements;

use RuntimeException;

/** Thrown when a bank statement cannot be read or does not reconcile. The message is safe to show the uploader. */
class StatementParseException extends RuntimeException {}
