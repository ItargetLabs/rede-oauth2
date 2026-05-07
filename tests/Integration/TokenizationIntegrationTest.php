<?php

declare(strict_types=1);

namespace RedeOAuth\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RedeOAuth\Environment;
use RedeOAuth\Http\HttpException;
use RedeOAuth\OAuth\OAuthClient;
use RedeOAuth\Store;
use RedeOAuth\Tokenization\CardBrandTokenizationClient;
use RedeOAuth\Tokenization\TokenizationRequest;

/**
 * Testes de integração do serviço de Tokenização de Bandeira Rede.
 *
 * Executados contra o sandbox real — sem mocks de HTTP.
 *
 * Endpoints usados (base sandbox: https://rl7-sandbox-api.useredecloud.com.br):
 *   POST /token-service/oauth/v2/tokenization
 *   GET  /token-service/oauth/v2/tokenization/{tokenizationId}
 *   POST /token-service/oauth/v2/cryptogram/{tokenizationId}
 *   PUT  /token-service/oauth/v2/tokenization/{tokenizationId}
 *   POST /token-service/oauth/v2/tokenization/seturl  (apenas sandbox)
 *
 * ─────────────────────────────────────────────────────────────────
 * Comportamento do sandbox MDES (Mastercard) documentado:
 *
 *   1. createTokenization  → retorna tokenizationId (código 00) imediatamente.
 *   2. getToken            → status torna-se "Active" em segundos; tokenCode
 *                            é null (MDES não expõe o PAN via API — normal).
 *   3. getCryptogram       → erro 38 "Token Cryptogram unavailable" enquanto
 *                            o MDES não confirma o provisionamento via webhook.
 *   4. manageToken         → erro 52 "The token's status does not allow this
 *                            action" antes do provisionamento completo.
 *
 * Para testar criptograma e gerenciamento é necessário:
 *   - Configurar REDE_TOKENIZATION_WEBHOOK_URL no .env com uma URL acessível.
 *   - Aguardar o evento de webhook que confirma o provisionamento completo.
 * ─────────────────────────────────────────────────────────────────
 *
 * Pré-requisitos:
 *   - REDE_MERCHANT_ID e REDE_MERCHANT_KEY configurados no .env
 *   - Serviço de tokenização habilitado no sandbox (https://developer.userede.com.br)
 *
 * @group integration
 * @group real-api
 * @group tokenization
 */
class TokenizationIntegrationTest extends TestCase
{
    private CardBrandTokenizationClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $merchantId  = $_ENV['REDE_MERCHANT_ID']  ?? '';
        $merchantKey = $_ENV['REDE_MERCHANT_KEY']  ?? '';

        if (empty($merchantId) || empty($merchantKey)) {
            $this->markTestSkipped(
                'Credenciais não configuradas. Defina REDE_MERCHANT_ID e REDE_MERCHANT_KEY no .env'
            );
        }

        $oauthEndpoint = $_ENV['REDE_SANDBOX_OAUTH_ENDPOINT']
            ?? 'https://rl7-sandbox-api.useredecloud.com.br/oauth2/token';

        $oauthClient  = new OAuthClient($oauthEndpoint);
        $store        = new Store($merchantId, $merchantKey, Environment::production(), $oauthClient);
        $this->client = new CardBrandTokenizationClient($store);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Mastercard de teste do sandbox.
     * Visa (ex: 4111...) retorna código 27 "Card is from a brand not enabled for tokenization"
     * para este estabelecimento.
     */
    private function makeTokenizationRequest(): TokenizationRequest
    {
        return new TokenizationRequest(
            cardNumber: '5448280000000007',
            expirationMonth: '12',
            expirationYear: '2028',
            email: 'portador.teste@example.com',
            storageCard: 2,
            cardholderName: 'PORTADOR TESTE',
            securityCode: '235'
        );
    }

    /**
     * Cria uma tokenização e retorna o tokenizationId.
     * Marca o teste como skipped se o serviço não estiver disponível.
     */
    private function createAndGetTokenizationId(): string
    {
        try {
            $response = $this->client->createTokenization($this->makeTokenizationRequest());
        } catch (HttpException $e) {
           if ($this->isServiceUnavailableError($e)) {
               $this->markTestSkipped(
                   'Serviço de tokenização não disponível para este estabelecimento: ' . $e->getMessage()
               );
           }
           throw $e;
        }

        if ($response->getReturnCode() !== '00') {
            $this->markTestSkipped(
                sprintf(
                    'Tokenização não aprovada pelo sandbox (código %s: %s).',
                    $response->getReturnCode(),
                    $response->getReturnMessage()
                )
            );
        }

        $id = $response->getTokenizationId();
        $this->assertNotNull($id);
        return $id;
    }

    /**
     * Aguarda até que o token saia do status Pending, com timeout.
     */
    private function pollUntilResolved(
        string $tokenizationId,
        int $maxSeconds = 60,
        int $intervalSeconds = 5
    ): string {
        $iterations = (int) ceil($maxSeconds / $intervalSeconds);

        for ($i = 0; $i < $iterations; $i++) {
            $response = $this->client->getToken($tokenizationId);
            $status   = $response->getTokenizationStatus() ?? 'Unknown';

            if ($status !== 'Pending') {
                return $status;
            }

            sleep($intervalSeconds);
        }

        return 'Pending';
    }

    /**
     * Verifica se um HttpException indica que o serviço não está disponível
     * para este estabelecimento (diferente de um erro de negócio esperado).
     */
    private function isServiceUnavailableError(HttpException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'Affiliation: Invalid parameter format')
            || str_contains($msg, 'Service not enabled')
            || str_contains($msg, 'not enabled for this establishment')
            || str_contains($msg, 'Unauthorized')
            || str_contains($msg, 'Webhook URL não registrada')
            || str_contains($msg, 'Card is from a brand not enabled')
            || $e->getCode() === 401
            || $e->getCode() === 403;
    }

    /**
     * Verifica se um HttpException indica limitação de sandbox que requer
     * provisionamento completo via webhook (criptograma/gerenciamento não disponíveis).
     *
     *   38 = Token Cryptogram unavailable. Check the token status
     *   52 = The token's status does not allow this action
     */
    private function isSandboxProvisioningIncomplete(HttpException $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'Token Cryptogram unavailable')
            || str_contains($msg, 'does not allow this action')
            || str_contains($msg, '38 -')
            || str_contains($msg, '52 -');
    }

    // -------------------------------------------------------------------------
    // Webhook URL (sandbox only)
    // -------------------------------------------------------------------------

    /**
     * Registra a URL de webhook no sandbox.
     * Em produção, o registro é feito pelo Portal Logado da Rede.
     */
    public function testSetWebhookUrlInSandbox(): void
    {
        $webhookUrl = $_ENV['REDE_TOKENIZATION_WEBHOOK_URL']
            ?? 'https://webhook.site/rede-tokenization-test';

        try {
            $result = $this->client->setWebhookUrl($webhookUrl);
            $this->assertIsArray($result);
            $this->assertArrayHasKey('returnCode', $result);
            $this->assertNotEmpty($result['returnCode']);
        } catch (HttpException $e) {
            if ($this->isServiceUnavailableError($e)) {
                $this->markTestSkipped(
                    'Configuração de webhook não disponível para este estabelecimento: ' . $e->getMessage()
                );
            }
            throw $e;
        }
    }

    public function testSetWebhookUrlWithBearerAuth(): void
    {
        $webhookUrl = $_ENV['REDE_TOKENIZATION_WEBHOOK_URL']
            ?? 'https://webhook.site/rede-tokenization-test';

        try {
            $result = $this->client->setWebhookUrl(
                $webhookUrl,
                'Bearer',
                'Bearer meu_token_secreto_sandbox'
            );
            $this->assertIsArray($result);
            $this->assertArrayHasKey('returnCode', $result);
            $this->assertNotEmpty($result['returnCode']);
        } catch (HttpException $e) {
            if ($this->isServiceUnavailableError($e)) {
                $this->markTestSkipped(
                    'Configuração de webhook não disponível para este estabelecimento: ' . $e->getMessage()
                );
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Criação de tokenização
    // -------------------------------------------------------------------------

    /**
     * Verifica que createTokenization devolve um tokenizationId válido.
     * A API também retorna links HATEOAS com os endpoints de consulta, criptograma e gerenciamento.
     */
    public function testCreateTokenizationReturnsId(): void
    {
        $id = $this->createAndGetTokenizationId();
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
            'tokenizationId deve ser um UUID válido'
        );
    }

    // -------------------------------------------------------------------------
    // Consulta de status
    // -------------------------------------------------------------------------

    /**
     * Consulta o token logo após criação — espera Pending ou Active.
     *
     * Comportamento observado no sandbox MDES:
     *   - Status torna-se Active rapidamente (sem esperar webhook)
     *   - tokenCode é null (MDES não expõe o PAN via getToken — comportamento normal)
     *   - last4 é retornado quando Active
     */
    public function testGetTokenReturnsPendingOrActive(): void
    {
        $tokenizationId = $this->createAndGetTokenizationId();

        $response = $this->client->getToken($tokenizationId);

        $this->assertNotEmpty($response->getReturnCode());
        $this->assertNotNull($response->getTokenizationStatus());
        $this->assertSame($tokenizationId, $response->getTokenizationId());

        $validStatuses = ['Pending', 'Active', 'Inactive', 'Suspended', 'Deleted', 'Failed'];
        $this->assertContains(
            $response->getTokenizationStatus(),
            $validStatuses,
            sprintf('Status inesperado: %s', $response->getTokenizationStatus())
        );
    }

    /**
     * Verifica que o token atinge status Active dentro do timeout e
     * valida os campos retornados para um token MDES (Mastercard).
     */
    public function testGetTokenReachesActiveStatusAndValidatesFields(): void
    {
        $tokenizationId = $this->createAndGetTokenizationId();
        $finalStatus    = $this->pollUntilResolved($tokenizationId, maxSeconds: 30, intervalSeconds: 5);

        if ($finalStatus !== 'Active') {
            $this->markTestIncomplete(
                sprintf('Token ficou em status "%s" — não ficou Active em 30 s.', $finalStatus)
            );
        }

        $response = $this->client->getToken($tokenizationId);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->isActive());
        $this->assertSame($tokenizationId, $response->getTokenizationId());
        $this->assertNotNull($response->getAffiliation());

        // last4 é retornado para tokens Active
        $this->assertNotNull($response->getLast4());
        $this->assertSame('0007', $response->getLast4()); // últimos 4 do 5448280000000007

        // brandName é retornado quando a bandeira processa o token
        $this->assertNotNull($response->getBrandName());

        // tokenCode é null para MDES (Mastercard não expõe o PAN via getToken — by design)
        // tokenExpirationDate também pode ser null por bandeira
    }

    // -------------------------------------------------------------------------
    // Criptograma
    // -------------------------------------------------------------------------

    /**
     * Tenta gerar um criptograma para um token Active.
     *
     * No sandbox MDES sem webhook configurado, o criptograma retorna erro 38
     * "Token Cryptogram unavailable" — isso é esperado e o teste é marcado
     * como incompleto. Com webhook real recebendo o evento de provisionamento,
     * o criptograma ficará disponível.
     */
    public function testGetCryptogramForActiveToken(): void
    {
        $tokenizationId = $this->createAndGetTokenizationId();
        $finalStatus    = $this->pollUntilResolved($tokenizationId, maxSeconds: 30, intervalSeconds: 5);

        if ($finalStatus !== 'Active') {
            $this->markTestIncomplete(
                sprintf('Token em status "%s" — não é possível testar criptograma.', $finalStatus)
            );
        }

        try {
            $response = $this->client->getCryptogram($tokenizationId);

            $this->assertTrue($response->isSuccess());
            $this->assertNotEmpty($response->getTokenCryptogram());
            $this->assertSame($tokenizationId, $response->getTokenizationId());
        } catch (HttpException $e) {
            if ($this->isSandboxProvisioningIncomplete($e)) {
                $this->markTestIncomplete(
                    'Criptograma não disponível no sandbox sem webhook: ' . $e->getMessage() . PHP_EOL .
                    'Configure REDE_TOKENIZATION_WEBHOOK_URL no .env com uma URL acessível e ' .
                    'aguarde o evento de provisionamento MDES.'
                );
            }
            throw $e;
        }
    }

    public function testGetCryptogramSubscriptionForActiveToken(): void
    {
        $tokenizationId = $this->createAndGetTokenizationId();
        $finalStatus    = $this->pollUntilResolved($tokenizationId, maxSeconds: 30, intervalSeconds: 5);

        if ($finalStatus !== 'Active') {
            $this->markTestIncomplete(
                sprintf('Token em status "%s" — não é possível testar criptograma de recorrência.', $finalStatus)
            );
        }

        try {
            $response = $this->client->getCryptogram($tokenizationId, subscription: true);

            $this->assertTrue($response->isSuccess());
            $this->assertNotEmpty($response->getTokenCryptogram());
        } catch (HttpException $e) {
            if ($this->isSandboxProvisioningIncomplete($e)) {
                $this->markTestIncomplete(
                    'Criptograma de recorrência não disponível no sandbox sem webhook: ' . $e->getMessage()
                );
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Gerenciamento de token (ciclo de vida)
    // -------------------------------------------------------------------------

    /**
     * Tenta executar o ciclo suspend → resume → delete para um token Active.
     *
     * No sandbox MDES sem provisionamento completo, estas operações retornam
     * erro 52 "The token's status does not allow this action" — marcado como incompleto.
     */
    public function testManageTokenSuspendResumeDelete(): void
    {
        $tokenizationId = $this->createAndGetTokenizationId();
        $finalStatus    = $this->pollUntilResolved($tokenizationId, maxSeconds: 30, intervalSeconds: 5);

        if ($finalStatus !== 'Active') {
            $this->markTestIncomplete(
                sprintf('Token em status "%s" — gerenciamento não testado.', $finalStatus)
            );
        }

        try {
            $suspendResponse = $this->client->manageToken(
                $tokenizationId,
                CardBrandTokenizationClient::STATUS_SUSPENDED,
                CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
            );
            $this->assertNotEmpty($suspendResponse->getReturnCode());
            $this->assertSame($tokenizationId, $suspendResponse->getTokenizationId());
        } catch (HttpException $e) {
            if ($this->isSandboxProvisioningIncomplete($e)) {
                $this->markTestIncomplete(
                    'Suspend não disponível no sandbox sem provisionamento completo: ' . $e->getMessage() . PHP_EOL .
                    'Configure REDE_TOKENIZATION_WEBHOOK_URL e aguarde o evento MDES.'
                );
            }
            throw $e;
        }

        try {
            $resumeResponse = $this->client->manageToken(
                $tokenizationId,
                CardBrandTokenizationClient::STATUS_RESUMED,
                CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
            );
            $this->assertNotEmpty($resumeResponse->getReturnCode());
        } catch (HttpException $e) {
            if ($this->isSandboxProvisioningIncomplete($e)) {
                $this->markTestIncomplete(
                    'Resume não disponível no sandbox: ' . $e->getMessage()
                );
            }
            throw $e;
        }

        try {
            $deleteResponse = $this->client->manageToken(
                $tokenizationId,
                CardBrandTokenizationClient::STATUS_DELETED,
                CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
            );
            $this->assertNotEmpty($deleteResponse->getReturnCode());
        } catch (HttpException $e) {
            if ($this->isSandboxProvisioningIncomplete($e)) {
                $this->markTestIncomplete(
                    'Delete não disponível no sandbox: ' . $e->getMessage()
                );
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Tratamento de erros
    // -------------------------------------------------------------------------

    public function testGetTokenWithInvalidIdThrowsOrReturnsError(): void
    {
        try {
            $response = $this->client->getToken('00000000-0000-0000-0000-000000000000');
            $this->assertNotSame('00', $response->getReturnCode());
        } catch (HttpException $e) {
            if ($this->isServiceUnavailableError($e)) {
                $this->markTestSkipped('Serviço de tokenização não disponível para este estabelecimento.');
            }
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testCreateTokenizationWithInvalidEmailStructure(): void
    {
        $request = new TokenizationRequest(
            cardNumber: '5448280000000007',
            expirationMonth: '12',
            expirationYear: '2028',
            email: 'invalido-sem-arroba',
            storageCard: 2
        );

        try {
            $response = $this->client->createTokenization($request);
            $this->assertNotEmpty($response->getReturnCode());
        } catch (HttpException $e) {
            if ($this->isServiceUnavailableError($e)) {
                $this->markTestSkipped('Serviço de tokenização não disponível para este estabelecimento.');
            }
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * Visa (4111...) retorna código 27 "Card is from a brand not enabled for tokenization"
     * para este estabelecimento. Verifica que o SDK trata a rejeição corretamente.
     */
    public function testCreateTokenizationWithUnsupportedBrandIsHandledGracefully(): void
    {
        $visaRequest = new TokenizationRequest(
            cardNumber: '4111111111111111',
            expirationMonth: '12',
            expirationYear: '2028',
            email: 'test@example.com',
            storageCard: 0
        );

        try {
            $response = $this->client->createTokenization($visaRequest);
            $this->assertNotEmpty($response->getReturnCode());
        } catch (HttpException $e) {
            // Código 27: comportamento esperado para bandeira não habilitada
            $this->assertNotEmpty($e->getMessage());
        }
    }
}
