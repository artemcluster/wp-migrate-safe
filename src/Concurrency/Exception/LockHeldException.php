<?php
declare(strict_types=1);

namespace WpMigrateSafe\Concurrency\Exception;

use RuntimeException;

class LockHeldException extends RuntimeException
{
    private string $holderJobId;

    public function __construct(string $message, string $holderJobId)
    {
        parent::__construct($message);
        $this->holderJobId = $holderJobId;
    }

    public function holderJobId(): string { return $this->holderJobId; }
}
