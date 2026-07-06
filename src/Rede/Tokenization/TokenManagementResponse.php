<?php

declare(strict_types=1);

namespace RedeOAuth\Tokenization;

/**
 * Resposta do gerenciamento de token (PUT /token-service/v1/tokenization/{tokenizationId}).
 *
 * Permite deletar, suspender ou reativar um token de bandeira.
 */
class TokenManagementResponse
{
    private string $returnCode;
    private string $returnMessage;
    private ?string $tokenizationId;
    private ?string $brandName;
    private ?string $brandMessage;

    public function __construct(array $data)
    {
        $this->returnCode     = $data['returnCode'] ?? '';
        $this->returnMessage  = $data['returnMessage'] ?? '';
        $this->tokenizationId = $data['tokenizationId'] ?? null;
        $this->brandName      = $data['brand']['name'] ?? null;
        $this->brandMessage   = $data['brand']['message'] ?? null;
    }

    public function getReturnCode(): string
    {
        return $this->returnCode;
    }

    public function getReturnMessage(): string
    {
        return $this->returnMessage;
    }

    public function getTokenizationId(): ?string
    {
        return $this->tokenizationId;
    }

    /** Nome da bandeira. Preenchido apenas em caso de erro retornado pela bandeira. */
    public function getBrandName(): ?string
    {
        return $this->brandName;
    }

    /** Mensagem de erro da bandeira. Preenchido apenas em caso de erro retornado pela bandeira. */
    public function getBrandMessage(): ?string
    {
        return $this->brandMessage;
    }

    public function isSuccess(): bool
    {
        return $this->returnCode === '00';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return array_filter([
            'returnCode' => $this->returnCode,
            'returnMessage' => $this->returnMessage,
            'tokenizationId' => $this->tokenizationId,
            'brand' => [
                'name' => $this->brandName,
                'message' => $this->brandMessage,
            ],
        ], static fn (mixed $value): bool => $value !== null);
    }
}
