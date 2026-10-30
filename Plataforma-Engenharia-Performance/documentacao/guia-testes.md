# Guia de Testes — PEP

Como rodar a suíte de testes do domínio e das views, e as armadilhas conhecidas do ambiente.

## Visão geral

- **Framework:** [Vitest](https://vitest.dev) + [fast-check](https://fast-check.dev)
  (property-based testing).
- **Escopo:** testes de domínio puro (motor de score, agregações, metas, RBAC, stores) e
  testes de integração/UI com *stub* de DOM (sem jsdom). O app em produção **não** depende de
  `node_modules` — ele abre por `file://`/servidor estático. As dependências de teste ficam
  em `devDependencies`.
- **Organização:** os testes vivem em `aplicacao/tests/*.test.js`. Arquivos `*.property.*`
  são property-based; os demais são de exemplo/unitários.

## Pré-requisitos do ambiente

- **Node.js 24+**. Nessa versão é necessário **Vitest 3.x** (o `package.json` já declara
  `vitest ^3`). Vitest 2.x é **incompatível** com Node 24 e falha com
  `"Vitest failed to find the runner/current suite"`.
- Instalação das dependências de teste (uma vez):
  ```
  npm install
  ```
  Se `npm`/`npx` estiverem bloqueados por ExecutionPolicy (PowerShell), invoque o npm pelo
  Node diretamente:
  ```
  node "C:\Program Files\nodejs\node_modules\npm\bin\npm-cli.js" install --prefix "<caminho>\aplicacao"
  ```

## ⚠️ Armadilha: caminho com espaços

**O Vitest não roda a partir de um caminho que contém espaços** (ex.:
`C:\Kiro - Analises\...`). Os *workers* falham a inicialização com
`"Vitest failed to find the runner"` em 100% dos arquivos, de forma enganosa (parece bug do
Vitest, mas é o caminho).

**Solução:** copie a pasta `aplicacao/` para um caminho sem espaços e rode de lá. Exemplo
(PowerShell):

```powershell
$tmp = "C:\pep_test"
Remove-Item -Recurse -Force $tmp -ErrorAction SilentlyContinue
robocopy "C:\Kiro - Analises\Plataforma-Engenharia-Performance\aplicacao" $tmp /E > $null
& "C:\Program Files\nodejs\node.exe" "$tmp\node_modules\vitest\vitest.mjs" --run --root "$tmp"
```

> Dica: redirecionar a saída para um arquivo (`*> out.txt`) e ler o arquivo é mais confiável
> do que capturar o stdout neste shell.

## Como rodar

A partir de um caminho **sem espaços** com as dependências instaladas:

```powershell
# Via script do package.json (quando npm está disponível)
npm test

# Ou direto pelo Node (contorna ExecutionPolicy)
node ".\node_modules\vitest\vitest.mjs" --run --root "."
```

Opções úteis:
- `--pool=forks --poolOptions.forks.singleFork` — execução mais estável em ambientes
  restritivos (também configurável em `vitest.config.js`).
- Rodar um arquivo específico: acrescente o caminho do teste ao comando.

## Resultado esperado (baseline atual)

A suíte roda com a grande maioria passando. Há **falhas conhecidas não relacionadas ao
domínio**, que não devem bloquear o trabalho:

- `tests/baseline-loader.*` → `Cannot find module 'xlsx'`. O `xlsx` (SheetJS) é carregado por
  **CDN** no app e não está em `devDependencies`. Para rodar esses testes localmente, instale
  `xlsx` como dependência de teste, ou ignore-os.
- Alguns testes de `tests/retro-data.*`, `tests/retro-import.*`, `tests/retro-views-extras.*`
  apresentam falhas pré-existentes nos dados de exemplo da Retro PI.

Os testes de **Score, Agregação, Metas, RBAC e Stores de gestão** passam integralmente.

## Boas práticas ao alterar o código

- Ao **remover/renomear** um módulo ou coleção, atualize os testes correspondentes
  (`rbac.test.js`, `admin-store*.test.js`, `view-admin-gestao.test.js` etc.).
- Mudanças que são **apenas de cor/estilo** não afetam a lógica, mas rode a suíte mesmo assim
  para garantir que substituições em massa (regex) não quebraram sintaxe de JS.
- Para o domínio, prefira estender os **testes de propriedade** (fast-check) com um *oráculo*
  derivado da planilha/pílula de referência, mantendo a reconciliação do Score verificável.
