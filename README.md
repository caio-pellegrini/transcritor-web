# Transcritor Web

Aplicação pessoal de transcrição de áudio e vídeo. O fluxo cobre ambiente, proteção de acesso, upload, estimativa, processamento assíncrono, OpenAI, ElevenLabs, polling, limpeza temporária e exportação.

## Ambiente local

Requisitos: Docker Engine com Docker Compose.

```bash
cp .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose up -d
```

O Compose carrega automaticamente o `docker-compose.override.yml` no ambiente local. A aplicação fica disponível em <http://localhost:8000> e o healthcheck em <http://localhost:8000/up>.

A senha local inicial é o valor de `APP_ACCESS_PASSWORD` no `.env` (`change-me` no arquivo de exemplo). Troque esse valor antes de qualquer exposição pública.

## VPS e HTTPS

Em produção, use somente o `docker-compose.yml`; o override é exclusivo do ambiente local. O Compose publica o Nginx da aplicação em `127.0.0.1:8000`, portanto ele não aceita tráfego direto da internet. O HTTPS termina em um Nginx já instalado no host da VPS, mantendo o projeto com apenas três containers e deixando emissão/renovação do certificado fora da aplicação.

O domínio deve apontar para a VPS e possuir um certificado válido, por exemplo emitido e renovado pelo Certbot. Uma configuração final mínima para o Nginx do host é:

```nginx
server {
    listen 80;
    server_name transcritor.seudominio.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name transcritor.seudominio.com;

    ssl_certificate /etc/letsencrypt/live/transcritor.seudominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/transcritor.seudominio.com/privkey.pem;

    client_max_body_size 510M;
    client_body_timeout 7200s;
    send_timeout 7200s;

    location / {
        proxy_pass http://127.0.0.1:8000;
        proxy_http_version 1.1;
        proxy_request_buffering off;
        proxy_read_timeout 7200s;
        proxy_send_timeout 7200s;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```

Substitua o domínio e os caminhos do certificado. Valide com `sudo nginx -t` antes de recarregar o Nginx do host. HTTPS é obrigatório em produção: além de proteger a senha e as chaves em trânsito, o Google Identity Services exige uma origem segura fora de `localhost`.

Laravel confia no header `X-Forwarded-Proto` desse proxy, força URLs HTTPS em produção e usa cookie de sessão `Secure`, `HttpOnly` e `SameSite=Lax`. O binding em loopback impede que um cliente externo contorne o proxy e falsifique esses headers.

### Primeira subida

1. Instale Docker Engine, Docker Compose e o proxy HTTPS do host. Coloque o projeto, por exemplo, em `/opt/transcritor-web` e entre nesse diretório.
2. Crie a configuração e restrinja sua leitura:

   ```bash
   cp .env.example .env
   chmod 600 .env
   ```

3. Edite `.env`. No mínimo, defina `APP_URL` com a URL HTTPS real, uma senha forte em `APP_ACCESS_PASSWORD`, o fallback cambial e, se for usar Google Docs, `GOOGLE_OAUTH_CLIENT_ID`. Mantenha `APP_ENV=production`, `APP_DEBUG=false`, `QUEUE_FAILED_DRIVER=null` e `SESSION_SECURE_COOKIE=true`.
4. Construa a imagem e gere uma chave. Copie a saída do segundo comando para `APP_KEY` no `.env`:

   ```bash
   docker compose -f docker-compose.yml build --pull app
   docker compose -f docker-compose.yml run --rm app php artisan key:generate --show
   ```

5. Suba os três containers. O entrypoint cria o SQLite, executa migrations e publica os assets compilados no volume compartilhado:

   ```bash
   docker compose -f docker-compose.yml up -d
   docker compose -f docker-compose.yml ps
   curl --fail https://transcritor.seudominio.com/up
   ```

Todos os containers devem aparecer como `healthy`. Confira também o worker e o scheduler:

```bash
docker compose -f docker-compose.yml exec worker supervisorctl status
```

No firewall da VPS, exponha somente SSH e as portas 80/443 do proxy do host. A porta 8000 já fica presa ao loopback pelo Compose e não deve ser republicada externamente.

### Atualização após um deploy

Não atualize enquanto a tela mostrar uma transcrição na fila ou em processamento. Isso evita interromper uma chamada que já pode ter sido cobrada.

1. Faça o backup do SQLite conforme a seção abaixo.
2. Atualize os arquivos do projeto pelo mecanismo usado no deploy.
3. Reconstrua e recrie os containers:

   ```bash
   docker compose -f docker-compose.yml build --pull app
   docker compose -f docker-compose.yml pull nginx
   docker compose -f docker-compose.yml up -d --force-recreate --remove-orphans
   docker compose -f docker-compose.yml ps
   curl --fail https://transcritor.seudominio.com/up
   ```

O `app` executa migrations automaticamente. Em cada inicialização, os assets da imagem são copiados novamente para `app_public`, evitando servir um frontend antigo depois do deploy.

### Backup do SQLite

Faça o backup sem Jobs ativos. O comando `.backup` do SQLite produz uma cópia consistente mesmo com WAL habilitado. O exemplo grava primeiro um arquivo temporário no container, copia-o para o host e o remove em seguida:

```bash
mkdir -p backups
docker compose -f docker-compose.yml exec -T app rm -f /tmp/transcritor-web-backup.sqlite
docker compose -f docker-compose.yml exec -T app sqlite3 /var/www/html/storage/app/database.sqlite ".backup '/tmp/transcritor-web-backup.sqlite'"
docker compose -f docker-compose.yml cp app:/tmp/transcritor-web-backup.sqlite ./backups/database-AAAA-MM-DD.sqlite
docker compose -f docker-compose.yml exec -T app rm -f /tmp/transcritor-web-backup.sqlite
```

Troque `AAAA-MM-DD` pela data do backup. Guarde também uma cópia protegida do `.env`, principalmente de `APP_KEY`; não envie nenhum deles ao controle de versão. Com a fila vazia, o SQLite não contém API keys nem mesmo em payload cifrado.

### Restauração do SQLite

O procedimento abaixo causa uma breve indisponibilidade. Substitua o caminho absoluto do backup antes de executar:

```bash
docker compose -f docker-compose.yml stop worker app
docker compose -f docker-compose.yml run --rm --no-deps -v /opt/transcritor-web/backups/database-AAAA-MM-DD.sqlite:/tmp/restore.sqlite:ro app sh -c 'rm -f /var/www/html/storage/app/database.sqlite /var/www/html/storage/app/database.sqlite-wal /var/www/html/storage/app/database.sqlite-shm && cp /tmp/restore.sqlite /var/www/html/storage/app/database.sqlite && chown www-data:www-data /var/www/html/storage/app/database.sqlite'
docker compose -f docker-compose.yml up -d app worker
docker compose -f docker-compose.yml exec -T app sqlite3 /var/www/html/storage/app/database.sqlite 'PRAGMA integrity_check;'
docker compose -f docker-compose.yml ps
```

O `PRAGMA integrity_check` deve responder `ok`. A remoção explícita dos arquivos `-wal` e `-shm` impede que dados auxiliares antigos sejam aplicados ao backup restaurado.

### Conferir que a API key não sai do navegador

Na página principal, abra as ferramentas do navegador em **Network**, limpe a lista e então cole uma chave-sentinela e selecione um arquivo. Nenhuma nova requisição deve aparecer. Em **Application → Local Storage**, a chave deve existir somente em `transcritor.api-key.openai` ou `transcritor.api-key.elevenlabs`.

Na tela de acesso, `POST /unlock` contém apenas a senha global.

O upload e o recálculo de estimativa não incluem a API key. Em **Network**, o `POST /transcriptions` deve conter somente `media`, `provider`, `model` e `diarization`; o `PATCH /transcriptions/{id}/estimate` contém somente as três opções. O navegador só envia a key no `POST /transcriptions/{id}/start`, disparado depois que o backend valida a mídia e confirma a estimativa.

Ao confirmar, o `POST /transcriptions/{id}/start` envia a chave exclusivamente no header `X-Transcription-Api-Key`. O controller cifra a chave, e o Job inteiro também implementa `ShouldBeEncrypted`. Assim, o payload aguardando no SQLite contém somente texto cifrado. A chave em claro existe apenas como variável local durante a chamada ao provider e nunca entra em Model, sessão, log ou resposta.

`QUEUE_FAILED_DRIVER=null` permanece obrigatório e a migration da fila não cria `failed_jobs`. O Job usa `$tries = 1`, captura falhas esperadas, grava apenas uma mensagem sanitizada e termina sem retentativa automática. Uma nova chamada potencialmente cobrada só acontece após novo envio manual da chave.

## Upload e estimativa

Arquivos MP3, MP4, MPEG, MPGA, M4A, OGG, WAV e WEBM são aceitos até 500 MB e quatro horas. O backend valida extensão e MIME, inspeciona o conteúdo real com `ffprobe` (timeout de 30 segundos) e só então grava a mídia em `storage/app/private/transcriptions/{ulid}`.

O limite de arquivo é exatamente 500 MiB no PHP e na validação Laravel. `post_max_size` e `client_max_body_size` ficam em 510 MiB somente para comportar o envelope multipart; não aumentam o limite aceito pela aplicação. O upload é movido do diretório temporário para o destino no mesmo volume, sem uma segunda cópia de até 500 MiB.

A estimativa usa o catálogo versionado em `config/transcription.php`. Ao escolher um arquivo, o navegador lê apenas seus metadados com uma object URL temporária, calcula a duração e mostra imediatamente custo e disponibilidade de modelos, sem upload. Trocar provider, modelo ou diarização recalcula essa prévia localmente. A cotação USD/BRL é carregada em paralelo pela aplicação, vem da AwesomeAPI (com a chave opcional `AWESOMEAPI_KEY`), fica em cache por 24 horas e usa `USD_BRL_FALLBACK_RATE` caso a consulta falhe.

O botão **Iniciar transcrição** faz o upload sem a API key. O backend continua tratando a duração local como não confiável: valida extensão, MIME e conteúdo real com `ffprobe`, persiste sua própria duração e recalcula o custo. Se a opção continuar válida e o custo não aumentar mais que `max(5%, US$ 0,05)`, a interface envia a key no endpoint de início automaticamente. A interface sempre pede nova confirmação se mudar disponibilidade, necessidade de transcode, lado do limite de 25 minutos ou validade da diarização. Custo igual ou menor e oscilação apenas cambial não interrompem o fluxo. Acima de quatro horas, o servidor rejeita e remove o upload.

Se o navegador não conseguir ler os metadados, o upload continua disponível. Nesse fallback, a tela mostra a estimativa calculada pelo servidor e pede confirmação antes de gastar. `awaiting_confirmation` permanece como estado interno entre upload e início; no caminho normal ele é transitório e não aparece como uma etapa separada.

Para arquivos curtos que já cabem na OpenAI, o GPT-4o Mini é indicado pelo menor preço configurado. Quando a OpenAI exigiria conversão ou não suporta a duração, a ElevenLabs é destacada porque recebe a mídia original e oferece diarização.

Limites adotados nesta versão:

- ElevenLabs `scribe_v2`: até os limites da aplicação, sem preprocessamento;
- OpenAI `gpt-transcribe`: 25 MiB e limite conservador de 25 minutos;
- OpenAI `whisper-1`: 25 MiB, sem teto adicional de duração e sem diarização.

Para os modelos OpenAI, arquivos acima de 25 MiB são convertidos uma única vez para WebM/Opus mono, com alvo de 23 MiB, bitrate máximo de 64 kbps e piso de qualidade de 16 kbps. Se não for possível caber sem romper o piso, a combinação fica indisponível. Não há chunking.

## Estado, polling e limpeza

Ao iniciar, a transcrição passa atomicamente de `awaiting_confirmation` para `queued`. Repetir a confirmação não cria outro Job. O worker prepara a mídia quando necessário, faz uma única chamada ao provider, normaliza texto, idioma e segmentos, persiste o resultado e conclui. A interface consulta `GET /transcriptions/{id}/status` a cada 2,5 segundos com `setTimeout` recursivo e para em `completed`, `failed` ou `expired`.

O Job apaga a origem e o eventual arquivo transcodificado em `finally`, tanto no sucesso quanto na falha. Uploads não confirmados expiram após 24 horas; o `schedule:work` executa `transcriptions:expire` a cada 15 minutos. Para validar manualmente:

```bash
docker compose exec app php artisan schedule:list
docker compose exec app php artisan transcriptions:expire
docker compose exec app php artisan queue:restart
```

Na interface, envie um arquivo pequeno, informe uma chave válida do provider e clique em **Iniciar transcrição**. A tela deve mostrar fila, preparação, transcrição indeterminada, finalização e conclusão; em seguida, `storage/app/private/transcriptions/{ulid}` não deve mais existir.

Para validar os limites sem consumir API, envie o arquivo e observe a estimativa antes de confirmar:

- acima de 25 minutos, os modelos GPT da OpenAI aparecem indisponíveis e a tela explica que a diarização fica na ElevenLabs;
- um OpenAI acima de 25 MiB mostra o bitrate do transcode quando a conversão é viável;
- para um arquivo longo, a ElevenLabs aparece como recomendada.

Os testes de provider usam `Http::fake()` e não consomem créditos. O teste de transcode gera um WAV longo em disco com ffmpeg, comprova que o multipart final fica abaixo de 25 MiB e verifica a remoção de todo o diretório temporário.

### Timeouts

Os limites foram ordenados para que uma camada externa não mate uma operação ainda válida na camada interna:

- upload no proxy do host, Nginx interno e PHP-FPM: 7.200 segundos;
- `ffmpeg`: 3.600 segundos;
- chamada HTTP ao provider: 21.600 segundos (6 horas), com 10 segundos apenas para estabelecer a conexão;
- Job e `queue:work`: 25.200 segundos (7 horas);
- reserva do Job no SQLite: 25.500 segundos;
- tolerância de encerramento do container worker: 7 horas e 5 minutos.

O Job continua com uma única tentativa (`$tries = 1` e `--tries=1`) e falha definitivamente se atingir o timeout; não existe retry HTTP. O `--max-time=43200` apenas recicla o processo do worker entre Jobs. Assim, nenhum componente do projeto corta uma chamada ao provider antes das seis horas. O provider e a rede externa ainda podem impor seus próprios limites, algo que a aplicação não consegue controlar.

### Espaço em disco

`TRANSCRIPTION_MIN_FREE_DISK_MB=512` mantém uma reserva operacional no volume. O espaço é verificado após o upload temporário e novamente antes do Job; quando houver transcode, o tamanho-alvo também entra no cálculo. Se a reserva não existir, a interface mostra uma mensagem explícita e nenhuma API de transcrição é chamada.

Em sucesso ou falha, a mídia é removida antes de persistir o estado terminal, liberando espaço para o WAL do SQLite. Falhas ao mover o upload também removem o diretório parcial. Isso reduz o risco de arquivo órfão e permite registrar o erro mesmo sob pressão de disco. Monitore periodicamente:

```bash
df -h /var/lib/docker
docker system df
docker compose -f docker-compose.yml exec app du -sh /var/www/html/storage
```

## Política de conteúdo

O Nginx envia uma Content-Security-Policy com nonce por requisição. Scripts ficam restritos à própria origem e aos domínios preparados para a integração futura com Google Identity Services e Drive:

- `accounts.google.com` e `apis.google.com` em `script-src`;
- `accounts.google.com`, `apis.google.com`, `www.googleapis.com` e `oauth2.googleapis.com` em `connect-src`;
- `accounts.google.com` em `frame-src`.

O stylesheet do Google Identity Services também é permitido em `style-src`, e o header `Cross-Origin-Opener-Policy: same-origin-allow-popups` preserva o fluxo OAuth por popup sem abrir a página para scripts inline. Produção continua sem `unsafe-inline`, `unsafe-eval` ou hosts de desenvolvimento.

Templates Vue não usam `v-html`; conteúdo externo e nomes de arquivo são renderizados por interpolação escapada.

## Exportações

Quando a transcrição termina, a tela oferece três saídas que partem do mesmo formatador do backend:

- **Copiar texto** usa `navigator.clipboard` e mantém o formato diarizado `[falante] texto [HH:MM:SS - HH:MM:SS]`;
- **Baixar .docx** gera o documento sob demanda com PhpWord e transmite a resposta sem persistir o arquivo;
- **Criar no Google Docs** pede autorização em popup e envia o HTML escapado diretamente do navegador para a API do Google Drive.

O token Google nunca passa pelo backend, não é salvo e existe apenas durante a criação do documento. O escopo solicitado é `https://www.googleapis.com/auth/drive.file`, limitado aos arquivos criados pelo próprio app. A aplicação não usa a Google Docs API: o Drive converte o upload HTML em um documento nativo.

### Configurar o Google Docs

1. Acesse o [Google Cloud Console](https://console.cloud.google.com/), abra o seletor de projetos e escolha **Novo projeto**. Dê um nome ao projeto, crie-o e mantenha-o selecionado.
2. Abra **APIs e serviços → Biblioteca**, procure por **Google Drive API**, entre no resultado e clique em **Ativar**. Não é necessário ativar a Google Docs API.
3. Abra **APIs e serviços → Tela de consentimento OAuth** (em algumas versões do console, **Google Auth Platform**). Configure o público como **External**, preencha os campos obrigatórios e mantenha o app em **Testing**. Em **Data Access**, adicione o escopo `https://www.googleapis.com/auth/drive.file`. Em **Test users**, adicione o seu email e o email do seu pai. Isso evita publicar o app ou passar por verificação enquanto somente vocês dois usam a integração.
4. Abra **APIs e serviços → Credenciais → Criar credenciais → ID do cliente OAuth**. Escolha **Web application**.
5. Em **Authorized JavaScript origins**, adicione a origem HTTPS exata da VPS, por exemplo `https://transcritor.seudominio.com`. Para desenvolvimento, adicione também `http://localhost:8000`. Não inclua caminho nem barra final. Este fluxo de token por popup não precisa de URI de redirecionamento.
6. Crie a credencial, copie o **Client ID** e coloque-o no `.env` do Transcritor:

   ```dotenv
   GOOGLE_OAUTH_CLIENT_ID=000000000000-exemplo.apps.googleusercontent.com
   ```

   O Client ID identifica a aplicação no navegador e é público por design; ele não é um segredo. Reinicie o container da aplicação para recarregar a configuração:

   ```bash
   docker compose up -d --force-recreate app
   ```

Para validar, conclua uma transcrição e clique em **Criar no Google Docs**. Escolha uma das contas cadastradas como test user, autorize o acesso e confirme que o documento abre em uma nova aba. Em seguida, teste bloquear popups e fechar a janela de consentimento: a interface deve explicar a falha e manter **Copiar texto** e **Baixar .docx** disponíveis.

## Diagnóstico

```bash
docker compose ps
docker compose exec app php artisan migrate:status
docker compose exec app php artisan app:diagnose --queue
docker compose exec worker supervisorctl status
```

O comando de diagnóstico confirma PHP, ffmpeg, ffprobe e as opções de concorrência do SQLite. Com `--queue`, também despacha um job; depois de processado, o worker cria `storage/app/private/diagnostics/queue-worker.json`.

Esse JSON é descartável e fica sob `storage/app/private`, cujo `.gitignore` ignora todos os artefatos gerados.

## Logs e segredos

Laravel usa o canal `daily` em produção, nível `warning`, com retenção de 14 dias. Os logs `json-file` dos três containers giram a cada 10 MiB e retêm três arquivos. O access log do Nginx usa o formato `combined`, que não registra headers.

A API key do provider aparece em claro somente na variável local da chamada HTTP. Ela não entra em Models, sessão, contexto, exceções ou mensagens de log; enquanto aguarda na fila, existe apenas no payload cifrado do Job, e `failed_jobs` permanece desabilitado. O `ApiKeyLeakageTest` verifica banco, sessão, resposta e arquivos de log com uma chave-sentinela. O token Google nunca é enviado ao backend: o navegador o usa diretamente contra `www.googleapis.com` e o descarta após a operação.

## Qualidade

```bash
docker compose exec app php artisan test --compact
docker compose exec app composer lint:check
docker compose exec app npm run format:check
docker compose exec app npm run lint:check
docker compose exec app npm run types:check
docker compose exec app npm run build
```

## Containers e persistência

- `app`: PHP-FPM com PHP 8.5, ffmpeg e ffprobe.
- `nginx`: servidor HTTP e proxy para o PHP-FPM.
- `worker`: Supervisor com um `queue:work` e um `schedule:work`.
- `app_storage`: volume persistente compartilhado entre `app` e `worker`, contendo SQLite, WAL, logs e mídia temporária.
- `app_public`: assets compilados compartilhados com o Nginx em produção.

A imagem `prod` não contém Node.js. A extensão GD existe somente porque é uma dependência obrigatória do PhpWord 1.4 usado na exportação `.docx`; ela não é uma dependência de processamento de mídia. Sessões usam cookies, cache usa arquivos e a fila usa o SQLite.

O PHP-FPM fica limitado a dois filhos de até 256 MiB cada, adequado para duas pessoas e mais seguro na VPS pequena. Há somente um Job de transcrição por vez. Não foi imposto um limite rígido de memória ao container do worker: o cache de páginas do Linux é contabilizado pelo cgroup e um teto baixo poderia matar ffmpeg ou uma chamada já cobrada. O limite de concorrência reduz o uso de RAM sem criar esse risco adicional.
