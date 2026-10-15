<?php
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- trigger_error text is a developer notice, never sent to a browser.
namespace Dosya\Backup\Connect;

final class HttpException extends \RuntimeException
{
    public int $status;
    public string $errorCode;
    public bool $retryable;
    public ?int $retryAfter;

    /** `$code` is the API's machine reason (or error) string; RuntimeException::$code is an int, so it lives in $errorCode and is readable as ->code via __get. */
    public function __construct(int $status, string $code, string $message = '', ?int $retryAfter = null)
    {
        parent::__construct($message !== '' ? $message : 'dosya api error ' . $status . ' ' . $code);
        $this->status = $status;
        $this->errorCode = $code;
        $this->retryable = $status === 429 || $status >= 500 || $status === 0;
        $this->retryAfter = $retryAfter;
    }

    public function __get(string $name)
    {
        if ($name === 'code') {
            return $this->errorCode;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- mirrors PHP's own notice for an unknown property; not debug output
        trigger_error('Undefined property: ' . __CLASS__ . '::$' . $name, E_USER_NOTICE);
        return null;
    }
}
