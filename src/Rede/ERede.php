<?php

declare(strict_types=1);

namespace RedeOAuth;

use GuzzleHttp\Psr7\Request;
use RedeOAuth\Http\AuthenticatedHttpClient;
use RedeOAuth\Http\HttpClientInterface;
use RedeOAuth\Http\HttpException;
use RedeOAuth\OAuth\OAuthClient;

/**
 * Cliente principal do SDK eRede
 */
class ERede
{
    private Store $store;
    private HttpClientInterface $httpClient;

    public function __construct(Store $store, ?HttpClientInterface $httpClient = null)
    {
        $this->store = $store;

        if ($httpClient === null) {
            $oauthClient = $store->getOAuthClient();
            if ($oauthClient === null) {
                $tokenEndpoint = $store->getEnvironment()->getApiUrl() . '/oauth2/token';
                $oauthClient = new OAuthClient($tokenEndpoint);
            }
            $httpClient = new AuthenticatedHttpClient($store, $oauthClient);
        }

        $this->httpClient = $httpClient;
    }

    /**
     * Cria uma nova transação
     *
     * @param Transaction $transaction
     * @return TransactionResponse
     * @throws HttpException
     */
    public function create(Transaction $transaction): TransactionResponse
    {
        try {
            $url = $this->store->getEnvironment()->getApiUrl() . '/v2/transactions';
            $body = json_encode($transaction->toArray());
            $request = new Request('POST', $url, [], $body);
            $response = $this->httpClient->send($request);
            $responseData = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
                throw $this->buildHttpException(
                    $responseData,
                    $response->getStatusCode()
                );
            }
            return new TransactionResponse($responseData);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao criar transação: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Captura uma transação
     *
     * @param Transaction $transaction
     * @return TransactionResponse
     * @throws HttpException
     */
    public function capture(Transaction $transaction): TransactionResponse
    {
        try {
            if ($transaction->getTid() === null) {
                throw new HttpException('TID é obrigatório para captura');
            }

            $url = $this->store->getEnvironment()->getApiUrl() . '/v2/transactions/' . $transaction->getTid();

            $body = json_encode([
                'amount' => $transaction->getAmount(),
            ]);

            $request = new Request('PUT', $url, [], $body);
            $response = $this->httpClient->send($request);

            $responseData = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw $this->buildHttpException(
                    $responseData,
                    $response->getStatusCode(),
                    'Erro ao capturar transação: '
                );
            }

            return new TransactionResponse($responseData);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao capturar transação: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Cancela uma transação
     *
     * @param Transaction $transaction
     * @return TransactionResponse
     * @throws HttpException
     */
    public function cancel(Transaction $transaction): TransactionResponse
    {
        try {
            if ($transaction->getTid() === null) {
                throw new HttpException('TID é obrigatório para cancelamento');
            }

            $baseUrl = $this->store->getEnvironment()->getApiUrl();
            $url = $baseUrl . '/v2/transactions/' . $transaction->getTid() . '/refunds';

            $bodyData = [];
            if ($transaction->getAmount() !== null && $transaction->getAmount() > 0) {
                $bodyData['amount'] = $transaction->getAmount();
            }

            $body = json_encode($bodyData);

            $request = new Request('POST', $url, [], $body);
            $response = $this->httpClient->send($request);
            $responseData = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw $this->buildHttpException(
                    $responseData,
                    $response->getStatusCode(),
                    'Erro ao cancelar transação: '
                );
            }

            return new TransactionResponse($responseData);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao cancelar transação: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Consulta uma transação pelo TID
     *
     * @param string $tid
     * @return TransactionResponse
     * @throws HttpException
     */
    public function get(string $tid): TransactionResponse
    {
        try {
            $url = $this->store->getEnvironment()->getApiUrl() . '/v2/transactions/' . $tid;

            $request = new Request('GET', $url);
            $response = $this->httpClient->send($request);
            $responseData = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw $this->buildHttpException(
                    $responseData,
                    $response->getStatusCode(),
                    'Erro ao consultar transação: '
                );
            }

            return new TransactionResponse($responseData);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao consultar transação: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Consulta uma transação pela referência
     *
     * @param string $reference
     * @return TransactionResponse
     * @throws HttpException
     */
    public function getByReference(string $reference): TransactionResponse
    {
        try {
            $url = $this->store->getEnvironment()->getApiUrl() . '/v2/transactions?reference=' . urlencode($reference);

            $request = new Request('GET', $url);
            $response = $this->httpClient->send($request);

            $responseData = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw $this->buildHttpException(
                    $responseData,
                    $response->getStatusCode(),
                    'Erro ao consultar transação: '
                );
            }

            if (isset($responseData[0])) {
                $responseData = $responseData[0];
            }

            return new TransactionResponse($responseData);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao consultar transação por referência: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Consulta cancelamentos de uma transação
     *
     * @param string $tid
     * @return TransactionResponse
     * @throws HttpException
     */
    public function getRefunds(string $tid): TransactionResponse
    {
        try {
            $url = $this->store->getEnvironment()->getApiUrl() . '/v2/transactions/' . $tid . '/refunds';

            $request = new Request('GET', $url);
            $response = $this->httpClient->send($request);

            $responseData = json_decode((string) $response->getBody(), true);

            if ($response->getStatusCode() !== 200) {
                throw $this->buildHttpException(
                    $responseData,
                    $response->getStatusCode(),
                    'Erro ao consultar cancelamentos: '
                );
            }

            return new TransactionResponse($responseData);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new HttpException('Erro ao consultar cancelamentos: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed>|null $responseData
     */
    private function buildHttpException(
        ?array $responseData,
        int $statusCode,
        string $prefix = ''
    ): HttpException {
        $errorMessage = $responseData['message'] ?? $responseData['returnMessage'] ?? 'Erro desconhecido';
        $errorCode = isset($responseData['returnCode']) ? (string) $responseData['returnCode'] : null;

        $message = $prefix;
        if ($errorCode !== null) {
            $message .= $errorCode . ' - ';
        }
        $message .= $errorMessage;

        return new HttpException(
            $message,
            $statusCode,
            null,
            $errorCode,
            $errorMessage,
            $responseData
        );
    }
}
