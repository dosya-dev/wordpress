<?php
namespace Dosya\Backup\Wp;

/** A connect failure with a message that is safe to show to the admin. */
final class ConnectException extends \RuntimeException
{
    public string $reason;

    public function __construct(string $message, string $reason = 'exchange')
    {
        parent::__construct($message);
        $this->reason = $reason;
    }
}
