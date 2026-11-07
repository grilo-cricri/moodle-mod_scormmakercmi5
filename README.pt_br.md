# pacote cmi5 (SCORM Maker) (mod_scormmakercmi5)

*[English version](README.md)*

Atividade do Moodle que recebe um pacote **cmi5** (zip) de qualquer ferramenta de autoria, lança o curso para o
aluno, guarda os dados num **LRS embutido** no próprio Moodle (nenhum LRS externo é necessário) e mostra relatórios ao
professor. Pacotes do SCORM Maker com assinatura Ed25519 válida ganham o selo "SCORM Maker verificado" e o **relatório
rico**; os demais pacotes cmi5 ganham o **relatório básico**.

Situação: estável (`0.4.0`).

## Capturas de tela

Tiradas no Moodle 4.5:

- [Configurações da atividade](docs/screenshots/01-activity-settings.png)
- [Aluno abrindo o curso](docs/screenshots/02-learner-launch.png)
- [Relatório do professor](docs/screenshots/03-teacher-report.png)
- [Página do curso com uma atividade cmi5](docs/screenshots/04-course-with-cmi5-activity.png)

## Funcionalidades

- Criar a atividade a partir de um zip cmi5: nome, descrição e lista de unidades atribuíveis (AUs, inclusive as dentro
  de blocos) vêm do `cmi5.xml`. Pacote inválido, que aponta para arquivo ausente ou tem caminho inseguro é recusado
  com uma mensagem que diz o motivo, e nada é criado.
- Substituir o pacote de uma atividade que já tem alunos, com confirmação explícita: tentativas em andamento ficam no
  pacote anterior, as novas usam o novo.
- Verificação de assinatura do SCORM Maker (Ed25519 sobre o manifesto canônico mais o SHA-256 de cada arquivo), com
  três resultados: verificado, não assinado, inválido (o pacote continua funcionando como cmi5 comum, e o professor vê
  o motivo). Pacote de outra ferramenta é apenas "não assinado": abre e recebe o relatório básico, nada é recusado.
- Chaves de assinatura confiáveis e revogadas, geridas pelo administrador.
- Lançamento de cada AU com os parâmetros de lançamento do cmi5, uma registration por aluno por atividade, retomada com
  a mesma registration e o mesmo State, `AnyWindow` embutido com tela cheia, `OwnWindow` em janela própria, modos
  Browse e Review.
- LRS xAPI embutido para as AUs lançadas (Statements, State, Agent Profile, Activity Profile), que aplica as regras do
  LMS do cmi5 e responde 403 com a regra violada. Lotes são tudo-ou-nada.
- Relatório básico: situação do curso e por AU, nota, tempo total, sessões, primeiro e último acesso, filtros por
  situação e grupo, exportação CSV, statements de cada aluno. O modo de grupos é respeitado.
- Relatório rico para pacotes verificados do SCORM Maker, por aluno e por atividade, separado por versão do pacote.
- Atividade dentro das lições (pacotes verificados, perfil 2): alcance, tempo visível e interações por bloco, itens
  abertos, retenção da mídia por faixa de 5 s, cliques sobre a imagem, escolhas do cenário, autoavaliação dos cartões e
  pontos do tour, em totais da turma (por aluno só o resumo). Blocos aninhados aparecem recuados ("Pai › Filho").
  A planilha detalhada tem nove tabelas: Excel ou ODS com uma aba por tabela, CSV num zip com um arquivo por tabela.
- O professor pode dispensar (`waived`) uma AU de um aluno e apagar a tentativa de um aluno, cada ação numa página de
  confirmação.
- Integração opcional: a atividade também pode ser criada a partir do SCORM Maker pelo web service do plugin separado
  [`local_scorm_maker_import`](https://github.com/grilo-cricri/moodle-local_scorm_maker_import). Este plugin funciona
  por inteiro sem ele.
- Backup e restauração (o pacote sempre; registrations, statements e State só com dados de usuário), Privacy API,
  inglês e português do Brasil.

## Requisitos

- Moodle 4.5 a 5.3 (`$plugin->requires = 2024100700`, `$plugin->supported = [405, 503]`).
- PHP 8.1 ou superior (o que a versão do Moodle exigir) com a extensão `sodium` (o Moodle já a exige).
- A configuração do site **Usar argumentos de barra** (`slasharguments`) ligada: o conteúdo cmi5 carrega arquivos e
  alcança o LRS por URLs com caminho de barra. A página da atividade avisa quando está desligada.
- O servidor web precisa repassar o cabeçalho `Authorization` ao PHP (Apache: `CGIPassAuth On`; nginx:
  `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`). Detalhes em [docs/admin.md](docs/admin.md).

## Instalação

1. Descompacte em `mod/scormmakercmi5` (`public/mod/scormmakercmi5` no Moodle 5.1 e posteriores), ou instale o zip em
   Administração do site > Plugins > Instalar plugins.
2. Abra Administração do site > Notificações, ou rode `php admin/cli/upgrade.php --non-interactive`.
3. Confira `slasharguments` e o cabeçalho `Authorization`, como descrito em [docs/admin.md](docs/admin.md).

## Configurações

Administração do site > Plugins > Módulos de atividade > pacote cmi5 (SCORM Maker). Exige `moodle/site:config`. A
tabela completa, com os padrões, está na seção "Configuration" do [README em inglês](README.md). Em resumo:

- Limites do LRS (tamanho do pedido, statements por pedido, tamanho do statement, statements por registration, tamanho
  do State, quantidade de documentos de State por registration — `maxstatedocs`, padrão 100) e limites do pacote (arquivos, tamanho descompactado), todos números inteiros maiores que zero.
- `sessiontimeout`: inatividade após a qual a sessão é abandonada (tarefa horária).
- `requireprefsread`: exige que a AU leia as preferências do aluno antes de `initialized`.
- `allowexternalau` (**desligada por padrão**, ligar é uma decisão do administrador): aceita pacote com AU hospedada fora
  do Moodle (endereço `https`). Ligada, o host externo recebe o actor do aluno (URL do site e id de usuário), a
  registration e um token que lê e grava os statements, o State e o Agent Profile dela. Desligada, esses pacotes são
  recusados na importação e na substituição e as AUs externas já existentes deixam de abrir. Só instalação nova recebe o
  padrão: o site atualizado mantém o que tinha (era ligada por padrão antes da 0.4.0, e a atualização grava `1` onde não
  havia valor); confira.
- `trustedkeys` e `revokedkids`: chaves de assinatura adicionais e revogadas.
- `accepthmlkey` (desligada por padrão): aceita a chave de homologação `sm-hml-2026-09`. A chave de produção
  `sm-prod-2026-10` é sempre aceita. Mantenha desligada em produção.
- Padrões para atividades novas: `browsemode`, `reviewmode`, `grademethod`.

## Capabilities

| Capability | O que permite | Riscos | Papéis padrão |
|---|---|---|---|
| `mod/scormmakercmi5:addinstance` | Adicionar a atividade a um curso (o pacote executa JavaScript na origem do Moodle, como o SCORM). | XSS | Professor (editingteacher), Gestor |
| `mod/scormmakercmi5:view` | Abrir a atividade e lançar o curso. Não é dada a visitantes: o cmi5 precisa de usuário e registration. | nenhum | Estudante, Professor não editor, Professor, Gestor |
| `mod/scormmakercmi5:viewreports` | Ver relatórios e statements de outros alunos. | dados pessoais | Professor não editor, Professor, Gestor |
| `mod/scormmakercmi5:manageattempts` | Apagar a tentativa de um aluno e dispensar uma AU. | perda de dados, dados pessoais | Professor, Gestor |

Chaves de assinatura e limites exigem `moodle/site:config`.

## AU fora do pacote

Uma AU cujo `url` no `cmi5.xml` é um endereço `https` absoluto abre nesse endereço, e não a partir do pacote. O pacote
fica "não assinado" (motivo `external_au`), sem relatório rico nem dos blocos. O CORS de `lrs.php` e `fetch.php` abre só
para a origem exata da AU, sem credenciais. O site externo recebe os parâmetros de lançamento do cmi5 (endpoint do LRS,
chave de busca de uso único, o ator e a registration) e, com o token, lê e grava os statements, o State e o Agent
Profile dessa registration. O aluno vê o aviso de que a atividade está hospedada fora do Moodle. Substituir o pacote
por um com AU externa exige também `mod/scormmakercmi5:addinstance` no curso.

## Privacidade

O plugin guarda dados pessoais dos alunos dentro do Moodle. Nada é enviado a serviço externo, exceto ao site de uma
**AU fora do pacote** (local externo `externalau` nos metadados de privacidade), que o professor escolhe ao enviar
esse pacote e que só abre enquanto o administrador mantiver `allowexternalau` ligada (desligada por padrão em instalação nova). Esse site recebe, na URL de
lançamento, a conta
do aluno (URL do site e id de usuário do Moodle, nunca nome ou e-mail) e a registration, mais um token que lê e grava
os statements, o State e o Agent Profile (preferências) desse aluno. O que esse site guarda está fora das ferramentas
de privacidade do Moodle.

Por aluno e atividade, o plugin guarda: a registration (id, versão do pacote, se o curso foi satisfeito, nota, datas);
a situação de cada AU e bloco (concluído, aprovado, reprovado, dispensado com motivo, notas, datas); as sessões (datas,
modo, duração e hashes de tokens, que nunca são exportados); cada statement enviado pelo curso (quem: o id de usuário
do Moodle; o que aconteceu, respostas, tempos); os documentos State que o curso salvou para retomar; e, uma vez por
aluno, as preferências (Agent Profile do cmi5) no contexto do próprio usuário. Por versão de pacote, registra também
quem a enviou e quando. A nota vai para o livro de notas do Moodle, que a trata por conta própria.

As ferramentas de privacidade do Moodle exportam tudo (organizado por atividade e AU) e apagam, por usuário e por
contexto. As versões do pacote são mantidas (registrations dependem delas) e só o autor do envio fica anônimo. Apagar a
conta do usuário remove também, de todas as atividades, suas registrations, sessões, statements e State e as preferências
salvas, com o mesmo código (notas e conclusão o próprio Moodle remove); as versões de pacote que um professor excluído
enviou ficam, com o autor do envio zerado (`usermodified = 0`).

Metadados de imagem: os arquivos de um pacote do SCORM Maker com assinatura **verificada** são guardados byte a byte,
porque a assinatura cobre cada byte. Suas imagens mantêm os metadados (EXIF, inclusive posição de GPS) exatamente como
assinados, mesmo com o removedor de EXIF do site ligado; retirá-los antes de publicar é responsabilidade do autor.
Pacote **não assinado**, ou com assinatura **inválida**, segue o removedor de EXIF do site.

## Limites conhecidos

- AU hospedada fora do pacote deixa o pacote não assinado e sem relatório dos blocos; não há LRS externo nem
  encaminhamento de dados a outro LRS.
- Pedido com `Origin` que não é o do Moodle (`$CFG->wwwroot`) nem o da AU é recusado: acesse o site pelo host do
  `wwwroot`.
- Uma AU por vez por registration: lançar uma AU abandona a sessão ativa da mesma registration.
- Nomes de arquivo com `& < > " | ' :` ou crase, absolutos, com `..`, `./` ou `//` são recusados.
- Falhas dentro do `config.php` (banco fora do ar, manutenção) respondem HTML, e a AU só vê um status diferente de 2xx.
- O App do Moodle (mobile) não é suportado.

## Conformidade cmi5

Resultado da suíte de testes de LMS cmi5 do [CATAPULT](https://github.com/adlnet/CATAPULT) (LTS, commit `806c0ba`)
contra a versão 0.3.2 do plugin, em Moodle 4.5 e 5.2, PHP 8.3, PostgreSQL 17: `package.js` 20 de 21 (a falha esperada
é o `101-one-thousand-aus.xml`, um `cmi5.xml` avulso com URLs absolutas, e o plugin só importa pacotes zip hospedados
no pacote) e `runtime.js` 66 de 66. Segurança: 22 de 22 cenários no Moodle 4.5; axe-core sem violação WCAG 2.1 AA.

## Notas de segurança para administradores

Duas propriedades são inerentes ao cmi5/xAPI e ao modo como o `mod_scorm` do próprio Moodle funciona; nenhuma é falha a
corrigir, e vale conhecer as duas antes de deixar professores enviarem pacotes.

- **A AU roda na mesma origem do Moodle, em um iframe sem `sandbox` ou, se a AU pedir, em janela própria.** Os dois
  casos são a mesma origem. Uma AU é uma página web do pacote que o professor enviou, servida por este site, então o script dela pode fazer, no navegador do aluno que a abre, o que qualquer script
  do site pode. É o mesmo modelo do `mod_scorm`, e por isso a capability `mod/scormmakercmi5:addinstance` tem
  `RISK_XSS` no editor de papéis do Moodle. Dê essa capability só a papéis em que você confia para enviar conteúdo (por
  padrão Professor e Gestor) e prefira pacotes assinados pelo SCORM Maker.
- **O aluno pode forjar os próprios statements.** A AU envia os statements do navegador do aluno, com um token do
  próprio aluno; quem abre as ferramentas do navegador pode enviar qualquer statement para a própria registration (um
  "passed" inclusive). É assim que o cmi5/xAPI funciona com qualquer LMS e LRS que confie no navegador. Trate os
  resultados desta atividade como autodeclarados e não os use sozinhos como prova de uma avaliação de alto risco; use a
  atividade para aprendizagem e prática, ou confirme o que importa fora do pacote.

## Documentação

Guias (em inglês): [administrador](docs/admin.md), [professor](docs/teacher.md), [aluno](docs/student.md). O histórico
de versões e os avisos de atualização estão em [CHANGES.md](CHANGES.md).

## Suporte e licença

Código-fonte: <https://github.com/grilo-cricri/moodle-mod_scormmakercmi5>. Problemas:
<https://github.com/grilo-cricri/moodle-mod_scormmakercmi5/issues>.

GNU GPL v3 ou posterior, texto completo em [`LICENSE`](LICENSE). Copyright 2026 ScormMaker.com.br.
