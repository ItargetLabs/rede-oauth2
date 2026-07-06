<?php

declare(strict_types=1);

namespace RedeOAuth\Tokenization;

/**
 * Resposta da requisição de criptograma (POST /token-service/v1/cryptogram/{tokenizationId}).
 *
 * O criptograma é por transação: use-o uma única vez e solicite um novo a cada transação.
 */
class CryptogramResponse
{
    private string $returnCode;
    private string $returnMessage;
    private ?string $tokenizationId;
    private ?string $tokenCryptogram;
    private ?string $eci;
    private ?string $expirationDate;

    public function __construct(array $data)
    {
        $this->returnCode      = $data['returnCode'] ?? '';
        $this->returnMessage   = $data['returnMessage'] ?? '';
        $this->tokenizationId  = $data['tokenizationId'] ?? null;
        $this->tokenCryptogram = $data['cryptogramInfo']['tokenCryptogram'] ?? null;
        $this->eci             = $data['cryptogramInfo']['eci'] ?? null;
        $this->expirationDate  = $data['cryptogramInfo']['expirationDate'] ?? null;
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

    /**
     * Criptograma do token (Base64, até 28 caracteres). Envie no campo tokenCryptogram da transação.
     */
    public function getTokenCryptogram(): ?string
    {
        return $this->tokenCryptogram;
    }

    /**
     * ECI retornado pela bandeira (resultado da autenticação do portador com o emissor).
     * Use este valor no campo securityAuthentication.sai da transação Visa/ELO.
     */
    public function getEci(): ?string
    {
        return $this->eci;
    }

    /**
     * Data de expiração do criptograma (formato AAAA-MM-DDThh:mm:ss.sssZ).
     * Pode não ser retornado por todas as bandeiras.
     */
    public function getExpirationDate(): ?string
    {
        return $this->expirationDate;
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
            'eci' => $this->eci,
            'expirationDate' => $this->expirationDate,
            'tokenCryptogram' => $this->tokenCryptogram !== null ? '[REDACTED]' : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
