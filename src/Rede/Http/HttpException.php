<?php

declare(strict_types=1);

namespace RedeOAuth\Http;

use Exception;
use Throwable;

/**
 * Exceção relacionada a requisições HTTP
 */
class HttpException extends Exception
{
    private ?string $returnCode = null;
    private ?string $returnMessage = null;

    /** @var array<string, mixed>|null */
    private ?array $responseBody = null;

    /**
     * @param array<string, mixed>|null $responseBody
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?string $returnCode = null,
        ?string $returnMessage = null,
        ?array $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->returnCode = $returnCode;
        $this->returnMessage = $returnMessage;
        $this->responseBody = $responseBody;
    }

    public function getReturnCode(): ?string
    {
        return $this->returnCode;
    }

    public function getReturnMessage(): ?string
    {
        return $this->returnMessage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }
}
