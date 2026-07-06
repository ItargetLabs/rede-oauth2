<?php

declare(strict_types=1);

namespace RedeOAuth;

use RedeOAuth\Http\HttpException;

/**
 * Agrega dados seguros de uma transação (request, response, autenticação 3DS, erros e bandeira).
 *
 * Use para logging, auditoria e exibição sem expor dados sensíveis do cartão.
 */
class TransactionDetails
{
    /**
     * @param array<string, mixed>      $request
     * @param array<string, mixed>|null $response
     * @param array<string, mixed>|null $authentication
     */
    public function __construct(
        private array $request,
        private ?array $response = null,
        private ?array $authentication = null,
    ) {
    }

    /**
     * Cria a partir de uma transação enviada e sua resposta (quando disponível).
     */
    public static function fromTransaction(Transaction $request, ?TransactionResponse $response = null): self
    {
        $safeRequest = $request->toSafeArray();
        $safeResponse = $response?->toSafeArray();
        $authentication = self::extractAuthentication($safeRequest, $safeResponse);

        return new self($safeRequest, $safeResponse, $authentication);
    }

    /**
     * Cria apenas com os dados do request (antes ou sem resposta da API).
     */
    public static function fromRequest(Transaction $request): self
    {
        $safeRequest = $request->toSafeArray();

        return new self($safeRequest, null, self::extractAuthentication($safeRequest, null));
    }

    /**
     * Cria a partir de uma exceção HTTP com corpo de resposta estruturado.
     *
     * @param array<string, mixed>|null $requestData
     */
    public static function fromException(HttpException $exception, ?array $requestData = null): self
    {
        $response = $exception->getResponseBody();

        if ($response !== null) {
            $response = SensitiveDataSanitizer::sanitize($response);
        } else {
            $response = [
                'returnCode' => $exception->getReturnCode(),
                'returnMessage' => $exception->getReturnMessage() ?? $exception->getMessage(),
                'httpStatusCode' => $exception->getCode(),
            ];
        }

        return new self(
            $requestData ?? [],
            $response,
            isset($response['threeDSecure']) ? $response['threeDSecure'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'request' => $this->request,
            'response' => $this->response,
        ];

        if ($this->authentication !== null) {
            $data['authentication'] = $this->authentication;
        }

        $data['returnCode'] = $this->getReturnCode();
        $data['returnMessage'] = $this->getReturnMessage();
        $data['brand'] = $this->getBrand();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequest(): array
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponse(): ?array
    {
        return $this->response;
    }

    /**
     * Dados de autenticação 3DS (request + response quando disponível).
     *
     * @return array<string, mixed>|null
     */
    public function getAuthentication(): ?array
    {
        return $this->authentication;
    }

    public function getReturnCode(): ?string
    {
        if ($this->response === null) {
            return null;
        }

        return $this->response['returnCode'] ?? $this->response['authorization']['returnCode'] ?? null;
    }

    public function getReturnMessage(): ?string
    {
        if ($this->response === null) {
            return null;
        }

        return $this->response['returnMessage'] ?? $this->response['authorization']['returnMessage'] ?? null;
    }

    /**
     * @return array{name: ?string, message: ?string}
     */
    public function getBrand(): array
    {
        $name = $this->response['brand']['name'] ?? $this->request['brand']['name'] ?? null;
        $message = $this->response['brand']['message'] ?? $this->request['brand']['message'] ?? null;

        if ($name === null) {
            $bin = $this->response['cardBin'] ?? $this->request['cardBin'] ?? null;
            $name = SensitiveDataSanitizer::detectBrand($bin);
        }

        return [
            'name' => $name,
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed>      $request
     * @param array<string, mixed>|null $response
     * @return array<string, mixed>|null
     */
    private static function extractAuthentication(array $request, ?array $response): ?array
    {
        $hasRequestAuth = isset($request['threeDSecure']) || isset($request['urls']);
        $hasResponseAuth = $response !== null && isset($response['threeDSecure']);

        if (!$hasRequestAuth && !$hasResponseAuth) {
            return null;
        }

        $authentication = [];

        if (isset($request['threeDSecure'])) {
            $authentication['request'] = [
                'threeDSecure' => $request['threeDSecure'],
                'urls' => $request['urls'] ?? null,
            ];
        }

        if ($hasResponseAuth) {
            $authentication['response'] = $response['threeDSecure'];
        }

        return $authentication;
    }
}
