# SDK PHP eRede com OAuth 2.0

SDK de integração com a eRede utilizando autenticação OAuth 2.0 (client credentials), cobrindo transações de crédito/débito e o serviço de **Tokenização de Bandeira** (Card Brand Tokenization — MDES/VTS).

## Funcionalidades

**Gateway de Pagamento (`ERede`)**
- Autenticação OAuth 2.0 (client credentials)
- Reuso e persistência do access token (`getAccessToken` / `setAccessToken`, `Token::toArray()` / `fromArray()`)
- Autorização de transações de crédito e débito
- Captura de transações pré-autorizadas
- Cancelamento de transações
- Consulta por TID e por referência
- Parcelamento
- Logging e auditoria segura de transações (`TransactionDetails`, `toSafeArray()`)

**Tokenização de Bandeira (`CardBrandTokenizationClient`)**
- Criação de requisição de tokenização (`POST /token-service/oauth/v2/tokenization`)
- Consulta de status do token (`GET /token-service/oauth/v2/tokenization/{id}`)
- Geração de criptograma (`POST /token-service/oauth/v2/cryptogram/{id}`)
- Gerenciamento do ciclo de vida — suspender, reativar, deletar (`PUT /token-service/oauth/v2/tokenization/{id}`)
- Registro de webhook no sandbox (`POST /token-service/oauth/v2/tokenization/seturl`)
- Suporte a transações com token de bandeira via `Transaction::withBrandToken()`

## Requisitos

- PHP >= 8.1
- Docker (para desenvolvimento)

## Instalação

### Via Composer

```bash
composer require devsitarget/sdk-erede-php-oauth
```

### Via Docker (desenvolvimento)

```bash
make build    # Constrói a imagem
make up       # Inicia o container
make install  # Instala as dependências
make setup-env  # Cria o .env a partir do env.example
```

## Configuração

### Credenciais

```php
<?php
use RedeOAuth\Store;
use RedeOAuth\Environment;
use RedeOAuth\OAuth\OAuthClient;

// Sandbox
$oauthClient = new OAuthClient('https://rl7-sandbox-api.useredecloud.com.br/oauth2/token');
$store = new Store('MERCHANT_ID', 'MERCHANT_KEY', Environment::sandbox(), $oauthClient);

// Produção
$oauthClient = new OAuthClient('https://api.userede.com.br/erede/oauth2/token');
$store = new Store('MERCHANT_ID', 'MERCHANT_KEY', Environment::production(), $oauthClient);
```

### Reuso do access token OAuth

> **v3.3.0** — Evite solicitar um novo token a cada request: obtenha, persista (cache, Redis, sessão, etc.) e reinjete o access token em novas instâncias de `ERede` ou `CardBrandTokenizationClient`.

Por padrão o SDK obtém o token automaticamente na primeira chamada. Com `getAccessToken()` / `setAccessToken()` você controla o ciclo de vida:

```php
<?php
use RedeOAuth\ERede;
use RedeOAuth\OAuth\Token;
use RedeOAuth\Tokenization\CardBrandTokenizationClient;

$erede = new ERede($store);

// Obtém o token (gera um novo se ainda não existir ou estiver expirado)
$token = $erede->getAccessToken();

// Persiste para reutilizar em outros processos/requests
$cache->set('rede_oauth_token', $token->toArray(), $token->getExpiresIn());
```

#### Restaurar um token do cache

```php
<?php
$cached = $cache->get('rede_oauth_token');

if ($cached !== null) {
    $token = Token::fromArray($cached);

    // Via construtor
    $erede = new ERede($store, accessToken: $token);

    // Ou depois da construção
    $erede = new ERede($store);
    $erede->setAccessToken($token);
}

// Mesma API no cliente de tokenização
$client = new CardBrandTokenizationClient($store, accessToken: $token);
```

Se o token injetado estiver expirado (ou faltar menos de 60 segundos para expirar), o SDK solicita um novo automaticamente na próxima requisição. Em caso de `401`, o token é renovado e a requisição é reenviada.

---

## Gateway de Pagamento

### Autorizar uma transação (sem captura automática)

```php
<?php
use RedeOAuth\Transaction;
use RedeOAuth\ERede;

$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->capture(false);

try {
    $response = (new ERede($store))->create($transaction);

    if ($response->getReturnCode() === '00') {
        printf("Autorizada; tid=%s\n", $response->getTid());
    } else {
        printf("Recusada: %s\n", $response->getReturnMessage());
    }
} catch (\Exception $e) {
    printf("Erro: %s\n", $e->getMessage());
}
```

### Autorizar com captura automática

```php
<?php
$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->capture(true);

$response = (new ERede($store))->create($transaction);
```

### Parcelamento

```php
<?php
$transaction = (new Transaction(100.00, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->setInstallments(3);

$response = (new ERede($store))->create($transaction);

if ($response->getReturnCode() === '00') {
    printf("Parcelado em %dx; tid=%s\n", $response->getInstallments(), $response->getTid());
}
```

### Capturar uma transação pré-autorizada

```php
<?php
// Pré-autoriza
$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow')
    ->capture(false);

$response = (new ERede($store))->create($transaction);

// Captura
$captureTransaction = (new Transaction(20.99))->setTid($response->getTid());
$captureResponse = (new ERede($store))->capture($captureTransaction);

if ($captureResponse->getReturnCode() === '00') {
    printf("Capturada; tid=%s\n", $captureResponse->getTid());
}
```

### Cancelar uma transação

```php
<?php
$cancelTransaction = (new Transaction(20.99))->setTid('TID_AQUI');
$cancelResponse = (new ERede($store))->cancel($cancelTransaction);

// Código 359 = cancelamento realizado com sucesso
if (in_array($cancelResponse->getReturnCode(), ['00', '359'])) {
    printf("Cancelada; tid=%s\n", $cancelResponse->getTid());
}
```

### Consultar por TID

```php
<?php
$response = (new ERede($store))->get('TID_AQUI');

printf("Status: %s\n", $response->getAuthorization()->getStatus());
printf("NSU: %s\n", $response->getAuthorization()->getNsu());
printf("Autorização: %s\n", $response->getAuthorization()->getAuthorizationCode());
```

### Consultar por referência

```php
<?php
$response = (new ERede($store))->getByReference('pedido123');

printf("TID: %s\n", $response->getTid());
```

---

## Logging e Auditoria

> **v3.2.0** — Suporte para inspecionar request, response, mensagens, códigos de erro, bandeira e autenticação 3DS **sem expor dados sensíveis** do cartão (PAN, CVV, criptograma, PaReq/CReq).

O fluxo normal de pagamento **não é alterado**: a API continua recebendo `toArray()` com os dados completos. Os métodos abaixo são **opt-in** — use apenas quando precisar registrar ou exibir informações da transação.

### Dados disponíveis

| Campo | Disponível | Observação |
|-------|------------|------------|
| Request sanitizado | Sim | Valor, referência, parcelas, tipo, 3DS |
| Response sanitizado | Sim | TID, NSU, status, autorização |
| `returnCode` / `returnMessage` | Sim | Código e mensagem da Rede |
| Bandeira do cartão | Sim | Nome e mensagem da bandeira |
| BIN + últimos 4 dígitos | Sim | Sem número completo |
| Autenticação 3DS | Sim | Device, URLs, redirect (parâmetros sensíveis ocultos) |
| Tokenização | Sim | Via `toSafeArray()` nos DTOs de tokenização |

### Dados que nunca aparecem

| Campo | Como é exibido |
|-------|----------------|
| Número do cartão | `544828******0007` |
| CVV | `***` |
| Nome do portador | `John ***` |
| Criptograma / token PAN | `[REDACTED]` |
| PaReq / CReq (3DS) | `[REDACTED]` |
| E-mail (tokenização) | `p***@exemplo.com` |

### Registrar uma transação com sucesso

```php
<?php
use RedeOAuth\Http\HttpException;
use RedeOAuth\Transaction;
use RedeOAuth\TransactionDetails;
use RedeOAuth\ERede;

$transaction = (new Transaction(20.99, 'pedido' . time()))
    ->creditCard('5448280000000007', '235', '12', '2028', 'John Snow');

try {
    $response = (new ERede($store))->create($transaction);
    $details  = TransactionDetails::fromTransaction($transaction, $response);

    // Salvar em banco, enviar para log, exibir no painel...
    $auditoria = $details->toArray();

    printf("TID: %s\n", $auditoria['response']['tid']);
    printf("Código: %s\n", $auditoria['returnCode']);
    printf("Mensagem: %s\n", $auditoria['returnMessage']);
    printf("Bandeira: %s\n", $auditoria['brand']['name']);

} catch (HttpException $e) {
    $details = TransactionDetails::fromException($e, $transaction->toSafeArray());
    $auditoria = $details->toArray();

    printf("Erro %s: %s\n", $auditoria['returnCode'], $auditoria['returnMessage']);
}
```

### Acesso direto aos dados

```php
<?php
$details = TransactionDetails::fromTransaction($transaction, $response);

$details->getRequest();         // request sanitizado
$details->getResponse();        // response sanitizado
$details->getAuthentication();  // dados 3DS (request + response), ou null
$details->getReturnCode();      // ex: "00", "51", "220"
$details->getReturnMessage();   // mensagem da Rede
$details->getBrand();           // ['name' => 'Mastercard', 'message' => null]
```

### Autenticação 3DS

Quando a transação usa 3DS, `getAuthentication()` retorna o request (device + URLs de callback) e o response (URL de redirect, método, parâmetros sem dados sensíveis):

```php
<?php
use RedeOAuth\Device;
use RedeOAuth\Url;

$transaction = (new Transaction(30.00, 'pedido3ds'))
    ->debitCard('4111111111111111', '123', '06', '2029', 'Jane Doe')
    ->threeDSecure(new Device(1, 'BROWSER', false, 'BR', 500, 500, 3))
    ->addUrl('https://meusite.com/3ds/success', Url::THREE_D_SECURE_SUCCESS)
    ->addUrl('https://meusite.com/3ds/failure', Url::THREE_D_SECURE_FAILURE);

$response = (new ERede($store))->create($transaction);
$details  = TransactionDetails::fromTransaction($transaction, $response);

$auth = $details->getAuthentication();
// $auth['request']  → device + urls
// $auth['response'] → url, method, parameters (PaReq/CReq ocultos)
```

### Sanitizar request ou response individualmente

Todos os DTOs principais expõem `toSafeArray()`:

```php
<?php
// Pagamento
$safeRequest  = $transaction->toSafeArray();
$safeResponse = $response->toSafeArray();

// Getters adicionais na response
$response->getBrandName();    // bandeira (API ou detecção por BIN)
$response->getBrandMessage();  // mensagem da bandeira
$response->getRawData();      // JSON bruto da API (uso interno)

// Tokenização
$safeTokenRequest  = $tokenizationRequest->toSafeArray();
$safeTokenResponse = $tokenQueryResponse->toSafeArray();
$safeCryptogram    = $cryptogramResponse->toSafeArray();
```

### Erros estruturados (`HttpException`)

A partir da v3.2.0, `HttpException` expõe os campos da API separadamente:

```php
<?php
use RedeOAuth\Http\HttpException;

try {
    $response = (new ERede($store))->create($transaction);
} catch (HttpException $e) {
    $e->getReturnCode();    // ex: "51"
    $e->getReturnMessage(); // ex: "Insufficient funds"
    $e->getResponseBody();  // array com o JSON completo da API
    $e->getCode();          // HTTP status (ex: 400)
}
```

### Utilitário de sanitização

Para sanitizar arrays customizados (ex.: antes de enviar ao Monolog):

```php
<?php
use RedeOAuth\SensitiveDataSanitizer;

$safe = SensitiveDataSanitizer::sanitize($dadosBrutos);
$bandeira = SensitiveDataSanitizer::detectBrand('544828'); // "Mastercard"
```

---

## Tokenização de Bandeira

> **Pré-requisito:** O serviço de tokenização precisa estar habilitado para o seu estabelecimento. Acesse [developer.userede.com.br](https://developer.userede.com.br) para habilitar.

O fluxo de tokenização é **assíncrono**: após criar uma requisição, o token é provisionado pela bandeira (MDES para Mastercard, VTS para Visa) e seu status é atualizado via webhook.

### 1. Configurar o cliente

```php
<?php
use RedeOAuth\Store;
use RedeOAuth\Environment;
use RedeOAuth\OAuth\OAuthClient;
use RedeOAuth\Tokenization\CardBrandTokenizationClient;

$oauthClient = new OAuthClient('https://rl7-sandbox-api.useredecloud.com.br/oauth2/token');
$store  = new Store('MERCHANT_ID', 'MERCHANT_KEY', Environment::sandbox(), $oauthClient);
$client = new CardBrandTokenizationClient($store);
```

### 2. Registrar URL de webhook (apenas sandbox)

Em produção, o registro é feito pelo Portal Logado da Rede.

```php
<?php
// Sem autenticação no webhook
$result = $client->setWebhookUrl('https://meusite.com/webhook/rede-token');

// Com autenticação Bearer
$result = $client->setWebhookUrl(
    'https://meusite.com/webhook/rede-token',
    'Bearer',
    'Bearer meu_token_secreto'
);
```

### 3. Criar tokenização

```php
<?php
use RedeOAuth\Tokenization\TokenizationRequest;

$request = new TokenizationRequest(
    cardNumber:      '5448280000000007',
    expirationMonth: '12',
    expirationYear:  '2028',
    email:           'portador@exemplo.com',
    storageCard:     0,            // 0 = não armazenado, 2 = já armazenado
    cardholderName:  'JOHN SNOW',
    securityCode:    '235'
);

$response = $client->createTokenization($request);

if ($response->getReturnCode() === '00') {
    $tokenizationId = $response->getTokenizationId(); // UUID a ser armazenado
    printf("Requisição criada; tokenizationId=%s\n", $tokenizationId);
}
```

### 4. Aguardar o webhook e consultar o status

Após receber o evento de webhook, consulte o status do token:

```php
<?php
$token = $client->getToken($tokenizationId);

printf("Status: %s\n", $token->getTokenizationStatus()); // Active | Pending | Failed | ...
printf("Bandeira: %s\n", $token->getBrandName());
printf("Últimos 4: %s\n", $token->getLast4());

if ($token->isActive()) {
    // Token pronto para uso em transações
}
```

Ciclo de vida do status:

| Status | Descrição |
|--------|-----------|
| `Pending` | Aguardando processamento da bandeira |
| `Active` | Pronto para uso em transações |
| `Inactive` | Inativo |
| `Suspended` | Suspenso temporariamente |
| `Deleted` | Removido permanentemente |
| `Failed` | Falha no provisionamento |

### 5. Gerar criptograma

> Gere um criptograma por transação. Não armazene nem reutilize.

```php
<?php
$cryptogram = $client->getCryptogram($tokenizationId);

if ($cryptogram->isSuccess()) {
    printf("Criptograma: %s\n", $cryptogram->getTokenCryptogram());
    printf("ECI: %s\n", $cryptogram->getEci()); // para Visa/ELO
}

// Para transações de recorrência
$cryptogram = $client->getCryptogram($tokenizationId, subscription: true);
```

### 6. Realizar transação com o token

```php
<?php
use RedeOAuth\Transaction;
use RedeOAuth\ERede;

$transaction = (new Transaction(99.90, 'pedido' . time()))
    ->creditCard(
        '5448280000000007', // número do cartão original (ou token PAN)
        '',                 // CVV vazio — a segurança vem do criptograma
        '12',
        '2028',
        'JOHN SNOW'
    )
    ->withBrandToken(
        tokenCryptogram: $cryptogram->getTokenCryptogram(),
        storageCard:     2,                      // 2 = credencial já armazenada
        sai:             $cryptogram->getEci(),  // obrigatório para Visa e ELO
        credentialId:    'CREDENTIAL_ID'         // obrigatório para Mastercard (storageCard 1 ou 2)
    );

$response = (new ERede($store))->create($transaction);
```

### 7. Gerenciar o ciclo de vida do token

```php
<?php
// Suspender (temporariamente)
$response = $client->manageToken(
    $tokenizationId,
    CardBrandTokenizationClient::STATUS_SUSPENDED,
    CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST // ou REASON_SUSPECTED_FRAUD
);

// Reativar
$response = $client->manageToken(
    $tokenizationId,
    CardBrandTokenizationClient::STATUS_RESUMED,
    CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
);

// Deletar permanentemente
$response = $client->manageToken(
    $tokenizationId,
    CardBrandTokenizationClient::STATUS_DELETED,
    CardBrandTokenizationClient::REASON_CUSTOMER_REQUEST
);
```

---

## Testes

### Configuração

```bash
make setup-env  # cria .env a partir de env.example
```

Edite o `.env` com suas credenciais de sandbox:

```env
REDE_MERCHANT_ID=seu_merchant_id
REDE_MERCHANT_KEY=seu_merchant_key

# Apenas para testes de tokenização:
REDE_TOKENIZATION_WEBHOOK_URL=https://meusite.com/webhook/rede-token
```

### Executar

```bash
# Via Docker (recomendado)
make test

# Localmente
composer test

# Apenas testes unitários
composer test -- --testsuite Unit

# Apenas testes de integração
composer test -- --testsuite Integration

# Apenas tokenização
composer test -- --group tokenization
```

### Suites

| Suite | Descrição |
|-------|-----------|
| **Unit** | Testes dos modelos de dados e camadas HTTP/OAuth — sem chamadas de rede |
| **Integration** | Testes contra o sandbox real da eRede — requerem `.env` configurado |

### Comportamento dos testes de tokenização no sandbox

Os testes de **criptograma** e **gerenciamento de token** dependem do provisionamento completo pela bandeira, que ocorre após o evento de webhook. Sem webhook configurado, esses testes são marcados como `Incompleto` com a mensagem de como configurar. Os demais (criação e consulta de status) funcionam sem webhook.

---

## Estrutura do Projeto

```
rede-oauth2/
├── src/Rede/
│   ├── OAuth/                    # Autenticação OAuth 2.0
│   │   ├── OAuthClient.php       # Obtém/renova tokens via client credentials
│   │   ├── OAuthClientInterface.php
│   │   ├── ManagesAccessToken.php # Trait get/set access token (ERede + Tokenization)
│   │   ├── Token.php             # Token + toArray()/fromArray() para cache
│   │   └── OAuthException.php
│   ├── Http/                     # Camada HTTP
│   │   ├── AuthenticatedHttpClient.php  # Injeta Bearer token + trata 401
│   │   ├── HttpClientInterface.php
│   │   └── HttpException.php
│   ├── Tokenization/             # Tokenização de Bandeira
│   │   ├── CardBrandTokenizationClient.php
│   │   ├── TokenizationRequest.php
│   │   ├── TokenizationResponse.php
│   │   ├── TokenQueryResponse.php
│   │   ├── CryptogramResponse.php
│   │   └── TokenManagementResponse.php
│   ├── ERede.php                 # Gateway de pagamento (fachada)
│   ├── Store.php                 # Configuração do merchant
│   ├── Environment.php           # URLs de sandbox e produção
│   ├── Transaction.php           # Modelo de transação
│   ├── TransactionResponse.php
│   ├── TransactionDetails.php    # Agrega request + response + 3DS para auditoria
│   ├── SensitiveDataSanitizer.php  # Mascara PAN, CVV, criptograma etc.
│   ├── CreditCard.php
│   ├── DebitCard.php
│   └── ...
├── tests/
│   ├── Unit/
│   │   ├── Tokenization/
│   │   │   └── TokenizationModelTest.php   # Testes de DTO sem HTTP
│   │   ├── Http/
│   │   ├── OAuth/
│   │   ├── StoreTest.php
│   │   ├── TransactionTest.php
│   │   └── TransactionDetailsTest.php      # Testes de logging/auditoria
│   ├── Integration/
│   │   ├── RedeGatewayTest.php             # Testes do gateway contra sandbox
│   │   └── TokenizationIntegrationTest.php # Testes de tokenização contra sandbox
│   └── bootstrap.php
├── env.example
├── phpunit.xml
├── composer.json
└── Makefile
```

## Comandos Disponíveis

| Comando | Descrição |
|---------|-----------|
| `make build` | Constrói a imagem Docker |
| `make up` | Inicia o container |
| `make down` | Para e remove o container |
| `make install` | Instala dependências via Composer |
| `make test` | Executa todos os testes |
| `make cs-check` | Verifica estilo de código (PSR-12) |
| `make cs-fix` | Corrige estilo de código automaticamente |
| `make phpstan` | Análise estática com PHPStan |
| `make shell` | Abre shell no container |
| `make setup-env` | Cria `.env` a partir de `env.example` |

## Licença

MIT
