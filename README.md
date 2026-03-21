# Plugin GLPI Agendamento

> Plugin para gerenciamento de agendamentos de chamados técnicos no GLPI, com visualização em calendário interativo e integração com Google Calendar.

---

## Sumário

- [Visão Geral](#visão-geral)
- [Funcionalidades](#funcionalidades)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração do Ambiente (Docker)](#configuração-do-ambiente-docker)
- [Configurações do Plugin](#configurações-do-plugin)
- [Integração com Google Calendar](#integração-com-google-calendar)
- [Estrutura do Projeto](#estrutura-do-projeto)
- [Banco de Dados](#banco-de-dados)
- [Permissões](#permissões)
- [Desenvolvimento](#desenvolvimento)
- [Licença](#licença)

---

## Visão Geral

O **Plugin GLPI Agendamento** permite que técnicos e administradores agendem atendimentos vinculados a chamados (tickets) diretamente no GLPI. Os agendamentos são exibidos em um calendário interativo baseado em **FullCalendar**, com suporte a visualizações diária, semanal e mensal, e sincronização opcional com o **Google Calendar**.

| Informação     | Valor                                    |
|----------------|------------------------------------------|
| Versão         | 1.0.0                                    |
| Autor          | Welington Oliveira                       |
| Licença        | GPL-3.0-or-later                         |
| GLPI           | 11.0.0 – 11.9.99                         |
| PHP            | >= 8.2                                   |

---

## Funcionalidades

- **Calendário interativo** com visualização diária, semanal e mensal
- **Agendamento vinculado a tickets** do GLPI com seleção de técnico responsável
- **Drag & drop** para reagendamento diretamente no calendário
- **Motivo de reagendamento** — ao mover um evento, um modal solicita o motivo; o histórico fica registrado como acompanhamento privado no chamado
- **Filtros** por técnico e por status do agendamento
- **Meus Agendamentos** — visão individual para o usuário logado
- **Controle de status**: `agendado`, `confirmado`, `cancelado`, `realizado`
- **Cancelamento com motivo** — ao cancelar, o motivo é registrado como acompanhamento privado no chamado
- **Informações do cliente**: contato e endereço vinculados ao agendamento
- **Integração com TicketTask** — criação automática de tarefa ao agendar
- **Integração com Google Calendar** — sincronização unidirecional GLPI → Google via OAuth 2.0
- **Configurações administrativas** acessíveis na aba de configuração do GLPI
- **Proteção CSRF** em todos os formulários e requisições AJAX

---

## Requisitos

- **GLPI** 11.0.0 ou superior
- **PHP** 8.2 ou superior
- **MySQL** 8.0 ou superior
- Extensão PHP `mysqli` habilitada

---

## Instalação

### Manual

1. Faça o download ou clone este repositório:
   ```bash
   git clone https://github.com/Welington98/plugin-glpi-agendamento.git
   ```

2. Copie o diretório `storage/plugins/agendamento` para a pasta `plugins/` da sua instalação do GLPI:
   ```bash
   cp -r storage/plugins/agendamento /var/www/glpi/plugins/agendamento
   ```

3. Acesse o GLPI como administrador e vá em:
   **Configuração → Plugins → Agendamento → Instalar → Ativar**

> **Importante:** ao desinstalar o plugin, os dados de agendamento são **preservados** no banco de dados. Apenas os tokens OAuth do Google Calendar são removidos. Isso permite reinstalar ou atualizar o plugin sem perda de histórico.

### Via Docker (ambiente de desenvolvimento)

Consulte a seção [Configuração do Ambiente (Docker)](#configuração-do-ambiente-docker).

---

## Configuração do Ambiente (Docker)

### Pré-requisitos

- Docker
- Docker Compose

### Passos

1. Copie o arquivo de variáveis de ambiente:
   ```bash
   cp .env.example .env
   ```

2. Ajuste as variáveis conforme necessário:
   ```env
   GLPI_DB_HOST=db
   GLPI_DB_PORT=3306
   GLPI_DB_NAME=glpi
   GLPI_DB_USER=glpi
   GLPI_DB_PASSWORD=glpi
   ```

3. Suba os serviços:
   ```bash
   docker compose up -d
   ```

4. Acesse o GLPI em: [http://localhost](http://localhost)

> O serviço `glpi` aguarda o banco de dados estar saudável antes de iniciar (`depends_on` com `healthcheck`).

### Serviços

| Serviço | Imagem           | Porta  |
|---------|------------------|--------|
| glpi    | glpi/glpi:11.0.4 | 80     |
| db      | mysql:8.0        | 3306   |

---

## Configurações do Plugin

Acesse as configurações em: **Configuração → Configuração Geral → aba Configuração Agendamento**

| Parâmetro               | Padrão       | Descrição                                    |
|-------------------------|--------------|----------------------------------------------|
| `default_view`          | `week`       | Visualização padrão do calendário            |
| `slot_min_time`         | `07:00`      | Horário de início do expediente              |
| `slot_max_time`         | `21:00`      | Horário de encerramento do expediente        |
| `slot_duration`         | `00:30:00`   | Tamanho de cada slot de tempo                |
| `default_event_duration`| `60`         | Duração padrão dos eventos (em minutos)      |
| `auto_create_task`      | `1`          | Cria TicketTask automaticamente ao agendar   |
| `notify_technician`     | `0`          | Notifica o técnico responsável               |
| `calendar_height`       | `650`        | Altura do calendário em pixels               |
| `business_days`         | `1,2,3,4,5`  | Dias úteis exibidos (1=Seg ... 7=Dom)        |

---

## Integração com Google Calendar

O plugin suporta sincronização unidirecional (**GLPI → Google Calendar**) via OAuth 2.0.

### Configuração

1. Crie um projeto no [Google Cloud Console](https://console.cloud.google.com/) e habilite a **Google Calendar API**
2. Crie credenciais OAuth 2.0 (tipo: Aplicativo Web) com a URI de redirecionamento:
   ```
   http://SEU_GLPI/plugins/agendamento/front/google_callback.php
   ```
3. No GLPI, vá em **Configuração → Configuração Geral → aba Configuração Agendamento** e preencha:
   - `google_client_id`
   - `google_client_secret`
   - `google_calendar_id` (padrão: `primary`)
   - Habilite `google_sync_enabled`

### Uso

Na página **Meus Agendamentos**, cada técnico tem os botões:

| Botão         | Ação                                                        |
|---------------|-------------------------------------------------------------|
| Conectar      | Inicia o fluxo OAuth e vincula a conta Google               |
| Desconectar   | Remove o token OAuth salvo                                  |
| Sincronizar   | Força a sincronização de todos os agendamentos pendentes    |

### Comportamento

- Ao **criar** um agendamento → evento criado no Google Calendar
- Ao **editar** ou **reagendar** → evento atualizado
- Ao **cancelar** → evento removido do Google Calendar
- Os tokens OAuth são armazenados criptografados (AES-256) na tabela `glpi_plugin_agendamento_google_tokens`

---

## Estrutura do Projeto

```
storage/plugins/agendamento/
├── setup.php                        # Inicialização, versão e verificação de pré-requisitos
├── hook.php                         # Instalação e desinstalação (criação/migração de tabelas)
├── front/
│   ├── agendamento.php              # Página principal com calendário e formulário
│   ├── agendamento_calendar.php     # Endpoint AJAX: eventos, reagendamento e metadados
│   ├── meus_agendamentos.php        # Visão de agendamentos do usuário logado
│   ├── ticket_agendamento.form.php  # Formulário de agendamento a partir de um ticket
│   ├── config.php                   # Página de configuração (redireciona para Config do GLPI)
│   ├── config.form.php              # Processamento do formulário de configuração
│   ├── google_action.php            # Ações Google: conectar, desconectar, sincronizar
│   ├── google_callback.php          # Callback OAuth 2.0 do Google
│   └── profile.form.php             # Gerenciamento de perfis e permissões
├── src/
│   ├── Agendamento.php              # Classe principal com toda a lógica de negócio
│   ├── Config.php                   # Classe de configuração (integrada à aba Config do GLPI)
│   ├── MenuAgendamento.php          # Classe de menu e navegação do plugin
│   ├── Profile.php                  # Gerenciamento de direitos e permissões
│   ├── GoogleCalendarAuth.php       # OAuth 2.0 com o Google (autenticação e refresh)
│   └── GoogleCalendarSync.php       # CRUD de eventos no Google Calendar via REST/curl
└── public/
    ├── css/
    │   └── agendamento.css          # Estilos customizados do plugin
    └── js/
        ├── agendamento-calendar.js          # Lógica do calendário principal
        ├── agendamento-tech-calendar.js     # Calendário de agenda do técnico
        └── agendamento-ticket.js            # Integração do agendamento na tela do ticket
```

---

## Banco de Dados

### `glpi_plugin_agendamento_agendamentos`

| Coluna                | Tipo           | Descrição                                            |
|-----------------------|----------------|------------------------------------------------------|
| `id`                  | int            | Chave primária                                       |
| `tickets_id`          | int            | Referência ao ticket do GLPI                         |
| `users_id_tech`       | int            | ID do técnico responsável                            |
| `tecnico_nome`        | varchar(255)   | Nome do técnico (desnormalizado)                     |
| `contato_cliente`     | varchar(255)   | Contato do cliente                                   |
| `endereco_cliente`    | text           | Endereço do cliente                                  |
| `data_hora_inicio`    | datetime       | Data e hora de início do atendimento                 |
| `data_hora_fim`       | datetime       | Data e hora de fim do atendimento                    |
| `status`              | varchar(50)    | Status: agendado/confirmado/cancelado/realizado      |
| `observacoes`         | text           | Observações livres                                   |
| `users_id`            | int            | Usuário que criou o agendamento                      |
| `tickettasks_id`      | int            | ID da TicketTask vinculada                           |
| `google_event_id`     | varchar(255)   | ID do evento no Google Calendar (quando sincronizado)|
| `motivo_reagendamento`| text           | Último motivo informado ao reagendar                 |
| `date_creation`       | timestamp      | Data de criação                                      |
| `date_mod`            | timestamp      | Data da última modificação                           |

**Índices**: `tickets_id`, `data_hora_inicio`, `status`

### `glpi_plugin_agendamento_google_tokens`

| Coluna          | Tipo         | Descrição                                     |
|-----------------|--------------|-----------------------------------------------|
| `id`            | int          | Chave primária                                |
| `users_id`      | int          | Usuário GLPI (único por usuário)              |
| `access_token`  | text         | Token de acesso (criptografado AES-256)       |
| `refresh_token` | text         | Token de renovação (criptografado AES-256)    |
| `token_expiry`  | datetime     | Data de expiração do access token             |
| `calendar_id`   | varchar(255) | ID do calendário Google alvo (padrão: primary)|
| `is_active`     | tinyint(1)   | Indica se a integração está ativa             |
| `date_creation` | timestamp    | Data de criação                               |
| `date_mod`      | timestamp    | Data da última modificação                    |

> **Nota:** ao desinstalar o plugin, a tabela de tokens é removida, mas a tabela de agendamentos é **preservada**.

---

## Permissões

O plugin respeita o sistema de permissões nativo do GLPI:

| Permissão                    | Acesso liberado                              |
|------------------------------|----------------------------------------------|
| `plugin_agendamento` → READ  | Visualização do calendário e agendamentos    |
| `plugin_agendamento` → CREATE| Criação de novos agendamentos               |
| `plugin_agendamento` → UPDATE| Edição, reagendamento e mudança de status    |
| `config` → READ              | Acesso à aba de configuração                 |
| `config` → UPDATE            | Edição das configurações do plugin           |

---

## Desenvolvimento

### Versionamento

O projeto utiliza **Semantic Release** com Conventional Commits. O CHANGELOG é gerado automaticamente em `storage/plugins/agendamento/CHANGELOG.md`.

Tipos de commit reconhecidos:

| Tipo       | Efeito no versionamento |
|------------|-------------------------|
| `feat`     | Minor release           |
| `fix`      | Patch release           |
| `BREAKING` | Major release           |

### Executar localmente

```bash
# Subir o ambiente
docker compose up -d

# Ver logs
docker compose logs -f glpi
```

---

## Licença

Este projeto está licenciado sob a [GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html).


---

## Visão Geral

O **Plugin GLPI Agendamento** permite que técnicos e administradores agendem atendimentos vinculados a chamados (tickets) diretamente no GLPI. Os agendamentos são exibidos em um calendário interativo baseado em **FullCalendar**, com suporte a visualizações diária, semanal e mensal.

| Informação     | Valor                                    |
|----------------|------------------------------------------|
| Versão         | 1.0.0                                    |
| Autor          | Welington Oliveira                       |
| Licença        | GPL-3.0-or-later                         |
| GLPI           | 11.0.0 – 11.9.99                         |
| PHP            | >= 8.2                                   |

---

## Funcionalidades

- **Calendário interativo** com visualização diária, semanal e mensal
- **Agendamento vinculado a tickets** do GLPI com seleção de técnico responsável
- **Drag & drop** para reagendamento diretamente no calendário
- **Filtros** por técnico e por status do agendamento
- **Meus Agendamentos** — visão individual para o usuário logado
- **Controle de status**: `agendado`, `confirmado`, `cancelado`, `realizado`
- **Informações do cliente**: contato e endereço vinculados ao agendamento
- **Integração com TicketTask** — criação automática de tarefa ao agendar
- **Configurações administrativas** acessíveis na aba de configuração do GLPI
- **Proteção CSRF** em todos os formulários e requisições AJAX

---

## Requisitos

- **GLPI** 11.0.0 ou superior
- **PHP** 8.2 ou superior
- **MySQL** 8.0 ou superior
- Extensão PHP `mysqli` habilitada

---

## Instalação

### Manual

1. Faça o download ou clone este repositório:
   ```bash
   git clone https://github.com/Welington98/plugin-glpi-agendamento.git
   ```

2. Copie o diretório `storage/plugins/agendamento` para a pasta `plugins/` da sua instalação do GLPI:
   ```bash
   cp -r storage/plugins/agendamento /var/www/glpi/plugins/agendamento
   ```

3. Acesse o GLPI como administrador e vá em:
   **Configuração → Plugins → Agendamento → Instalar → Ativar**

### Via Docker (ambiente de desenvolvimento)

Consulte a seção [Configuração do Ambiente (Docker)](#configuração-do-ambiente-docker).

---

## Configuração do Ambiente (Docker)

### Pré-requisitos

- Docker
- Docker Compose

### Passos

1. Copie o arquivo de variáveis de ambiente:
   ```bash
   cp .env.example .env
   ```

2. Ajuste as variáveis conforme necessário:
   ```env
   GLPI_DB_HOST=db
   GLPI_DB_PORT=3306
   GLPI_DB_NAME=glpi
   GLPI_DB_USER=glpi
   GLPI_DB_PASSWORD=glpi
   ```

3. Suba os serviços:
   ```bash
   docker compose up -d
   ```

4. Acesse o GLPI em: [http://localhost](http://localhost)

> O serviço `glpi` aguarda o banco de dados estar saudável antes de iniciar (`depends_on` com `healthcheck`).

### Serviços

| Serviço | Imagem           | Porta  |
|---------|------------------|--------|
| glpi    | glpi/glpi:11.0.4 | 80     |
| db      | mysql:8.0        | 3306   |

---

## Configurações do Plugin

Acesse as configurações em: **Configuração → Configuração Geral → aba Configuração Agendamento**

| Parâmetro               | Padrão       | Descrição                                    |
|-------------------------|--------------|----------------------------------------------|
| `default_view`          | `week`       | Visualização padrão do calendário            |
| `slot_min_time`         | `07:00`      | Horário de início do expediente              |
| `slot_max_time`         | `21:00`      | Horário de encerramento do expediente        |
| `slot_duration`         | `00:30:00`   | Tamanho de cada slot de tempo                |
| `default_event_duration`| `60`         | Duração padrão dos eventos (em minutos)      |
| `auto_create_task`      | `1`          | Cria TicketTask automaticamente ao agendar   |
| `notify_technician`     | `0`          | Notifica o técnico responsável               |
| `calendar_height`       | `650`        | Altura do calendário em pixels               |
| `business_days`         | `1,2,3,4,5`  | Dias úteis exibidos (1=Seg ... 7=Dom)        |

---

## Estrutura do Projeto

```
storage/plugins/agendamento/
├── setup.php                   # Inicialização, versão e verificação de pré-requisitos
├── hook.php                    # Instalação e desinstalação (criação de tabelas)
├── front/
│   ├── agendamento.php         # Página principal com calendário e formulário
│   ├── agendamento_calendar.php# Endpoint AJAX: eventos, reagendamento e metadados
│   ├── meus_agendamentos.php   # Visão de agendamentos do usuário logado
│   ├── config.php              # Página de configuração (redireciona para Config do GLPI)
│   └── config.form.php         # Processamento do formulário de configuração
├── src/
│   ├── Agendamento.php         # Classe principal com toda a lógica de negócio
│   ├── Config.php              # Classe de configuração (integrada à aba Config do GLPI)
│   └── MenuAgendamento.php     # Classe de menu e navegação do plugin
└── public/
    ├── css/
    │   └── agendamento.css     # Estilos customizados do plugin
    └── js/
        ├── agendamento-calendar.js          # Lógica do calendário principal
        └── agendamento-tech-calendar.js     # Calendário de agenda do técnico
```

---

## Banco de Dados

O plugin cria a tabela `glpi_plugin_agendamento_agendamentos`:

| Coluna              | Tipo           | Descrição                                |
|---------------------|----------------|------------------------------------------|
| `id`                | int            | Chave primária                          |
| `tickets_id`        | int            | Referência ao ticket do GLPI            |
| `users_id_tech`     | int            | ID do técnico responsável               |
| `tecnico_nome`      | varchar(255)   | Nome do técnico (desnormalizado)        |
| `contato_cliente`   | varchar(255)   | Contato do cliente                      |
| `endereco_cliente`  | text           | Endereço do cliente                     |
| `data_hora_inicio`  | datetime       | Data e hora de início do atendimento    |
| `data_hora_fim`     | datetime       | Data e hora de fim do atendimento       |
| `status`            | varchar(50)    | Status: agendado/confirmado/cancelado/realizado |
| `observacoes`       | text           | Observações livres                      |
| `users_id`          | int            | Usuário que criou o agendamento         |
| `tickettasks_id`    | int            | ID da TicketTask vinculada              |
| `date_creation`     | timestamp      | Data de criação                         |
| `date_mod`          | timestamp      | Data da última modificação              |

**Índices**: `tickets_id`, `data_hora_inicio`, `status`

---

## Permissões

O plugin respeita o sistema de permissões nativo do GLPI:

| Permissão          | Acesso liberado                              |
|--------------------|----------------------------------------------|
| `ticket` → READ    | Visualização do calendário e agendamentos    |
| `config` → READ    | Acesso à aba de configuração                 |
| `config` → UPDATE  | Edição das configurações do plugin           |

---

## Desenvolvimento

### Versionamento

O projeto utiliza **Semantic Release** com Conventional Commits. O CHANGELOG é gerado automaticamente em `storage/plugins/agendamento/CHANGELOG.md`.

Tipos de commit reconhecidos:

| Tipo       | Efeito no versionamento |
|------------|-------------------------|
| `feat`     | Minor release           |
| `fix`      | Patch release           |
| `BREAKING` | Major release           |

### Executar localmente

```bash
# Subir o ambiente
docker compose up -d

# Ver logs
docker compose logs -f glpi
```

---

## Licença

Este projeto está licenciado sob a [GPL-3.0-or-later](https://www.gnu.org/licenses/gpl-3.0.html).
