# ADR 0003 — Ingestão manual por CSV/JSON (em vez de integração ao vivo)

- Status: Aceito
- Data: 2026
- Relacionado: ADR 0001

## Contexto

Os indicadores vêm do Jira (itens/épicos), de planilhas (Score, baseline) e, futuramente, de ServiceNow e Datadog. Integração ao vivo exige backend e credenciais.

## Decisão

No MVP, alimentar os dados por **importação manual** de arquivos: CSV/JSON de extração do Jira, CSV do Score mensal e `.xlsx`/CSV de baseline, processados no navegador. A geração dos indicadores por Sprint é feita client-side a partir dos itens.

## Justificativa

- Sem backend/credenciais, mantém a natureza estática (ADR 0001).
- Formatos CSV/JSON com round-trip reversível servem também de contrato estável e de seed para o banco na produção.

## Consequências

- Positivas: funciona offline; formatos viram pipeline de carga/seed na migração.
- Negativas: processo manual, sujeito a atraso/erro humano; não "mitiga lançamentos manuais" (objetivo que depende da integração automática).
- Mitigação: validação com mensagens de erro por linha; evolução para ETL agendado na fase de produção (ver `architecture.md`).
