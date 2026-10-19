# Reviewer Link for 30 Days — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/ojsbrReviewerMagicLink/releases/download/1.0.0.0/ojsbrReviewerMagicLink-1.0.0.0.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **OJS 3.5** that keeps the **one-click link** sent to reviewers
(*Settings → Workflow → Review → "Include a secure link in the email invitation to
reviewers"*) working for **30 days** (configurable), however many times it is opened,
**also after reminders**, and that leads to the **login form** instead of an error page
once the link can no longer be used.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.0.0.0 |

Tested on OJS 3.5.0.3 and 3.5.0.5.

## The problem

In OJS 3.5 the link of the review e-mails often fails to let the reviewer in:

1. **Up to 3.5.0-4 the link is single-use**, and the first request uses it up — including
   the `HEAD` request that **Microsoft 365 (Safe Links)** sends about a second before the
   person clicks. The reviewer then sees "invitation not available". The core fixed this
   in 3.5.0-5 ([pkp/pkp-lib#12608](https://github.com/pkp/pkp-lib/issues/12608)), but the
   scanner's `HEAD` still signs a session in.
2. **Every reminder deletes the invitation of the previous e-mail**, whose link then
   answers **404**. The core considers this a design decision.
3. An expired or deleted link answers **404** or "invitation not available" instead of
   offering the login form.

## What it does

- **R1** — each link stays valid for **30 days** from the e-mail (configurable), with
  unlimited clicks; every click within that period signs the reviewer in and opens the
  review (`reviewer/submission`).
- **R2** — `HEAD` and `OPTIONS` requests get a bare `200`: no session, no sign-in, nothing
  changed. They are only counted.
- **R3** — the link of an earlier e-mail keeps working after a reminder: each link sent
  has its own validity.
- **R4** — **never a 404**: an expired link, a wrong key, an unknown or deleted invitation,
  a completed, cancelled or declined review, or a person who may not enter without a
  password all lead to the login form, with the way back to the review (or to the
  reviewer's list) and the notice *"Your access link has expired. Sign in with your username
  and password to continue."* The visitor is never told whether the invitation exists.
- **R5** — the automatic sign-in only happens for people who are **only reviewer** (and,
  if the journal allows it, author or reader) in the journal. A **manager, editor,
  assistant or site administrator** always gets the login form; a disabled account never
  enters.
- **R6** — if the journal does not send the one-click link, the plugin does nothing.
- **R7** — only reviewer invitations are handled; every other invitation (role
  assignment, e-mail change...) follows the core flow unchanged.
- **R8** — every automatic entry is recorded (date, IP, browser, user, review, invitation)
  and listed in the plugin settings; the ignored `HEAD`/`OPTIONS` requests are counted.
- **R10** — optional page with an **"Open the review"** button that posts before signing
  in, against scanners that follow links with `GET`.

## ⚠️ Security notice

The link is a key that can be **reused for 30 days**. Whoever receives a forwarded copy of
the e-mail enters as that reviewer — **but only with the reviewer's role**: anyone who also
manages or edits the journal always has to type the password (R5). Shorten the validity, or
switch the automatic sign-in off, if that does not suit the journal.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so you get `plugins/generic/ojsbrReviewerMagicLink/`. The two
   tables of the plugin are created by its installation.
2. Enable **Reviewer Link for 30 Days** under the *Generic* plugins list.

## Configuration

**Settings → Website → Plugins → Reviewer Link for 30 Days → Settings.**

| Setting | Default | What it does |
| --- | --- | --- |
| Days each link stays valid | **30** | 1 to 365, counted from the e-mail. |
| Sign the reviewer in automatically with the link | **on** | Off: the link always leads to the login form and back to the review. |
| Also when the reviewer is an author | **on** | Authors of the journal may enter with the link. |
| Also when the reviewer is a reader | **on** | Readers of the journal may enter with the link. |
| Page with an "Open the review" button | off | The sign-in only happens after a click on the button (POST with CSRF token). |

The same window lists the latest entries with the link and the number of scanner
requests ignored.

## How it works (technical)

Two documented extension points, no core class or template replaced:

- **A listener on the saving of the core invitation model** (`InvitationModel::saved`,
  an Eloquent event) keeps a copy of the **hash** of every reviewer link — never the key
  itself — in the table `ojsbr_reviewer_links`, the first time it is saved with a key. The
  reminder deletes the core invitation with a mass query (no model events), so the copy
  outlives it.
- **The `LoadHandler` hook** answers `invitation/accept` for reviewer links: known in the
  plugin's table, of type `reviewerAccess` in the core, or unknown. Every other invitation
  is left to the core.

Both are registered in every context — reminders are sent by the scheduled tasks, from the
command line — and each checks whether the plugin is enabled in the journal of the
invitation ([pkp/pkp-lib#11793](https://github.com/pkp/pkp-lib/issues/11793)). The sign-in
uses `Validation::registerUserSession()`, as the core does. A link sent before the plugin
was installed is still honoured, in any status, with the validity counted from its
creation, while the core invitation exists. Links expired for more than 30 days are
deleted.

## Tests

- **PHPUnit** (`tests/*Test.php`, on `PKP\tests\PKPTestCase`): every decision case (valid,
  wrong key, unknown, expired, closed review, disabled user, manager/editor/assistant/site
  administrator, automatic sign-in off, another account signed in, and all 512 combinations
  of the conditions never ending anywhere but the review or the login form); against the
  database, in a rolled-back transaction: the copy outlives the core invitation, a link
  sent before the plugin is honoured, other invitations are left alone, the counters and
  the clean-up; the plugin classes against the installed PKP; the 38 translations. From the
  OJS root:

  ```bash
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/ojsbrReviewerMagicLink/tests"
  ```

- **Cypress** (`cypress/tests/functional/OjsbrReviewerMagicLink.cy.js`, run by
  [pkp-github-actions](https://github.com/pkp/pkp-github-actions) on every push): settings
  saved and a validity out of range refused, `HEAD` with no effect, and unusable links led
  to the login form with the notice, never to a 404.
- **End to end on OJS 3.5.0.5**, with links generated exactly as the e-mail does: `HEAD` and
  then `GET` enters; the same link three times enters three times; the link of the original
  e-mail after two reminders enters (the core alone answers 404); expired, completed review,
  reviewer who is also manager, disabled user, wrong key and unknown id all lead to the login
  form; the journal without the one-click link and the plugin disabled give back the core
  behaviour; a role-assignment invitation follows the core flow; the button page signs in
  only after the POST.

Tests are kept in the repository and are not part of the release package.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**, the same license as OJS.

## AI use

Generative AI (Claude Opus 5.5, by Anthropic) was used to write and run tests, improve the
code and bring it in line with PKP standards. Every change is reviewed and tested by OJSBR,
which is responsible for the published releases.

## Contributing

Issues and pull requests are welcome.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **OJS 3.5** que mantém o **link de acesso de um clique** enviado aos
avaliadores (*Configurações → Fluxo de trabalho → Avaliação → "Incluir um link seguro no
e-mail de convite aos avaliadores"*) válido por **30 dias** (configurável), quantas vezes
for aberto, **também depois dos lembretes**, e que leva à **tela de login** em vez de uma
página de erro quando o link não puder mais ser usado.

**⬇️ Pacote de instalação:** [OJS 3.5](https://github.com/OJSBR/ojsbrReviewerMagicLink/releases/download/1.0.0.0/ojsbrReviewerMagicLink-1.0.0.0.tar.gz) — ou veja todas as [Releases](../../releases).

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja os
> [Créditos e autoria](#créditos-e-autoria).

### Compatibilidade e branches

| Aplicação | Branch | Versão do plugin |
|-----------|--------|------------------|
| OJS 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.0.0.0 |

Testado no OJS 3.5.0.3 e 3.5.0.5.

### O problema

No OJS 3.5 o link dos e-mails de avaliação muitas vezes não deixa o avaliador entrar:

1. **Até a 3.5.0-4 o link é de uso único**, e o primeiro acesso o gasta — inclusive o `HEAD`
   que o **Microsoft 365 (Safe Links)** faz cerca de um segundo antes do clique da pessoa.
   O avaliador vê "convite indisponível". O núcleo corrigiu isso na 3.5.0-5
   ([pkp/pkp-lib#12608](https://github.com/pkp/pkp-lib/issues/12608)), mas o `HEAD` do robô
   ainda abre uma sessão logada.
2. **Cada lembrete apaga o convite do e-mail anterior**, cujo link passa a dar **404**. O
   núcleo considera isso decisão de projeto.
3. Link vencido ou apagado dá **404** ou "convite indisponível" em vez de oferecer a tela de
   login.

### O que faz

- **R1** — cada link vale **30 dias** a partir do e-mail (configurável), com cliques
  ilimitados; cada clique dentro do prazo faz o login do avaliador e abre a avaliação.
- **R2** — `HEAD` e `OPTIONS` recebem `200` sem corpo: nenhuma sessão, nenhum login, nada
  muda. Só são contados.
- **R3** — o link de um e-mail anterior continua valendo depois de um lembrete: cada link
  enviado tem a própria validade.
- **R4** — **nunca 404**: link vencido, chave errada, convite inexistente ou apagado,
  avaliação concluída, cancelada ou recusada, ou pessoa que não pode entrar sem senha vão
  para a tela de login, com retorno à avaliação (ou ao painel do avaliador) e o aviso
  *"Seu link de acesso expirou. Entre com seu usuário e senha para continuar."* O visitante
  nunca fica sabendo se o convite existe.
- **R5** — o login automático só acontece para quem é **apenas avaliador** (e, se a revista
  permitir, autor ou leitor) na revista. **Gerente, editor, assistente ou administrador do
  site** sempre vai para a tela de login; conta desativada nunca entra.
- **R6** — se a revista não envia o link de um clique, o plugin não faz nada.
- **R7** — só convites de avaliador; os demais (atribuição de papel, troca de e-mail...)
  seguem o fluxo do núcleo sem mudança.
- **R8** — cada entrada automática fica registrada (data, IP, navegador, usuário,
  avaliação, convite) e aparece nas configurações do plugin; os `HEAD`/`OPTIONS` ignorados
  são contados.
- **R10** — página opcional com o botão **"Acessar a avaliação"**, que faz um POST antes do
  login, contra robôs que seguem links com `GET`.

### ⚠️ Aviso de segurança

O link é uma chave **reutilizável por 30 dias**. Quem recebe o e-mail encaminhado entra como
aquele avaliador — **mas só com o papel de avaliador**: quem também gerencia ou edita a revista
sempre precisa digitar a senha (R5). Reduza a validade, ou desligue o login automático, se
isso não servir à revista.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/ojsbrReviewerMagicLink/`); as duas tabelas do
plugin são criadas na instalação. Depois ative **Link de avaliador por 30 dias** na lista de
plugins *Genéricos*.

### Configuração

**Configurações → Website → Plugins → Link de avaliador por 30 dias → Configurações.**

| Opção | Padrão | O que faz |
| --- | --- | --- |
| Dias de validade de cada link | **30** | De 1 a 365, contados a partir do e-mail. |
| Fazer o login do avaliador automaticamente pelo link | **ligada** | Desligada: o link sempre leva à tela de login e de volta à avaliação. |
| Também quando o avaliador é autor | **ligada** | Autores da revista podem entrar pelo link. |
| Também quando o avaliador é leitor | **ligada** | Leitores da revista podem entrar pelo link. |
| Página com o botão "Acessar a avaliação" | desligada | O login só acontece depois do clique no botão (POST com token CSRF). |

A mesma janela lista as últimas entradas pelo link e quantos acessos de robôs foram
ignorados.

### Como funciona (técnico)

Dois pontos de extensão documentados, sem substituir classe nem template do núcleo:

- **Um listener na gravação do modelo de convite do núcleo** (`InvitationModel::saved`,
  evento do Eloquent) guarda uma cópia do **hash** de cada link de avaliador — nunca a chave
  — na tabela `ojsbr_reviewer_links`, na primeira vez em que ele é gravado com chave. O
  lembrete apaga o convite do núcleo com uma consulta em massa (sem eventos do modelo), e a
  cópia continua.
- **O hook `LoadHandler`** responde `invitation/accept` para os links de avaliador:
  conhecidos na tabela do plugin, do tipo `reviewerAccess` no núcleo, ou desconhecidos. Os
  demais convites ficam com o núcleo.

Os dois são registrados em qualquer contexto — os lembretes saem das tarefas agendadas, pela
linha de comando — e cada um confere se o plugin está ligado na revista do convite
([pkp/pkp-lib#11793](https://github.com/pkp/pkp-lib/issues/11793)). O login usa
`Validation::registerUserSession()`, como o núcleo. Link enviado antes da instalação do plugin
continua valendo, em qualquer status, com a validade contada da criação, enquanto o convite do
núcleo existir. Links vencidos há mais de 30 dias são apagados.

### Testes

PHPUnit em `tests/` e Cypress em `cypress/tests/functional/` (rodado pelo
[pkp-github-actions](https://github.com/pkp/pkp-github-actions) a cada push), com os comandos da
seção em inglês. **Ponta a ponta no OJS 3.5.0.5**, com links gerados exatamente como o e-mail
gera: `HEAD` e depois `GET` entra; o mesmo link três vezes entra três vezes; o link do e-mail
original depois de dois lembretes entra (só o núcleo dá 404); vencido, avaliação concluída,
avaliador que também é gerente, usuário desativado, chave errada e id inexistente vão para a tela
de login; revista sem link de um clique e plugin desligado devolvem o comportamento do núcleo;
convite de atribuição de papel segue o fluxo do núcleo; a página do botão só faz o login depois do
POST.

Os testes ficam no repositório e não fazem parte do pacote da release.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**, a mesma licença do OJS.

### Uso de IA

Foi usada IA generativa (Claude Opus 5.5, da Anthropic) para escrever e rodar testes, melhorar o
código e alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde
pelas releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
