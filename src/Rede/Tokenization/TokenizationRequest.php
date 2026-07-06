<?php

declare(strict_types=1);

namespace RedeOAuth\Tokenization;

use RedeOAuth\SensitiveDataSanitizer;

/**
 * Representa a requisição de tokenização de cartão pela bandeira via Rede.
 *
 * Documentação: POST /token-service/v1/tokenization
 */
class TokenizationRequest
{
    private string $cardNumber;
    private string $expirationMonth;
    private string $expirationYear;
    private string $email;
    private int $storageCard;
    private ?string $cardholderName;
    private ?string $securityCode;

    /**
     * @param string      $cardNumber       Número do cartão (até 19 dígitos)
     * @param string      $expirationMonth  Mês de expiração do cartão (entre "01" e "12")
     * @param string      $expirationYear   Ano de expiração do cartão (4 dígitos, ex: "2028")
     * @param string      $email            E-mail do portador do cartão (até 200 caracteres)
     * @param int         $storageCard      0 = credencial não armazenada, 2 = credencial já armazenada
     * @param string|null $cardholderName   Nome do portador impresso no cartão (até 200 caracteres)
     * @param string|null $securityCode     Código de segurança do cartão (até 4 caracteres)
     */
    public function __construct(
        string $cardNumber,
        string $expirationMonth,
        string $expirationYear,
        string $email,
        int $storageCard = 0,
        ?string $cardholderName = null,
        ?string $securityCode = null
    ) {
        $this->cardNumber = $cardNumber;
        $this->expirationMonth = $expirationMonth;
        $this->expirationYear = $expirationYear;
        $this->email = $email;
        $this->storageCard = $storageCard;
        $this->cardholderName = $cardholderName;
        $this->securityCode = $securityCode;
    }

    public function getCardNumber(): string
    {
        return $this->cardNumber;
    }

    public function getExpirationMonth(): string
    {
        return $this->expirationMonth;
    }

    public function getExpirationYear(): string
    {
        return $this->expirationYear;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getStorageCard(): int
    {
        return $this->storageCard;
    }

    public function getCardholderName(): ?string
    {
        return $this->cardholderName;
    }

    public function getSecurityCode(): ?string
    {
        return $this->securityCode;
    }

    public function toArray(): array
    {
        $data = [
            'cardNumber'      => $this->cardNumber,
            'expirationMonth' => $this->expirationMonth,
            'expirationYear'  => $this->expirationYear,
            'email'           => $this->email,
            'storageCard'     => $this->storageCard,
        ];

        if ($this->cardholderName !== null) {
            $data['cardholderName'] = $this->cardholderName;
        }

        if ($this->securityCode !== null) {
            $data['securityCode'] = $this->securityCode;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return SensitiveDataSanitizer::sanitize($this->toArray());
    }
}
