<?php
/**
 * An expected error with an HTTP status, shown to the user as-is.
 *   throw new HttpException(422, 'Enter the customer name.');
 * API requests get {"ok":false,"message":...} (+ $details); pages get the error page.
 */
declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
