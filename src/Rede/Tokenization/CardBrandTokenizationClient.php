<?php

declare(strict_types=1);

namespace RedeOAuth\Tokenization;

use GuzzleHttp\Psr7\Request;
use RedeOAuth\Http\AuthenticatedHttpClient;
use RedeOAuth\Http\HttpClientInterface;
use RedeOAuth\Http\HttpException;
use RedeOAuth\OAuth\OAuthClient;
use RedeOAuth\Store;

/**
 * Cliente para o serviço de Tokenização de Bandeira Rede (Rede Card Brand Tokenization).
 *
 * Endpoints base:
 *   Sandbox:    https://rl7-sandbox-api.useredecloud.com.br
 *   Produção:   https://api.userede.com.br/redelabs
 *
 * Operações disponíveis:
 *   - createTokenization : POST /token-service/oauth/v2/tokenization
 *   - getToken           : GET  /token-service/oauth/v2/tokenization/{tokenizationId}
 *   - getCryptogram      : POST /token-service/oauth/v2/cryptogram/{tokenizationId}
 *   - manageToken        : PUT  /token-service/oauth/v2/tokenization/{tokenizationId}
 *   - setWebhookUrl      : POST /token-service/oauth/v2/tokenization/seturl  (apenas sandbox)
 */
class CardBrandTokenizationClient
{
    public const STATUS_DELETED   = 'deleted';
    public const STATUS_SUSPENDED = 'suspend';
    public const STATUS_RESUMED   = 'resume';

    public const REASON_CUSTOMER_REQUEST = 1;
    public const REASON_SUSPECTED_FRAUD  = 2;

    private Store $store;
    private HttpClientInterface $httpClient;

    public function __construct(Store $store, ?HttpClientInterface $httpClient = null)
    {
        $this->store = $store;

        if ($httpClient === null) {
            $oauthClient = $store->getOAuthClient();
            if ($oauthClient === null) {
                $tokenEndpoint = $store->getEnvironment()->getTokenServiceBaseUrl() . '/oauth2/token';
                $oauthClient   = new OAuthClient($tokenEndpoint);
            }
            $httpClient = new AuthenticatedHttpClient($store, $oauthClient);
        }

        $this->httpClient = $httpClient;
    }

    /**
     * Envia a requisição de tokenização do cartão à bandeira via Rede.
     *
     * Após obter o tokenizationId, aguarde o evento de webhook (assíncrono) para confirmar
     * a criação do token e então consulte com getToken().
     *
     * @throws HttpException
     */
    public function createTokenization(TokenizationRequest $request): TokenizationResponse
    {
        try {
            $url     = $this->buildUrl('/token-service/oauth/v2/tokenization');
            $payload = array_merge(
                ['filiation' => $this->store->getFiliation()],
                $request->toArray()
            );
            $body = json_encode($payload);

            $httpRequest = new Request('POST', $url, [], $body);
            $response    = $this->httpClient->send($httpRequest);
            $data        = $this->decodeResponse($response);

            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
                throw new HttpException(
                    ($data['returnCode'] ?? '') . ' - ' . ($data['returnMessage'] ?? 'Erro desconhecido'),
                    $response->getStatusCode()
                );
            }

            return new TokenizationResponse($data);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao criar tokenização: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Consulta o status e os dados de uma requisição de tokenização pelo tokenizationId.
     *
     * @param string $tokenizationId UUID retornado em createTokenization()
     * @throws HttpException
     */
    public function getToken(string $tokenizationId): TokenQueryResponse
    {
        try {
            $url = $this->buildUrl(
                '/token-service/oauth/v2/tokenization/' . urlencode($tokenizationId),
                ['filiation' => $this->store->getFiliation()]
            );

            $httpRequest = new Request('GET', $url);
            $response    = $this->httpClient->send($httpRequest);
            $data        = $this->decodeResponse($response);

            if ($response->getStatusCode() !== 200) {
                throw new HttpException(
                    ($data['returnCode'] ?? '') . ' - ' . ($data['returnMessage'] ?? 'Erro desconhecido'),
                    $response->getStatusCode()
                );
            }

            return new TokenQueryResponse($data);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao consultar token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Solicita o criptograma para realizar uma transação com o token de bandeira.
     *
     * Gere um criptograma por transação; não armazene nem reutilize.
     *
     * @param string $tokenizationId UUID do token obtido via getToken()
     * @param bool   $subscription   true se a transação for uma recorrência
     * @throws HttpException
     */
    public function getCryptogram(string $tokenizationId, bool $subscription = false): CryptogramResponse
    {
        try {
            $url  = $this->buildUrl('/token-service/oauth/v2/cryptogram/' . urlencode($tokenizationId));
            $body = json_encode([
                'filiation'    => $this->store->getFiliation(),
                'subscription' => $subscription,
            ]);

            $httpRequest = new Request('POST', $url, [], $body);
            $response    = $this->httpClient->send($httpRequest);
            $data        = $this->decodeResponse($response);

            if ($response->getStatusCode() !== 200) {
                throw new HttpException(
                    ($data['returnCode'] ?? '') . ' - ' . ($data['returnMessage'] ?? 'Erro desconhecido'),
                    $response->getStatusCode()
                );
            }

            return new CryptogramResponse($data);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao solicitar criptograma: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Gerencia o ciclo de vida do token: deletar, suspender ou reativar.
     *
     * @param string $tokenizationId   UUID do token
     * @param string $tokenizationStatus  Use as constantes STATUS_DELETED, STATUS_SUSPENDED ou STATUS_RESUMED
     * @param int    $reason           Use as constantes REASON_CUSTOMER_REQUEST ou REASON_SUSPECTED_FRAUD
     * @throws HttpException
     */
    public function manageToken(
        string $tokenizationId,
        string $tokenizationStatus,
        int $reason
    ): TokenManagementResponse {
        try {
            $url  = $this->buildUrl('/token-service/oauth/v2/tokenization/' . urlencode($tokenizationId));
            $body = json_encode([
                'filiation'          => $this->store->getFiliation(),
                'tokenizationStatus' => $tokenizationStatus,
                'reason'             => $reason,
            ]);

            $httpRequest = new Request('PUT', $url, [], $body);
            $response    = $this->httpClient->send($httpRequest);
            $data        = $this->decodeResponse($response);

            if ($response->getStatusCode() !== 200) {
                throw new HttpException(
                    ($data['returnCode'] ?? '') . ' - ' . ($data['returnMessage'] ?? 'Erro desconhecido'),
                    $response->getStatusCode()
                );
            }

            return new TokenManagementResponse($data);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao gerenciar token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Registra ou atualiza a URL de webhook para receber eventos do ciclo de vida do token.
     *
     * Em produção, o registro é feito pelo Portal Logado da Rede; use este método apenas em sandbox.
     *
     * @param string      $url           URL de callback (até 256 caracteres)
     * @param string|null $authType      Tipo de autenticação da URL: "Bearer" ou "Basic" (opcional)
     * @param string|null $authToken     Token de autenticação no formato "Bearer XXX" ou "Basic XXX" (opcional)
     * @return array{returnCode: string, returnMessage: string}
     * @throws HttpException
     */
    public function setWebhookUrl(string $url, ?string $authType = null, ?string $authToken = null): array
    {
        try {
            $payload = [
                'filiation' => $this->store->getFiliation(),
                'url'       => $url,
            ];

            if ($authType !== null && $authToken !== null) {
                $payload['authorization'] = [
                    'type'  => $authType,
                    'token' => $authToken,
                ];
            }

            $endpoint    = $this->buildUrl('/token-service/oauth/v2/tokenization/seturl');
            $body        = json_encode($payload);
            $httpRequest = new Request('POST', $endpoint, [], $body);
            $response    = $this->httpClient->send($httpRequest);

            $rawBody = (string) $response->getBody();
            $status  = $response->getStatusCode();

            // O endpoint seturl pode retornar corpo vazio em caso de sucesso ou erro de autorização.
            if ($rawBody === '' || $rawBody === null) {
                if ($status === 200 || $status === 201 || $status === 204) {
                    return ['returnCode' => '00', 'returnMessage' => 'Success'];
                }
                throw new HttpException(
                    'Webhook URL não registrada (HTTP ' . $status . '). ' .
                    'Verifique se este estabelecimento tem permissão para configurar webhooks no sandbox.',
                    $status
                );
            }

            $data = json_decode($rawBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new HttpException(
                    'Resposta inválida do servidor de webhook: ' . json_last_error_msg(),
                    $status
                );
            }

            if ($status !== 200 && $status !== 201) {
                throw new HttpException(
                    ($data['returnCode'] ?? '') . ' - ' . ($data['returnMessage'] ?? 'Erro desconhecido'),
                    $status
                );
            }

            return $data;
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao registrar URL de webhook: ' . $e->getMessage(), 0, $e);
        }
    }

    private function buildUrl(string $path, array $queryParams = []): string
    {
        $url = $this->store->getEnvironment()->getTokenServiceBaseUrl() . $path;

        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url;
    }

    private function decodeResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException('Resposta inválida do servidor: ' . json_last_error_msg());
        }

        return $data ?? [];
    }
}
