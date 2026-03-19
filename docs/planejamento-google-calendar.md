# Planejamento: Integração Google Calendar — Plugin Agendamento GLPI

> **Versão:** 1.0  
> **Data:** 19/03/2026  
> **Autor:** Welington Oliveira  
> **Status:** Em planejamento  
> **Plugin:** plugin-glpi-agendamento v1.0.0  

---

## Sumário

1. [Objetivo](#1-objetivo)
2. [Análise de Custos](#2-análise-de-custos)
3. [Arquitetura](#3-arquitetura)
4. [Fases de Implementação](#4-fases-de-implementação)
   - [Fase 1 — Infraestrutura OAuth e Configuração](#fase-1--infraestrutura-oauth-e-configuração)
   - [Fase 2 — Sincronização GLPI → Google Calendar](#fase-2--sincronização-glpi--google-calendar)
   - [Fase 3 — Interface do Técnico](#fase-3--interface-do-técnico)
   - [Fase 4 — Robustez e Tratamento de Erros](#fase-4--robustez-e-tratamento-de-erros)
5. [Modelagem de Dados](#5-modelagem-de-dados)
6. [Endpoints Google Calendar API](#6-endpoints-google-calendar-api)
7. [Dependências e Bibliotecas](#7-dependências-e-bibliotecas)
8. [Formato do Evento no Google Calendar](#8-formato-do-evento-no-google-calendar)
9. [Guia de Configuração Google Cloud](#9-guia-de-configuração-google-cloud)
10. [Estrutura de Arquivos](#10-estrutura-de-arquivos)
11. [Ordem de Execução](#11-ordem-de-execução)
12. [Riscos e Mitigações](#12-riscos-e-mitigações)

---

## 1. Objetivo

Integrar o **Google Calendar** ao plugin de agendamento do GLPI para que os técnicos recebam automaticamente os agendamentos em seus calendários pessoais (Google/Android/iOS), facilitando o acompanhamento dos atendimentos em campo sem necessidade de acessar o GLPI constantemente.

### Premissas

- A sincronização será **unidirecional**: GLPI → Google Calendar (push)
- Cada técnico conecta sua própria conta Google (OAuth 2.0)
- A integração é **opcional** — o plugin funciona normalmente sem ela
- **Custo zero** de infraestrutura e APIs

---

## 2. Análise de Custos

| Item                     | Custo         | Observação                                    |
|--------------------------|---------------|-----------------------------------------------|
| Google Cloud Project     | **Gratuito**  | Não exige cartão de crédito para Calendar API |
| Google Calendar API v3   | **Gratuito**  | Quota: 1.000.000 requisições/dia              |
| Bibliotecas externas     | **Nenhuma**   | Implementação via `curl` nativo do PHP        |
| Infraestrutura adicional | **Nenhuma**   | Roda no mesmo servidor do GLPI                |
| **Total**                | **R$ 0,00**   |                                               |

### Por que é gratuito?

A Google Calendar API v3 não cobra por uso dentro da quota padrão de 1 milhão de requisições diárias. Para um GLPI com centenas de técnicos e milhares de agendamentos, esse limite nunca será atingido.

---

## 3. Arquitetura

### Diagrama de Fluxo

```
┌─────────────────────────────────────────────────────────────┐
│                   Plugin Agendamento GLPI                   │
│                                                             │
│   ┌─────────────┐    ┌──────────────────────┐               │
│   │ Agendamento │───▶│ GoogleCalendarSync   │               │
│   │   .php      │    │       .php           │               │
│   │             │    │                      │               │
│   │ create()    │    │ createEvent()        │               │
│   │ update()    │    │ updateEvent()        │               │
│   │ reschedule()│    │ deleteEvent()        │               │
│   │ updateStat()│    │ refreshToken()       │               │
│   └─────────────┘    └──────────┬───────────┘               │
│                                 │                           │
│   ┌─────────────────────────────┼───────────┐               │
│   │ GoogleCalendarAuth.php      │           │               │
│   │                             │           │               │
│   │ authorize() ◀── Técnico clica "Conectar"│               │
│   │ callback()  ◀── Google redireciona      │               │
│   │ revoke()    ◀── Técnico "Desconectar"   │               │
│   └─────────────────────────────────────────┘               │
└─────────────────────────────────┬───────────────────────────┘
                                  │ HTTPS (REST)
                                  ▼
                    ┌──────────────────────────┐
                    │  Google Calendar API v3  │
                    │                          │
                    │  OAuth 2.0 por técnico   │
                    │  Calendário pessoal      │
                    └──────────────────────────┘
                                  │
                                  ▼
                    ┌──────────────────────────┐
                    │  Dispositivo do Técnico  │
                    │  Google Calendar App     │
                    │  Android / iOS / Web     │
                    └──────────────────────────┘
```

### Fluxo de Sincronização

```
Técnico cria agendamento no GLPI
        │
        ▼
Agendamento salvo no banco GLPI
        │
        ▼
Técnico tem Google Calendar conectado?
        │
   ┌────┴────┐
   │ NÃO     │ SIM
   │         ▼
   │    GoogleCalendarSync::createEvent()
   │         │
   │         ▼
   │    Evento criado no Google Calendar
   │         │
   │         ▼
   │    google_event_id salvo no banco
   │
   ▼
 FIM (agendamento funciona normal sem Google)
```

---

## 4. Fases de Implementação

### Fase 1 — Infraestrutura OAuth e Configuração

**Prioridade:** Alta | **Complexidade:** Média

| #   | Tarefa                                               | Descrição                                                         |
|-----|------------------------------------------------------|-------------------------------------------------------------------|
| 1.1 | Criar projeto Google Cloud                           | Console gratuito, habilitar Calendar API v3                       |
| 1.2 | Configurar credenciais OAuth 2.0                     | Client ID + Client Secret, redirect URI apontando para o GLPI    |
| 1.3 | Criar tabela `glpi_plugin_agendamento_google_tokens` | Armazena tokens OAuth criptografados por técnico                  |
| 1.4 | Criar classe `GoogleCalendarAuth.php`                | Fluxo OAuth: authorize → callback → armazenar → refresh          |
| 1.5 | Criar endpoint `google_callback.php`                 | Recebe o callback do Google após autorização                      |
| 1.6 | Adicionar configs no plugin                          | Campos: Client ID, Client Secret, habilitar/desabilitar sync     |
| 1.7 | Botão "Conectar Google Calendar"                     | Na página "Meus Agendamentos" de cada técnico                    |

**Entregável:** Técnico consegue conectar/desconectar sua conta Google ao GLPI.

---

### Fase 2 — Sincronização GLPI → Google Calendar

**Prioridade:** Alta | **Complexidade:** Média

| #   | Tarefa                                        | Descrição                                                    |
|-----|-----------------------------------------------|--------------------------------------------------------------|
| 2.1 | Criar classe `GoogleCalendarSync.php`         | Métodos de CRUD de eventos via API REST                      |
| 2.2 | Implementar `createEvent()`                   | Cria evento no Google Calendar ao criar agendamento          |
| 2.3 | Implementar `updateEvent()`                   | Atualiza evento ao editar/reagendar                          |
| 2.4 | Implementar `deleteEvent()`                   | Remove evento ao cancelar agendamento                        |
| 2.5 | Adicionar coluna `google_event_id`            | Na tabela de agendamentos, vincula ao evento Google          |
| 2.6 | Integrar nos métodos existentes               | Hooks em `create()`, `update()`, `reschedule()`, `updateStatus()` |

**Entregável:** Agendamentos criados/editados/cancelados no GLPI refletem automaticamente no Google Calendar do técnico.

---

### Fase 3 — Interface do Técnico

**Prioridade:** Média | **Complexidade:** Baixa

| #   | Tarefa                            | Descrição                                                    |
|-----|-----------------------------------|--------------------------------------------------------------|
| 3.1 | Status de conexão                 | Indicador visual (conectado/desconectado) na tela do técnico |
| 3.2 | Botão "Desconectar"              | Revoga token OAuth e limpa dados                             |
| 3.3 | Indicador de sync nos eventos    | Ícone mostrando se o evento está sincronizado com Google     |
| 3.4 | Botão "Sincronizar agora"        | Força re-sync de todos os agendamentos ativos do técnico     |

**Entregável:** Técnico tem visibilidade e controle sobre a integração.

---

### Fase 4 — Robustez e Tratamento de Erros

**Prioridade:** Média | **Complexidade:** Média

| #   | Tarefa                           | Descrição                                                     |
|-----|----------------------------------|---------------------------------------------------------------|
| 4.1 | Retry com exponential backoff    | Se a API Google falhar, tenta novamente (max 3 tentativas)    |
| 4.2 | Log de sincronização             | Registrar sucesso/falha nos logs do GLPI                      |
| 4.3 | Token refresh automático         | Renovar `access_token` usando `refresh_token` quando expirar  |
| 4.4 | Fallback gracioso                | Se Google falhar, agendamento no GLPI funciona normalmente    |
| 4.5 | Alerta de token expirado         | Notificação ao técnico para reconectar caso refresh falhe     |

**Entregável:** Integração estável e resiliente para uso em produção.

---

## 5. Modelagem de Dados

### Nova Tabela: `glpi_plugin_agendamento_google_tokens`

Armazena as credenciais OAuth 2.0 de cada técnico.

| Campo           | Tipo           | Descrição                                       |
|-----------------|----------------|-------------------------------------------------|
| `id`            | INT PK AI      | Identificador único                             |
| `users_id`      | INT NOT NULL   | ID do técnico no GLPI (UNIQUE)                  |
| `access_token`  | TEXT           | Token de acesso criptografado (AES-256)         |
| `refresh_token` | TEXT           | Refresh token criptografado (AES-256)           |
| `token_expiry`  | DATETIME       | Data/hora de expiração do access_token          |
| `calendar_id`   | VARCHAR(255)   | ID do calendário (default: `primary`)           |
| `is_active`     | TINYINT(1)     | Se a conexão está ativa (1) ou revogada (0)     |
| `date_creation` | TIMESTAMP      | Data de criação do registro                     |
| `date_mod`      | TIMESTAMP      | Data da última modificação                      |

```sql
CREATE TABLE IF NOT EXISTS `glpi_plugin_agendamento_google_tokens` (
    `id`             INT NOT NULL AUTO_INCREMENT,
    `users_id`       INT NOT NULL,
    `access_token`   TEXT DEFAULT NULL,
    `refresh_token`  TEXT DEFAULT NULL,
    `token_expiry`   DATETIME DEFAULT NULL,
    `calendar_id`    VARCHAR(255) DEFAULT 'primary',
    `is_active`      TINYINT(1) DEFAULT 1,
    `date_creation`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `date_mod`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Alteração na Tabela Existente

```sql
ALTER TABLE `glpi_plugin_agendamento_agendamentos`
    ADD COLUMN `google_event_id` VARCHAR(255) DEFAULT NULL;
```

### Novas Configurações do Plugin

| Chave                    | Valor Padrão | Descrição                          |
|--------------------------|--------------|-------------------------------------|
| `google_client_id`       | `''`         | Client ID do projeto Google Cloud   |
| `google_client_secret`   | `''`         | Client Secret (criptografado)       |
| `google_sync_enabled`    | `0`          | Habilita/desabilita a integração    |
| `google_calendar_id`     | `'primary'`  | ID do calendário padrão             |

---

## 6. Endpoints Google Calendar API

Apenas **5 endpoints REST** são necessários para a integração completa:

| Método   | Endpoint                                          | Uso no Plugin                     |
|----------|---------------------------------------------------|-----------------------------------|
| `POST`   | `https://oauth2.googleapis.com/token`             | Trocar código por tokens + refresh |
| `POST`   | `https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events` | Criar evento |
| `PUT`    | `https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events/{eventId}` | Atualizar evento |
| `DELETE` | `https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events/{eventId}` | Remover evento |
| `GET`    | `https://www.googleapis.com/calendar/v3/calendars/{calendarId}/events/{eventId}` | Verificar existência |

### Scopes OAuth Necessários

```
https://www.googleapis.com/auth/calendar.events
```

Apenas o scope de eventos — não acessa contatos, e-mail ou outros dados do Google.

---

## 7. Dependências e Bibliotecas

| Componente                | Abordagem                                       | Justificativa                              |
|---------------------------|--------------------------------------------------|--------------------------------------------|
| Google Calendar API v3    | REST via `curl` nativo do PHP                    | Leve, sem dependências externas            |
| OAuth 2.0                 | Implementação manual (3 endpoints)               | Simples, sem overhead de SDK               |
| Criptografia de tokens    | `openssl_encrypt` / `openssl_decrypt` (AES-256)  | Nativo do PHP, usa chave existente do GLPI |
| HTTP Client               | `curl_init()` / `curl_exec()`                    | Disponível em qualquer PHP 8.2+            |

### Por que NÃO usar o Google PHP SDK?

- Adiciona ~30MB de dependências via Composer
- A Calendar API usa apenas 5 endpoints REST simples
- `curl` nativo é mais leve e alinhado ao padrão do plugin
- Evita conflitos de dependências com o GLPI

---

## 8. Formato do Evento no Google Calendar

Quando um agendamento é sincronizado, o evento no Google Calendar terá o seguinte formato:

### Campos do Evento

| Campo Google     | Valor                                                           |
|------------------|-----------------------------------------------------------------|
| `summary`        | `[GLPI #123] Atendimento - Título do Chamado`                  |
| `location`       | Endereço do cliente (`endereco_cliente`)                        |
| `description`    | Detalhes completos (veja template abaixo)                       |
| `start.dateTime` | `data_hora_inicio` (ISO 8601)                                   |
| `end.dateTime`   | `data_hora_fim` (ISO 8601)                                      |
| `colorId`        | Baseado no status (agendado=azul, confirmado=verde, etc.)       |
| `reminders`      | 30 minutos antes (popup) + 1 hora antes (popup)                 |

### Template da Descrição

```
📋 Chamado GLPI #123
Título: Manutenção preventiva - Servidor principal

👤 Técnico: João Silva
📞 Contato Cliente: (11) 99999-9999
📍 Endereço: Rua Exemplo, 123 - Centro - São Paulo/SP

📌 Status: Agendado
📝 Observações: Levar ferramentas específicas para rack

🔗 Abrir no GLPI: https://glpi.empresa.com/front/ticket.form.php?id=123
```

### Mapeamento de Cores (colorId)

| Status      | Cor Google Calendar | colorId |
|-------------|---------------------|---------|
| agendado    | Azul (Blueberry)    | `9`     |
| confirmado  | Verde (Sage)        | `2`     |
| cancelado   | Vermelho (Tomato)   | `11`    |
| realizado   | Cinza (Graphite)    | `8`     |

---

## 9. Guia de Configuração Google Cloud

### Pré-requisitos

- Conta Google (pessoal ou Workspace)
- Acesso ao [Google Cloud Console](https://console.cloud.google.com)

### Passo a Passo

#### 1. Criar Projeto

1. Acesse o Google Cloud Console
2. Clique em **"Selecionar projeto"** → **"Novo projeto"**
3. Nome: `GLPI Agendamento` (ou outro de sua preferência)
4. Clique em **"Criar"**

#### 2. Habilitar a API

1. No menu lateral, vá em **"APIs e serviços"** → **"Biblioteca"**
2. Pesquise por **"Google Calendar API"**
3. Clique em **"Ativar"**

#### 3. Configurar Tela de Consentimento OAuth

1. Vá em **"APIs e serviços"** → **"Tela de consentimento OAuth"**
2. Selecione **"Externo"** (ou "Interno" se for Google Workspace)
3. Preencha:
   - Nome do aplicativo: `GLPI Agendamento`
   - E-mail de suporte: seu e-mail
   - Domínios autorizados: domínio do seu GLPI
4. Em **Escopos**, adicione: `https://www.googleapis.com/auth/calendar.events`
5. Em **Usuários de teste**, adicione os e-mails dos técnicos (enquanto em modo teste)

#### 4. Criar Credenciais OAuth 2.0

1. Vá em **"APIs e serviços"** → **"Credenciais"**
2. Clique em **"Criar credenciais"** → **"ID do cliente OAuth"**
3. Tipo de aplicativo: **"Aplicativo da Web"**
4. Nome: `GLPI Plugin Agendamento`
5. **URIs de redirecionamento autorizados:**
   ```
   https://seu-glpi.com/plugins/agendamento/front/google_callback.php
   ```
6. Clique em **"Criar"**
7. **Copie o Client ID e o Client Secret**

#### 5. Configurar no GLPI

1. No GLPI, vá em **Configuração → Agendamento → Google Calendar**
2. Cole o **Client ID** e o **Client Secret**
3. Marque **"Habilitar sincronização"**
4. Salve

#### 6. Conectar Técnicos

1. Cada técnico acessa **"Meus Agendamentos"**
2. Clica em **"Conectar Google Calendar"**
3. Autoriza o acesso na tela do Google
4. Pronto! Os agendamentos serão sincronizados automaticamente

---

## 10. Estrutura de Arquivos

### Arquivos Novos

```
storage/plugins/agendamento/
├── src/
│   ├── GoogleCalendarAuth.php      # Fluxo OAuth 2.0 (authorize, callback, revoke)
│   └── GoogleCalendarSync.php      # CRUD de eventos no Google Calendar
├── front/
│   └── google_callback.php         # Endpoint de callback OAuth
```

### Arquivos Modificados

```
storage/plugins/agendamento/
├── hook.php                        # + criação da tabela google_tokens
│                                   # + coluna google_event_id
├── setup.php                       # + registro do GoogleCalendarAuth
├── src/
│   ├── Agendamento.php             # + hooks de sync nos métodos CRUD
│   └── Config.php                  # + campos de config Google
├── front/
│   ├── config.php                  # + seção Google Calendar
│   └── meus_agendamentos.php       # + botão Conectar/Desconectar
├── public/
│   ├── css/agendamento.css         # + estilos do botão Google
│   └── js/agendamento-calendar.js  # + indicador de sync
```

### Descrição das Novas Classes

#### `GoogleCalendarAuth.php`

```
Responsabilidades:
├── getAuthorizationUrl()     → Gera URL de autorização OAuth
├── handleCallback()          → Processa retorno do Google (troca code por tokens)
├── refreshAccessToken()      → Renova access_token usando refresh_token
├── revokeAccess()            → Revoga tokens e desconecta técnico
├── getValidToken()           → Retorna token válido (renova se expirado)
├── isConnected()             → Verifica se técnico tem conexão ativa
├── encryptToken()            → Criptografa token para armazenamento
└── decryptToken()            → Descriptografa token para uso
```

#### `GoogleCalendarSync.php`

```
Responsabilidades:
├── createEvent()             → Cria evento no Google Calendar
├── updateEvent()             → Atualiza evento existente
├── deleteEvent()             → Remove evento
├── syncAgendamento()         → Sincroniza um agendamento (create ou update)
├── syncAllForTechnician()    → Re-sincroniza todos os agendamentos ativos
├── buildEventPayload()       → Monta JSON do evento Google
├── parseStatusToColor()      → Converte status GLPI para colorId Google
└── handleApiError()          → Trata erros da API com retry
```

---

## 11. Ordem de Execução

### Roadmap Visual

```
                    FASE 1                          FASE 2
             Infraestrutura OAuth            Sincronização Eventos
            ┌─────────────────────┐       ┌─────────────────────┐
Semana 1-2  │ 1.3 Tabela tokens   │       │ 2.5 Coluna event_id │
            │ 1.4 Auth class      │──────▶│ 2.1 Sync class      │
            │ 1.5 Config fields   │       │ 2.2 createEvent     │
            │ 1.6 Callback        │       │ 2.3 updateEvent     │
            │ 1.7 Botão conectar  │       │ 2.4 deleteEvent     │
            └─────────────────────┘       │ 2.6 Hooks no CRUD   │
                                          └─────────────────────┘
                                                    │
                    FASE 3                          │         FASE 4
              Interface Técnico                     │    Robustez Produção
            ┌─────────────────────┐                 │  ┌─────────────────────┐
Semana 3    │ 3.1 Status conexão  │◀────────────────┘  │ 4.1 Retry backoff   │
            │ 3.2 Desconectar     │──────────────────▶ │ 4.2 Logs            │
            │ 3.3 Ícone sync      │                    │ 4.3 Auto refresh    │
            │ 3.4 Sync manual     │                    │ 4.4 Fallback        │
            └─────────────────────┘                    │ 4.5 Alertas         │
                                                       └─────────────────────┘
```

### Sequência de Tarefas

```
1.3 → 1.4 → 1.5 → 1.6 → 1.7 → 2.5 → 2.1 → 2.2 → 2.3 → 2.4 → 2.6 → 3.1 → 3.2 → 3.3 → 3.4 → 4.1 → 4.2 → 4.3 → 4.4 → 4.5
```

### Entregas Incrementais

| Entrega | Fases    | Resultado                                                   |
|---------|----------|-------------------------------------------------------------|
| **MVP** | 1 + 2    | Técnico conecta Google e agendamentos sincronizam           |
| **UX**  | + 3      | Interface visual para gerenciar conexão e ver status sync   |
| **Prod**| + 4      | Resiliente, com logs, retry e fallback para produção        |

---

## 12. Riscos e Mitigações

| #  | Risco                                        | Probabilidade | Impacto | Mitigação                                                              |
|----|----------------------------------------------|---------------|---------|------------------------------------------------------------------------|
| R1 | Técnico não conecta conta Google             | Média         | Baixo   | Sync é opcional; plugin funciona 100% sem Google                       |
| R2 | Token OAuth expira e técnico não reconecta   | Média         | Médio   | Refresh automático do token; notificação se refresh falhar             |
| R3 | Google Calendar API fora do ar               | Baixa         | Médio   | Fallback gracioso; agendamento GLPI funciona normal; retry automático  |
| R4 | Quota da API excedida                        | Muito Baixa   | Alto    | 1M req/dia é mais que suficiente; monitorar uso                        |
| R5 | Vazamento de tokens OAuth                    | Baixa         | Alto    | Criptografia AES-256; tokens nunca expostos no frontend                |
| R6 | Conflito de versão com GLPI                  | Baixa         | Médio   | Usar apenas APIs estáveis do GLPI; testar em versões suportadas        |
| R7 | Técnico remove permissão pelo Google         | Baixa         | Baixo   | Detectar erro 401 e marcar conexão como inativa; solicitar reconexão   |

---

## Anexo A — Referências

| Recurso                              | URL                                                                           |
|--------------------------------------|-------------------------------------------------------------------------------|
| Google Calendar API v3 Docs          | https://developers.google.com/calendar/api/v3/reference                       |
| OAuth 2.0 para Web Server Apps       | https://developers.google.com/identity/protocols/oauth2/web-server            |
| Google Cloud Console                 | https://console.cloud.google.com                                              |
| FullCalendar (já usado no plugin)    | https://fullcalendar.io                                                       |
| GLPI Plugin Development              | https://glpi-developer-documentation.readthedocs.io                           |

---

## Anexo B — Glossário

| Termo            | Definição                                                                 |
|------------------|---------------------------------------------------------------------------|
| **OAuth 2.0**    | Protocolo de autorização que permite ao GLPI acessar o Google Calendar    |
| **Access Token** | Token temporário (~1h) usado para autenticar chamadas à API               |
| **Refresh Token**| Token permanente usado para obter novos access tokens sem interação       |
| **Client ID**    | Identificador público da aplicação no Google Cloud                        |
| **Client Secret**| Chave secreta da aplicação (nunca exposta ao usuário)                     |
| **Calendar ID**  | Identificador do calendário Google (`primary` = calendário principal)     |
| **Scope**        | Permissão solicitada (apenas `calendar.events` neste caso)                |
| **colorId**      | Código de cor do Google Calendar para eventos (1-11)                      |
