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

### Estorno por inconsistência técnica (pendente de decisão)

Hoje só existe o estorno pedido pelo usuário, com prazos. Um estorno por **falha técnica** (ex.: conciliação que encontrou um crédito indevido) não deveria depender da janela de arrependimento, mas também não pode ser uma rota que qualquer usuário chama. Proposta para as duas versões:

1. Separar no domínio `assertUserReversalAllowed()` (regra atual) de `assertCompensationAllowed()` (sem prazo; exige motivo e só aceita operações `COMPLETED`).
2. Expor a compensação apenas por um comando interno (Artisan / script NestJS) ou por um papel administrativo, nunca pela interface do usuário.
3. Registrar quem executou e o motivo no próprio lançamento `REVERSAL`.

Não foi implementado porque exige definir quem é o operador autorizado — uma decisão de negócio.

---

## 5. Segurança

| | PHP (sessão) | Node (JWT) |
| :--- | :--- | :--- |
| Credencial | Cookie de sessão criptografado, `HttpOnly`, `SameSite=Lax` | JWT em cookie `httpOnly`, `SameSite=Lax`, `Secure` em produção; repassado pelo BFF como `Bearer` |
| Login | `Auth::attempt()` + regeneração da sessão | bcrypt + emissão de JWT com expiração |
| Logout | Sessão invalidada no servidor | Cookie apagado; o JWT continua válido até expirar |
| CSRF | Token `@csrf` obrigatório | Server Actions (POST) com verificação de `Origin` × `Host` do Next.js + `SameSite=Lax`; a API ignora cookies |
| Entrada | Form Requests | `ValidationPipe` com `whitelist` e `forbidNonWhitelisted` |

**JWT e CSRF não são equivalentes.** JWT é uma forma de autenticar; CSRF é um ataque que explora o envio automático de cookies. Como o Node também guarda o JWT num cookie, ele também precisa de proteção contra CSRF — e a tem pelas Server Actions e pelo `SameSite`, não pelo JWT.

**Riscos conhecidos:** sem revogação de JWT no logout (Node); rate limit em memória (Node) e em cache de banco (PHP), não compartilhados entre instâncias.

---

## 6. Testes

| | PHP | Node |
| :--- | :--- | :--- |
| Unitários de domínio | PHPUnit (`tests/Unit`) | Jest (`*.spec.ts`) |
| Integração | Feature tests com SQLite em memória | Supertest + SQLite dedicado (`test/e2e.db`) |
| Paridade | `tests/Feature/ParityScenariosTest.php` | `test/parity.e2e-spec.ts` |
| Concorrência | 2 processos PHP reais sobre um arquivo SQLite | Requisições simultâneas na mesma API |
| Rollback | Falha simulada ao gravar o lançamento | Falha simulada ao gravar o lançamento |
| Análise estática | PHPStan nível 8, Pint | `tsc --noEmit` |
| **Total (última execução)** | **53 testes, todos passando** | **64 testes (35 unit + 15 e2e + 14 frontend), todos passando** |

Cenários de paridade cobertos nos dois: duplicidade de e-mail/CPF, login inválido, depósito zero/negativo/acima do teto, autotransferência, destinatário inexistente, transferências simultâneas, idempotência simultânea, chave reutilizada, rollback e regras de estorno (403/422/409). O limite de requisições tem teste nos dois.

**Limitação:** no Node, os testes de concorrência rodam num único processo; o SQLite serializa as transações, então eles provam a regra sob requisições sobrepostas, não sob vários servidores. No PHP, os processos são realmente separados.

---

## 7. Conclusão

As duas versões entregam o mesmo comportamento financeiro — mesmos valores, mesmas regras, mesmos códigos HTTP — com soluções próprias de cada stack: sessão e CSRF num monólito Laravel; JWT atrás de um BFF no Next.js; trava de escrita do SQLite + `UPDATE` condicional no Laravel; `UPDATE` condicional dentro da transação interativa do Prisma. Os testes de paridade existem nos dois repositórios para manter esse contrato.
