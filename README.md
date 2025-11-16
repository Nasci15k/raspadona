# Raspadinha - Backend (PHP + MySQL com Docker)

Este é o backend da aplicação de raspadinhas, desenvolvido em PHP com banco de dados MySQL. Para garantir a compatibilidade com o Render, estamos utilizando **Docker**.

## Estrutura do Projeto

```
backend/
├── ... (arquivos PHP)
├── conexao.php           # Configuração de conexão com BD (usa variáveis de ambiente)
├── index.php             # Arquivo principal
├── database.sql          # Script SQL do banco de dados
├── .env.example          # Exemplo de variáveis de ambiente
├── Dockerfile            # Configuração do ambiente PHP com Docker
├── .dockerignore         # Arquivos a serem ignorados na imagem Docker
├── render.yaml           # Configuração para Render (usando Docker)
└── composer.json         # Dependências do projeto
```

## Configuração de Variáveis de Ambiente

Antes de fazer o deploy, configure as seguintes variáveis de ambiente no Render:

1. `DB_HOST` - Host do banco de dados MySQL
2. `DB_PORT` - Porta do banco de dados (padrão: 3306)
3. `DB_NAME` - Nome do banco de dados
4. `DB_USER` - Usuário do banco de dados
5. `DB_PASSWORD` - Senha do banco de dados
6. `SITE_NAME` - Nome do site
7. `SITE_LOGO` - URL do logo do site
8. `DEPOSITO_MIN` - Depósito mínimo
9. `SAQUE_MIN` - Saque mínimo
10. `CPA_PADRAO` - CPA padrão
11. `REVSHARE_PADRAO` - Revshare padrão
12. `APP_ENV` - Ambiente (production/development)
13. `APP_DEBUG` - Debug ativo (true/false)

## Deploy no Render (Usando Docker)

### Passo 1: Criar um Repositório Git

```bash
git init
git add .
git commit -m "Initial commit - Dockerized PHP"
git remote add origin https://github.com/seu_usuario/raspadinha-backend.git
git push -u origin main
```

### Passo 2: Conectar ao Render

1. Acesse https://render.com
2. Clique em "New +" e selecione **"Web Service"**
3. Conecte seu repositório GitHub
4. Selecione o repositório `raspadinha-backend`
5. Configure as seguintes informações:
   - **Name**: raspadinha-backend
   - **Environment**: **Docker** (Selecione esta opção)
   - **Build Command**: *Deixe em branco, pois o Dockerfile cuida disso.*
   - **Start Command**: *Deixe em branco, pois o Dockerfile cuida disso.*
   - **Plan**: Free (ou pago, conforme necessário)

### Passo 3: Adicionar Variáveis de Ambiente

No painel do Render, vá para "Environment" e adicione todas as variáveis de ambiente listadas acima.

### Passo 4: Configurar Banco de Dados MySQL

No Render, você pode usar um serviço MySQL externo ou criar um banco de dados MySQL no próprio Render.

**Opção Recomendada: Usar um Banco de Dados Gerenciado**

1. Crie um banco de dados MySQL em um provedor externo (ex: Planetscale, AWS RDS) ou no próprio Render.
2. Importe o arquivo `database.sql` para o novo banco de dados.
3. Configure as variáveis de ambiente com as credenciais do banco de dados.

### Passo 5: Deploy

Após configurar tudo, o Render fará o deploy automaticamente, construindo a imagem Docker e iniciando o serviço.

## Suporte

Para mais informações sobre o Render e Docker, visite: https://render.com/docs
