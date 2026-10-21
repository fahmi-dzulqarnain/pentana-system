<?php

namespace App\Exceptions;

use RuntimeException;

// Another admin (or a double-click) reviewed the proof first.
class PaymentNotPendingException extends RuntimeException {}
