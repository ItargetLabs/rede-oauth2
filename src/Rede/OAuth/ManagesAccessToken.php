<?php

declare(strict_types=1);

namespace RedeOAuth\OAuth;

use RedeOAuth\Http\AuthenticatedHttpClient;
use RedeOAuth\Http\HttpClientInterface;
use RedeOAuth\Http\HttpException;

/**
 * Expõe get/set do access token OAuth nos clientes do SDK.
 *
 * Permite persistir o token gerado (cache, sessão, etc.) e reutilizá-lo
 * em novas instâncias, evitando solicitar um novo token a cada request.
 *
 * Classes que usam este trait devem ter a propriedade `$httpClient`.
 *
 * @property HttpClientInterface $httpClient
 */
trait ManagesAccessToken
{
    /**
     * Define um access token já obtido (ex.: recuperado do cache).
     * Se o token estiver expirado, um novo será solicitado na próxima requisição.
     */
    public function setAccessToken(?Token $token): static
    {
        if (!$this->httpClient instanceof AuthenticatedHttpClient) {
            throw new \LogicException(
                'setAccessToken() só está disponível quando o cliente HTTP é AuthenticatedHttpClient'
            );
        }

        $this->httpClient->setAccessToken($token);

        return $this;
    }

    /**
     * Retorna o access token atual, gerando um novo se necessário.
     *
     * @throws HttpException
     */
    public function getAccessToken(): Token
    {
        if (!$this->httpClient instanceof AuthenticatedHttpClient) {
            throw new \LogicException(
                'getAccessToken() só está disponível quando o cliente HTTP é AuthenticatedHttpClient'
            );
        }

        return $this->httpClient->getAccessToken();
    }
}
