# Carteira Digital Financeira — Grupo Adriano Cobuccio (PHP & Laravel)

Aplicação Full Stack de carteira digital desenvolvida em **PHP** com **Laravel**, projetada para operações de depósito, transferência entre usuários (P2P) por CPF ou e-mail, tratamento de saldo negativo e reversão auditável de transações.

---

## 1. Tecnologias Utilizadas

* **PHP 8.3 / 8.4** com `declare(strict_types=1);` em todos os arquivos.
* **Laravel** (Eloquent ORM, Form Requests, Sessão Criptografada, Proteção CSRF).
* **MySQL 8.0** (Transações ACID e bloqueio pessimista com `SELECT ... FOR UPDATE`).
* **Blade + Tailwind CSS + Alpine.js** (Interface reativa com máscaras monetárias e de CPF em tempo real).
* **PHPUnit** (Suíte de testes unitários de domínio e testes de integração HTTP + Banco).
* **Laravel Pint & PHPStan (Nível 8)** (Padronização PSR-12 e análise estática de tipos).
* **Docker & Docker Compose** (Containers isolados para PHP-FPM, Nginx e MySQL).

---

## 2. Decisões Arquiteturais e Engenharia Financeira

### Precisão Monetária em Centavos (`BIGINT` + Value Object `Money`)
* Valores monetários são armazenados e processados exclusivamente como inteiros em **centavos** (`BIGINT`), evitando erros de arredondamento de ponto flutuante (IEEE 754).
* O Value Object imutável `App\Domain\Wallet\Money` realiza a conversão de strings no formato brasileiro (`R$ 1.450,75`) para centavos inteiros usando apenas aritmética inteira.

### Controle de Concorrência e Prevenção de Deadlock
* Todas as mutações de saldo rodam dentro de `DB::transaction()` com bloqueio pessimista (`lockForUpdate()`).
* Em transferências entre duas carteiras, os registros são bloqueados em ordem crescente de ID (`orderBy('id')->lockForUpdate()`), eliminando o risco de *deadlock* quando dois usuários transferem um para o outro simultaneamente.

### Regra de Saldo Negativo e Amortização no Depósito
* Antes de qualquer transferência, a regra de domínio (`TransactionDomainRules::assertTransferAllowed`) valida se o remetente possui saldo suficiente.
* Caso a carteira esteja com saldo negativo (por exemplo, após a reversão de uma operação cujo valor já havia sido transferido), um novo depósito abate automaticamente o saldo devedor e acrescenta o excedente ao saldo disponível.

### Ledger Imutável e Reversão Idempotente
* Nenhuma transação é removida do banco (`DELETE`). O estorno marca a operação original como `REVERSED`, registra a data/motivo e insere um contra-lançamento do tipo `REVERSAL` vinculado por `original_transaction_id` (protegido por constraint `UNIQUE` no banco para impedir duplo estorno).

---

## 3. Como Executar o Projeto

### Opção A: Via Docker Compose (Recomendado — 1 Comando, Zero Configuração)

O projeto conta com inicialização **100% automatizada** via script de bootstrap do container: a cópia do `.env`, a instalação das dependências do Composer, a geração de chave criptográfica (`APP_KEY`) e a execução das *migrations* com os dados de teste (*seeders*) acontecem automaticamente durante o boot.

Basta clonar o repositório e executar:

```bash
docker compose up --build
```
> Ou para rodar em segundo plano: `docker compose up -d --build`

Acesse no navegador: **`http://localhost:8000`**

Para rodar a suíte completa de testes dentro do container:
```bash
docker compose exec app php artisan test
```

---

### Opção B: Execução Local (PHP nativo + SQLite / MySQL)

Caso prefira rodar diretamente na máquina sem Docker:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```
Acesse no navegador: **`http://localhost:8000`**

---

## 4. Contas de Demonstração (Seeds)

O comando `php artisan migrate --seed` cria três contas prontas para avaliação:

| Usuário | E-mail | CPF | Senha | Saldo Inicial | Cenário de Teste |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Gabriel Cobuccio** | `gabriel@cobuccio.com` | `111.222.333-44` | `password123` | **R$ 1.450,00** | Conta com saldo positivo e histórico de movimentações |
| **Bruno Costa** | `bruno@cobuccio.com` | `555.666.777-88` | `password123` | **R$ 800,00** | Conta destinatária/remetente para transferências P2P |
| **Mariana Souza** | `mariana@cobuccio.com` | `999.888.777-66` | `password123` | **-R$ 250,00** | Conta com **saldo negativo** para demonstrar o abatimento automático no depósito |

---

## 5. Testes Automatizados e Qualidade

Para executar a suíte completa de testes unitários (Domínio/TDD) e de integração (Feature):
```bash
php artisan test
```

Para verificar a padronização de código (PSR-12):
```bash
php ./vendor/bin/pint --test
```

Endpoint de Healthcheck (conectividade ativa com o banco de dados):
* `GET /api/health`
