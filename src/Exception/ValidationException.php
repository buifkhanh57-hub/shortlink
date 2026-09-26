<?php

declare(strict_types=1);

namespace Shortlink\Exception;

use Throwable;

/**
 * Thrown when user supplied input fails validation.
 *
 * In addition to the generic error payload it can carry a map of
 * field-name => human readable message which is exposed under the
 * "details" key so API clients can highlight the offending fields.
 */
final class ValidationException extends ShortlinkException
{
    protected int $statusCode = 422;

    protected string $errorCode = 'validation_failed';

    /** @var array<string, string> per-field validation messages */
    private array $errors;

    /**
     * @param array<string, string> $errors
     */
    public function __construct(
        string $message = 'The submitted data is not valid.',
        array $errors = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * Map of field name to human readable validation message.
     *
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function toPayload(): array
    {
        $payload = parent::toPayload();
        if ($this->errors !== []) {
            $payload['error']['details'] = $this->errors;
        }

        return $payload;
    }
}
