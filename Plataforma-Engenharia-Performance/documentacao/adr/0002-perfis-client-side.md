# ADR 0002 — Perfis de acesso como simulação client-side

- Status: Aceito
- Data: 2026
- Relacionado: ADR 0001

## Contexto

O descritivo de jornadas de usuário define 5 perfis (Admin, DM, TM, GPM, TL) com permissões distintas. Autenticação/autorização reais exigem backend. O MVP é estático (ADR 0001).

## Decisão

Implementar perfis como **simulação de visibilidade client-side**: um Seletor de Perfil ajusta menu e telas visíveis, com matriz de permissões (`rbac.js`) como fonte única e "nega por omissão". Deixar explícito na UI que não é segurança real.

## Justificativa

- Permite validar os fluxos por perfil e a UX do menu sem backend.
- A matriz (`rbac.PERMISSIONS`) já modela a autorização que o servidor aplicará na produção.

## Consequências

- Positivas: fluxos por perfil navegáveis; transição direta para RBAC server-side (a matriz vira dados no banco).
- Negativas: não é segurança — qualquer um troca de perfil no navegador.
- Mitigação: banner de aviso na UI; promoção para RBAC no servidor na fase de produção.
