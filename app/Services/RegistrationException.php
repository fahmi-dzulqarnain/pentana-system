<?php

namespace App\Services;

use RuntimeException;

/** Thrown when a member cannot register for an activity (closed, full-and-no-waitlist policy, etc.). */
class RegistrationException extends RuntimeException {}
