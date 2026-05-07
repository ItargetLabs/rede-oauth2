<?php

declare(strict_types=1);

namespace RedeOAuth\Tokenization;

/**
 * Resposta da requisição de criação de tokenização (POST /token-service/v1/tokenization).
 */
class TokenizationResponse
{
    private string $returnCode;
    private string $returnMessage;
    private ?string $tokenizationId;

    public function __construct(array $data)
    {
        $this->returnCode    = $data['returnCode'] ?? '';
        $this->returnMessage = $data['returnMessage'] ?? '';
        $this->tokenizationId = $data['tokenizationId'] ?? null;
    }

    public function getReturnCode(): string
    {
        return $this->returnCode;
    }

    public function getReturnMessage(): string
    {
        return $this->returnMessage;
    }

    /**
     * Identificador único da requisição de tokenização na Rede (UUID, até 36 caracteres).
     */
    public function getTokenizationId(): ?string
    {
        return $this->tokenizationId;
    }

    public function isSuccess(): bool
    {
        return $this->returnCode === '00';
    }
}
