# Cobuccio Wallet — Carteira Financeira (PHP · Laravel)

Carteira financeira full stack desenvolvida para o desafio técnico do **Grupo Adriano Cobuccio / Brasil Card**: cadastro e autenticação de usuários, depósitos, transferências entre usuários (por e-mail ou CPF), validação de saldo, recuperação de saldo negativo e estorno auditável de operações.

> O mesmo domínio também foi implementado em **Node.js (NestJS + Next.js)** (repositório `desafio-dev-next`). As regras de negócio, o modelo de dados e as contas de demonstração são idênticos nas duas versões; a seção [Mesmo domínio, duas stacks](#9-mesmo-domínio-duas-stacks) resume o que muda.

---

## 1. Como executar

### Opção A — Docker Compose (recomendado)

```bash
docker compose up --build
```

Sobe PHP-FPM 8.4, Nginx e MySQL 8. Na primeira execução o container copia o `.env`, instala as dependências, gera a `APP_KEY` e roda migrations e seed — não é preciso configurar nada.

| Serviço | URL |
| :--- | :--- |
| Aplicação web | http://localhost:8000 |
| Healthcheck | http://localhost:8000/api/health |
| MySQL (acesso externo) | `localhost:3307` |

Testes dentro do container:
```bash
docker compose exec app php artisan test
```

### Opção B — Local com SQLite (PHP 8.3+ e Composer)

O `.env.example` aponta para o MySQL do Docker (`DB_HOST=db`). Para rodar sem Docker, troque para SQLite:

```bash
cp .env.example .env
```

No `.env`, substitua o bloco `DB_*` por:
```dotenv
DB_CONNECTION=sqlite
```

Depois:
```bash
composer install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

Acesse http://localhost:8000.

---

## 2. Contas de demonstração

Criadas pelo seed (mesmas contas da versão Node). Senha de todas: `password123`.

| Titular | E-mail | CPF | Saldo inicial | Para que serve |
| :--- | :--- | :--- | :--- | :--- |
| Gabriel Cobuccio | `gabriel@cobuccio.com` | `111.222.333-44` | R$ 1.450,00 | Saldo positivo com histórico de depósito e transferência |
| Bruno Costa | `bruno@cobuccio.com` | `555.666.777-88` | R$ 800,00 | Destinatário/remetente para testar transferências |
| Mariana Souza | `mariana@cobuccio.com` | `999.888.777-66` | **−R$ 250,00** | Saldo negativo após um estorno: um depósito abate a dívida |

---

## 3. Regras de negócio

Estas regras são as mesmas na versão Node.

**Cadastro e acesso**
- E-mail e CPF são únicos. O cadastro cria o usuário e a carteira (saldo zero) na mesma transação.
- Falha de login retorna mensagem genérica, sem revelar se o e-mail existe.

**Depósito**
- Valor de R$ 0,01 a R$ 1.000.000,00 por operação; descrição opcional de até 140 caracteres.
- Se a carteira estiver negativa, o depósito abate a dívida e o excedente vira saldo disponível.

**Transferência**
- Destinatário identificado por e-mail ou CPF; não é permitido transferir para a própria carteira.
- Só é permitida se o remetente tiver saldo suficiente. Débito e crédito acontecem na mesma transação.

**Estorno (reversão)**
- Nenhuma operação é apagada: a original passa a `REVERSED` e um contra-lançamento `REVERSAL` é criado, ligado a ela por `original_transaction_id` (único no banco, o que impede estornar duas vezes).
- Estornos não podem ser estornados. Motivo opcional de até 200 caracteres.
- Quem pode estornar e até quando:

| Operação | Quem | Prazo | Motivação |
| :--- | :--- | :--- | :--- |
| Transferência | Remetente | 15 minutos | Arrependimento / erro de digitação |
| Transferência | Destinatário | 24 horas | Devolução voluntária de um valor recebido por engano |
| Depósito | Titular da carteira | 24 horas | Depósito duplicado ou inconsistente |

- O estorno pode deixar o saldo negativo (ex.: o destinatário já gastou o valor recebido). Esse saldo é recuperado no próximo depósito.

**Idempotência**
- Depósitos e transferências aceitam uma chave de idempotência (header `X-Idempotency-Key` ou campo `idempotency_key`). Os formulários Blade enviam um UUID novo, num campo oculto, a cada carregamento da página.
- Repetir a mesma chave devolve a transação original, sem mover dinheiro de novo (HTTP 200 e `idempotent_replay: true`; a primeira execução retorna 201).
- Reutilizar a chave em outra operação (outro valor, tipo ou carteira) retorna **409** e não move dinheiro.

**Limite de requisições**
- Login e cadastro: 10 por minuto (por e-mail + IP). Depósito, transferência e estorno: 30 por minuto (por usuário). Acima disso, a API responde **429**; nos formulários, a página volta com a mensagem de erro.

---

## 4. Modelo de dados

Três tabelas, iguais em conceito e relacionamentos à versão Node. Valores sempre em **centavos inteiros** (`BIGINT`).

```
users (1) ──── (1) wallets (1) ──┬── (N) transactions  [como remetente: sender_wallet_id]
                                 └── (N) transactions  [como destinatário: receiver_wallet_id]
transactions (1) ──── (0..1) transactions  [estorno → original: original_transaction_id]
```

| Tabela | Campos principais |
| :--- | :--- |
| `users` | `id`, `name`, `email` único, `cpf` único, `password` (hash) |
| `wallets` | `id`, `user_id` único, `balance_cents` (pode ser negativo) |
| `transactions` | `id`, `type` (`DEPOSIT`/`TRANSFER`/`REVERSAL`), `status` (`COMPLETED`/`REVERSED`), `amount_cents`, `sender_wallet_id` (nulo em depósitos), `receiver_wallet_id`, `original_transaction_id` único, `reversal_reason`, `reversed_at`, `idempotency_key` único |

As demais tabelas (`sessions`, `cache`, `jobs`, `password_reset_tokens`) são as padrão do Laravel.

---

## 5. Rotas

A aplicação é um monólito Blade com sessão. As mesmas rotas respondem em **JSON** quando a requisição envia `Accept: application/json` (é assim que os testes de integração as exercitam).

| Método | Rota | Descrição |
| :--- | :--- | :--- |
| `GET` / `POST` | `/register` | Tela e envio do cadastro (cria a carteira) |
| `GET` / `POST` | `/login` | Tela e envio do login |
| `POST` | `/logout` | Encerra a sessão |
| `GET` | `/dashboard?tab=overview\|deposit\|transfer\|statement` | Saldo, formulários e extrato |
| `POST` | `/wallet/deposit` | Depósito |
| `POST` | `/wallet/transfer` | Transferência |
| `POST` | `/wallet/transactions/{id}/reverse` | Estorno |
| `GET` | `/api/health` | Healthcheck com verificação do banco |

---

## 6. Decisões técnicas

**Arquitetura.** Controllers finos; validação e normalização (valor em `R$ 1.450,75` → centavos, CPF sem máscara) nos Form Requests; orquestração e transações em `App\Services\WalletService`; invariantes em `App\Domain\Wallet` (`Money`, `TransactionDomainRules`), código PHP puro sem dependência do Eloquent, testado de forma unitária.

**Autenticação.** Sessão do Laravel com cookie criptografado, proteção CSRF em todos os formulários e regeneração do ID de sessão no login (contra *session fixation*).

**Dinheiro em centavos.** Toda a aritmética usa inteiros, evitando os erros de arredondamento de ponto flutuante (`0.1 + 0.2 ≠ 0.3`).

**Concorrência e gasto duplo.** Cada operação roda em `DB::transaction()` com bloqueio pessimista (`lockForUpdate()`, ou seja, `SELECT ... FOR UPDATE` no MySQL). Na transferência, as duas carteiras são bloqueadas **em ordem crescente de ID**, o que evita *deadlock* quando dois usuários transferem um para o outro ao mesmo tempo. O saldo é lido e validado já com a linha bloqueada, então duas transferências simultâneas não conseguem gastar o mesmo saldo. Se duas requisições com a mesma chave de idempotência chegarem juntas, o índice único barra a segunda e o serviço devolve a transação da primeira.

**Strict types.** Todos os arquivos PHP do projeto declaram `declare(strict_types=1);`.

---

## 7. Testes e qualidade

```bash
php artisan test                  # 40 testes (unitários de domínio + integração HTTP/banco)
php vendor/bin/phpstan analyse    # PHPStan nível 8 com Larastan — sem erros
php vendor/bin/pint --test        # Padrão de código (Laravel Pint)
```

Os testes rodam em SQLite em memória (configurado no `phpunit.xml`), sem depender do MySQL.

---

## 8. Limitações conhecidas

- No modo local com SQLite, `lockForUpdate()` não tem efeito (o SQLite não suporta `FOR UPDATE`); a consistência vem do bloqueio de escrita do próprio SQLite. O bloqueio por linha descrito acima vale no MySQL do Docker.
- O limite de requisições usa o cache da aplicação (`CACHE_STORE=database`); com várias instâncias, o ideal seria Redis.
- Nos formulários, quem excede o limite é redirecionado com mensagem de erro (HTTP 302), não com 429. O 429 aparece para clientes JSON.

---

## 9. Mesmo domínio, duas stacks

| Aspecto | Esta versão (PHP) | Versão Node |
| :--- | :--- | :--- |
| Backend | Laravel 13 + Eloquent | NestJS 10 + Prisma 6 |
| Frontend | Blade + Alpine.js + Tailwind (monólito) | Next.js 15 / React 19, Server Actions (BFF) |
| Banco | MySQL 8 (Docker) ou SQLite (local) | SQLite |
| IDs | inteiros autoincrementais | UUID |
| Autenticação | Sessão Laravel + CSRF | JWT em cookie `httpOnly` |
| Gasto duplo | `SELECT ... FOR UPDATE` em ordem de ID | `UPDATE` condicional (`balanceCents >= valor`) |
| Rate limit | `RateLimiter` + middleware `throttle` | `@nestjs/throttler` |
| Testes | PHPUnit (40) + PHPStan nível 8 + Pint | Jest + Supertest (48) |

Regras de negócio, prazos de estorno, limites de valor, idempotência, modelo de dados e contas de demonstração são os mesmos nas duas.
