<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyConflict extends RuntimeException {}
