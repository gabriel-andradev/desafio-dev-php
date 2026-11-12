# Cobuccio Wallet — Comparação técnica

> Este documento é idêntico nos dois repositórios (`desafio-dev-php` e `desafio-dev-next`).

## 1. Objetivo

As duas aplicações implementam **o mesmo produto** — uma carteira financeira com cadastro, autenticação, depósito, transferência, extrato e estorno — com stacks diferentes:

| | PHP | Node |
| :--- | :--- | :--- |
| Stack | PHP 8.4, Laravel 13, Blade, Alpine.js, Eloquent | TypeScript, Next.js 15, React 19, NestJS 10, Prisma |
| Banco | SQLite | SQLite |
| Forma | Monólito modular | BFF (Next.js) + API REST (NestJS) |

O contrato de negócio é o mesmo; a arquitetura de cada um segue o próprio framework.

---

## 2. Comparação arquitetural

| Tema | Laravel | NestJS / Next.js | Por que difere |
| :--- | :--- | :--- | :--- |
| Organização | Rotas → Form Request → Controller → Service → Domain | DTO → Controller → Service → Domain | Convenção de cada framework |
| Interface | Blade + Alpine.js, servida pelo mesmo processo | Next.js (Server Components + Server Actions) | Monólito × BFF |
| ORM | Eloquent (Active Record) | Prisma (cliente gerado do schema) | — |
| Validação | Form Requests | DTOs com class-validator | — |
| Proteção de rota | Middleware `auth` | `JwtAuthGuard` (Passport) | — |
| Autenticação | Sessão (cookie) | JWT (Bearer), guardado pelo BFF em cookie `httpOnly` | Ver seção 5 |
| CSRF | Token `@csrf` em todo formulário | Server Actions + verificação de Origin do Next.js + `SameSite=Lax`; a API só aceita Bearer | Ver seção 5 |
| Transação | `DB::transaction()` | `prisma.$transaction()` interativa | — |
| Gasto duplo | `BEGIN IMMEDIATE` + `UPDATE` condicional (`lockForUpdate()` para MySQL/PostgreSQL) | `UPDATE` condicional (`updateMany ... gte`) | Ver seção 4 |
| Rate limit | `RateLimiter` + middleware `throttle` | `@nestjs/throttler` | — |
| IDs | Inteiros autoincrementais | UUID | Padrão de cada ORM; a autorização não depende de o ID ser imprevisível |

---

## 3. Contrato de negócio

| Regra | PHP | Node |
| :--- | :---: | :---: |
| Nome 3–100, e-mail normalizado, CPF 11 dígitos sem máscara | ✅ | ✅ |
| Senha 8–72, hash bcrypt | ✅ | ✅ |
| E-mail/CPF duplicado → 409 | ✅ | ✅ |
| Usuário + carteira (saldo 0) numa transação | ✅ | ✅ |
| Login inválido → 401, mensagem genérica | ✅ | ✅ |
| Valores em centavos inteiros | ✅ | ✅ |
| Valor por operação: R$ 0,01 a R$ 1.000.000,00 (`Money` centraliza) | ✅ | ✅ |
| Depósito quita saldo negativo primeiro | ✅ | ✅ |
| Transferência: e-mail ou CPF, não para si mesmo (422), destinatário inexistente (404), saldo insuficiente (422) | ✅ | ✅ |
| Estorno: remetente 15 min, destinatário 24 h, depósito 24 h | ✅ | ✅ |
| Estorno: não participante 403, fora do prazo 422, já estornado 409 | ✅ | ✅ |
| Ledger imutável: `REVERSED` + lançamento `REVERSAL` ligado (único) | ✅ | ✅ |
| Estorno pode deixar saldo negativo | ✅ | ✅ |
| Idempotência: repetição devolve a original; chave reutilizada em outra operação → 409 | ✅ | ✅ |
| Idempotência concorrente: duas requisições simultâneas → um crédito | ✅ | ✅ |
| Chave de idempotência de outro usuário → 409, sem expor a operação | ✅ | ✅ |
| Compensação técnica interna: sem prazo, com justificativa e responsável, auditada, sem rota HTTP | ✅ `php artisan wallet:compensate` | ✅ `npm run wallet:compensate` |
| Rate limit: auth 10/min, carteira 30/min | ✅ | ✅ (+120/min global) |
| CPF de outros usuários mascarado na lista de contatos | ✅ | ✅ |
| Mesmas contas de demonstração | ✅ | ✅ |

Os prazos de estorno e o teto por operação são **políticas desta implementação**, não requisitos do enunciado.

---

## 4. Decisões e trade-offs

### Gasto duplo no SQLite

SQLite não tem lock por linha: `SELECT ... FOR UPDATE` não existe e o `lockForUpdate()` do Laravel não faz nada nele. As duas versões resolvem o problema sem depender disso:

- **Node:** o débito é um `UPDATE ... WHERE balanceCents >= valor`. Se outra operação consumiu o saldo antes, nenhuma linha é alterada e a transferência é recusada.
- **PHP:** o mesmo `UPDATE` condicional, mais `transaction_mode = IMMEDIATE`: a transação já começa com a trava de escrita, então operações concorrentes esperam a vez em vez de falhar com `SQLITE_BUSY` no meio. O `lockForUpdate()` foi mantido porque é o que protege a operação em MySQL/PostgreSQL.

### Idempotência

Nas duas versões a chave é única no banco. Se duas requisições com a mesma chave passam juntas pela verificação inicial, a segunda transação inteira é desfeita pelo índice único e a requisição devolve a operação da primeira. A chave nasce no formulário (UUID): um duplo envio reaproveita a mesma chave.

### Estorno por inconsistência técnica

O enunciado pede reversão "em caso de inconsistência" além da solicitação do usuário. As duas versões têm um caso de uso interno de **compensação**:

- **Origem confiável:** não existe rota HTTP. Só um comando executado no servidor (`php artisan wallet:compensate` / `npm run wallet:compensate`) chama a operação; quem tem shell no servidor é, por definição, operador. Não foram criados usuários nem papéis administrativos.
- **Regras próprias** (`assertCompensationAllowed` / `validateCompensationPreconditions`): sem janela de prazo e sem exigir participação, mas exige justificativa (mín. 10 caracteres) e responsável; nunca compensa um estorno nem algo já estornado.
- **Mesmo núcleo atômico do estorno do usuário:** status `REVERSED` condicional, saldos devolvidos e lançamento `REVERSAL` ligado à original (índice único impede duas compensações).
- **Auditoria:** todo `REVERSAL` grava `reversal_source` (`USER` ou `COMPENSATION`) e `reversal_actor` (`user:<id>` ou `operator:<nome>`).

Se no futuro houver um painel administrativo, ele deve chamar o mesmo serviço atrás de um papel explícito — não reaproveitar a rota de estorno do usuário.

### Revogação do JWT (Node)

O logout do Node chama `POST /api/auth/logout`, que incrementa `users.tokenVersion`; o JWT carrega a versão (`ver`) e o `JwtStrategy` recusa versões antigas. Uma coluna, sem lista de tokens. Efeito: o logout encerra a sessão em todos os dispositivos. No Laravel isso já é nativo (a sessão é invalidada no servidor).

### `SQLITE_BUSY`

PHP: `busy_timeout` de 5 s com transações `IMMEDIATE`. Node: o Prisma espera a trava; erros transitórios restantes (`database is locked`, P2034) fazem a operação inteira ser repetida até 3 vezes. Nos testes entre processos, nenhuma requisição terminou em erro de trava nas duas versões.

---

## 5. Segurança

| | PHP (sessão) | Node (JWT) |
| :--- | :--- | :--- |
| Credencial | Cookie de sessão criptografado, `HttpOnly`, `SameSite=Lax` | JWT em cookie `httpOnly`, `SameSite=Lax`, `Secure` em produção; repassado pelo BFF como `Bearer` |
| Login | `Auth::attempt()` + regeneração da sessão | bcrypt + emissão de JWT com expiração |
| Logout | Sessão invalidada no servidor | `tokenVersion` incrementado: todo JWT anterior passa a receber 401; cookie apagado |
| CSRF | Token `@csrf` obrigatório | Server Actions (POST) com verificação de `Origin` × `Host` do Next.js + `SameSite=Lax`; a API ignora cookies |
| Entrada | Form Requests | `ValidationPipe` com `whitelist` e `forbidNonWhitelisted` |

**JWT e CSRF não são equivalentes.** JWT é uma forma de autenticar; CSRF é um ataque que explora o envio automático de cookies. Como o Node também guarda o JWT num cookie, ele também precisa de proteção contra CSRF — e a tem pelas Server Actions e pelo `SameSite`, não pelo JWT.

**Riscos conhecidos:** rate limit em memória (Node) e em cache de banco (PHP), não compartilhados entre instâncias; no Node o logout encerra a sessão em todos os dispositivos (consequência da versão de sessão única).

---

## 6. Testes

| | PHP | Node |
| :--- | :--- | :--- |
| Unitários de domínio | PHPUnit (`tests/Unit`) | Jest (`*.spec.ts`) |
| Integração | Feature tests com SQLite em memória | Supertest + SQLite dedicado (`test/e2e.db`) |
| Paridade | `tests/Feature/ParityScenariosTest.php` | `test/parity.e2e-spec.ts` |
| Concorrência | 2 processos PHP independentes sobre um arquivo SQLite | 2 processos Node independentes sobre um arquivo SQLite, além de requisições simultâneas na mesma API |
| Rollback | Falha simulada ao gravar o lançamento | Falha simulada ao gravar o lançamento (no mesmo processo e num processo separado) |
| Compensação | `tests/Feature/CompensationTest.php` | `test/compensation.e2e-spec.ts` (inclui execução real do script) |
| Análise estática | PHPStan nível 8, Pint | `tsc --noEmit` |
| **Total (última execução)** | **60 testes, todos passando** | **76 testes (37 unit + 25 e2e + 14 frontend), todos passando** |

Cenários de paridade cobertos nos dois: duplicidade de e-mail/CPF, login inválido, depósito zero/negativo/acima do teto, autotransferência, destinatário inexistente, transferências simultâneas, idempotência simultânea, chave reutilizada, rollback e regras de estorno (403/422/409). O limite de requisições tem teste nos dois.

**Limitação:** a concorrência foi provada com dois processos independentes na mesma máquina e no mesmo arquivo SQLite, nas duas versões. Não houve teste de carga nem com vários servidores — o que, com SQLite, também não é um cenário de produção.

---

## 7. Conclusão

As duas versões entregam o mesmo comportamento financeiro — mesmos valores, mesmas regras, mesmos códigos HTTP — com soluções próprias de cada stack: sessão e CSRF num monólito Laravel; JWT atrás de um BFF no Next.js; trava de escrita do SQLite + `UPDATE` condicional no Laravel; `UPDATE` condicional dentro da transação interativa do Prisma. Os testes de paridade existem nos dois repositórios para manter esse contrato.
