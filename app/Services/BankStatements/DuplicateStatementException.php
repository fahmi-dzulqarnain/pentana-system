<?php

namespace App\Services\BankStatements;

use RuntimeException;

/** Thrown when this exact file was imported before. The message is safe to show the uploader. */
class DuplicateStatementException extends RuntimeException {}
