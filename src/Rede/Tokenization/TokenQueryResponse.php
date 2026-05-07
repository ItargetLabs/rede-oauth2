<?php

declare(strict_types=1);

namespace RedeOAuth\Tokenization;

/**
 * Resposta da consulta de status de tokenização (GET /token-service/v1/tokenization/{tokenizationId}).
 *
 * tokenizationStatus possíveis: Pending | Active | Inactive | Suspended | Deleted | Failed
 */
class TokenQueryResponse
{
    private string $returnCode;
    private string $returnMessage;
    private ?string $tokenizationId;
    private ?string $tokenizationStatus;
    private ?string $affiliation;
    private ?string $brandName;
    private ?string $brandMessage;
    private ?string $lastModifiedDate;
    private ?string $last4;
    private ?string $tokenCode;
    private ?string $tokenExpirationDate;

    public function __construct(array $data)
    {
        $this->returnCode          = $data['returnCode'] ?? '';
        $this->returnMessage       = $data['returnMessage'] ?? '';
        $this->tokenizationId      = $data['tokenizationId'] ?? null;
        $this->tokenizationStatus  = $data['tokenizationStatus'] ?? null;
        $this->affiliation         = isset($data['affiliation']) ? (string) $data['affiliation'] : null;
        $this->brandName           = $data['brand']['name'] ?? null;
        $this->brandMessage        = $data['brand']['message'] ?? null;
        $this->lastModifiedDate    = $data['lastModifiedDate'] ?? null;
        $this->last4               = $data['last4'] ?? null;
        $this->tokenCode           = $data['token']['code'] ?? null;
        $this->tokenExpirationDate = $data['token']['expirationDate'] ?? null;
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
     * Status do ciclo de vida do token: Pending, Active, Inactive, Suspended, Deleted ou Failed.
     */
    public function getTokenizationStatus(): ?string
    {
        return $this->tokenizationStatus;
    }

    public function isActive(): bool
    {
        return $this->tokenizationStatus === 'Active';
    }

    public function getAffiliation(): ?string
    {
        return $this->affiliation;
    }

    public function getBrandName(): ?string
    {
        return $this->brandName;
    }

    public function getBrandMessage(): ?string
    {
        return $this->brandMessage;
    }

    public function getLastModifiedDate(): ?string
    {
        return $this->lastModifiedDate;
    }

    public function getLast4(): ?string
    {
        return $this->last4;
    }

    /**
     * Número do token de cartão gerado pela bandeira (descriptografado).
     */
    public function getTokenCode(): ?string
    {
        return $this->tokenCode;
    }

    /**
     * Data de expiração do token gerado pela bandeira (formato MM/YYYY).
     */
    public function getTokenExpirationDate(): ?string
    {
        return $this->tokenExpirationDate;
    }

    public function isSuccess(): bool
    {
        return $this->returnCode === '00';
    }
}
