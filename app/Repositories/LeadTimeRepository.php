<?php

namespace App\Repositories;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * LeadTimeRepository
 * ──────────────────
 * Gráfico LEAD TIME = cycle time médio por etapas (SOMENTE LEITURA), sobre
 * vw_lead_time_item (item de VALOR ENTREGUE da extração, etapas em dias úteis).
 *
 * REGRA (decidida pelo usuário):
 *   - população: itens de entrega de valor ENTREGUES (Aceito, Pendente de Produção,
 *     Em produção) cujo sprint_ref atual está na JANELA;
 *   - etapas: Desenvolvimento, Homologação, Implantação (SEM ideação); cada uma pode ser NULL;
 *   - lead time do item = soma das etapas que ele TEM; item sem nenhuma etapa fica
 *     FORA das médias (contado em itens_sem_etapa);
 *   - média do time = AVG do lead time dos itens do time;
 *     média da jornada = AVG sobre TODOS os itens da jornada (não a média das médias);
 *   - segmento da barra = CONTRIBUIÇÃO da etapa = Σ etapa (NULL como 0) / nº de itens
 *     da população → Dev + Hom + Impl = lead time médio;
 *   - media_nos_itens = Σ etapa / n, onde n = itens que TÊM a etapa (modo da planilha);
 *   - p50 = mediana do lead time dos itens (média dos dois centrais se n par); max = maior.
 *
 * Filtros, PI, JANELA e times vêm de PrevisibilidadeRepository::resolverContexto()
 * (mesma resolução da Previsibilidade e do Planejado x Realizado). Só entram times COM
 * extração na PI; os demais da seleção vão em times_sem_extracao.
 */
class LeadTimeRepository
{
    public const ETAPAS = ['desenvolvimento', 'homologacao', 'implantacao'];

    private PDO $pdo;
    private PrevisibilidadeRepository $base;

    public function __construct(?PrevisibilidadeRepository $base = null, ?PDO $pdo = null)
    {
        $this->pdo  = $pdo ?? Database::pdo();
        $this->base = $base ?? new PrevisibilidadeRepository($this->pdo);
    }

    /**
     * @param array{jornada?:string,time?:string,versao?:string,sprint?:string} $filtros
     * @throws InvalidArgumentException filtro inexistente/inconsistente
     */
    public function consultar(array $filtros, ?string $hoje = null): array
    {
        $ctx   = $this->base->resolverContexto($filtros, $hoje);
        $pi    = $ctx['pi'];
        $times = $ctx['times'];

        $avisos = [];
        if ($pi === null) {
            $avisos[] = 'Nenhuma PI cadastrada no catálogo.';
        } elseif ($ctx['sprints'] === []) {
            $avisos[] = 'Nenhuma sprint de valor fechada nesta PI ainda. Escolha uma sprint no filtro ou aguarde o fechamento.';
        }
        if ($pi !== null && $times === []) {
            $avisos[] = 'Nenhum time da seleção possui itens extraídos do Jira para ' . $pi['desc_pi'] . '.';
        }
        if ($ctx['times_sem_extracao']) {
            $nomes = array_column($ctx['times_sem_extracao'], 'time');
            $resto = count($nomes) - 8;
            $avisos[] = count($nomes) . ' time(s) sem extração na ' . ($pi['desc_pi'] ?? 'PI') . ' ficaram fora do gráfico: '
                . implode(', ', array_slice($nomes, 0, 8)) . ($resto > 0 ? " e mais {$resto}" : '') . '.';
        }

        $calcula = $pi !== null && $ctx['sprints'] !== [] && $times !== [];
        $keys    = array_column($times, 'projeto_key');
        $itens   = $calcula ? $this->itens($pi['desc_pi'], $ctx['sprints'], $keys) : [];

        // Agrupa por jornada (ordem do catálogo), mantendo a lista de itens para a média da jornada.
        $jornadas = [];
        foreach ($times as $t) {
            $rows = $itens[$t['projeto_key']] ?? [];
            $j = &$jornadas[$t['jornada']];
            $j ??= ['jornada' => $t['jornada'], 'times' => [], '_itens' => []];
            $j['times'][] = ['time' => $t['time'], 'projeto_key' => $t['projeto_key']] + $this->estatisticas($rows);
            array_push($j['_itens'], ...$rows);
            unset($j);
        }
        $porJornada = [];
        $todos = [];
        foreach ($jornadas as $j) {
            $porJornada[] = ['jornada' => $j['jornada'], 'times' => $j['times'], 'media_jornada' => $this->estatisticas($j['_itens'])];
            array_push($todos, ...$j['_itens']);
        }
        $total = $this->estatisticas($todos);

        if ($calcula && $total['itens'] === 0) {
            $avisos[] = 'Nenhum item de entrega de valor entregue na janela para a seleção.';
        }

        $diag = ['itens_fora_por_tipo' => [], 'valor_nao_entregue_por_status' => [], 'itens_sem_etapa_por_time' => []];
        if ($calcula) {
            $diag = $this->diagnosticos($pi['desc_pi'], $ctx['sprints'], $keys, $times, $itens);
        }

        return [
            'janela' => [
                'origem'  => $ctx['origem'],
                'pi'      => $pi['desc_pi'] ?? null,
                'sprints' => $ctx['sprints'],
                'detalhe' => $ctx['sprints_detalhe'],
            ],
            'filtros'     => $ctx['filtros'],
            'por_jornada' => $porJornada,
            'total'       => $total,
            'avisos'      => $avisos,
            'times_sem_extracao' => $ctx['times_sem_extracao'],
            'diagnosticos' => $diag,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════

    /** Placeholders únicos (prepares nativos não repetem nomeados). */
    private function in(string $p, array $vals, array &$par): string
    {
        $ph = [];
        foreach (array_values($vals) as $i => $v) { $ph[] = ":{$p}{$i}"; $par[":{$p}{$i}"] = $v; }
        return implode(',', $ph);
    }

    /**
     * Itens de valor entregues na janela, por projeto_key (inclui os sem etapa,
     * que são separados em estatisticas()).
     * @return array<string,list<array{d:?int,h:?int,i:?int,lt:?int,qtd:int,st:?string,fi:bool}>>
     */
    private function itens(string $descPi, array $sprints, array $keys): array
    {
        $par = [':pi' => $descPi];
        $s = $this->in('s', $sprints, $par); $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT projeto_key, dias_desenvolvimento AS d, dias_homologacao AS h, dias_implantacao AS i,
                    lead_time_item AS lt, qtd_etapas AS qtd, status AS st, fim_implantacao AS fi
               FROM vw_lead_time_item
              WHERE pi = :pi AND sprint_ref IN ($s) AND projeto_key IN ($k)"
        );
        $st->execute($par);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['projeto_key']][] = [
                'd' => $r['d'] === null ? null : (int)$r['d'],
                'h' => $r['h'] === null ? null : (int)$r['h'],
                'i' => $r['i'] === null ? null : (int)$r['i'],
                'lt' => $r['lt'] === null ? null : (int)$r['lt'],
                'qtd' => (int)$r['qtd'],
                'st' => $r['st'],
                'fi' => $r['fi'] !== null,
            ];
        }
        return $out;
    }

    /** Estatísticas de um conjunto de itens (time, jornada ou seleção). */
    private function estatisticas(array $rows): array
    {
        $semEtapa = 0;
        $lts = [];
        $soma = ['d' => 0, 'h' => 0, 'i' => 0];
        $n    = ['d' => 0, 'h' => 0, 'i' => 0];
        foreach ($rows as $r) {
            if ($r['qtd'] === 0) { $semEtapa++; continue; }
            $lts[] = $r['lt'];
            foreach (['d', 'h', 'i'] as $e) {
                if ($r[$e] !== null) { $soma[$e] += $r[$e]; $n[$e]++; }
            }
        }
        $itens = count($lts);
        $resumo = $this->implantacaoResumo($rows);
        $etapa = function (string $e) use ($soma, $n, $itens): array {
            return [
                'contribuicao'    => $itens > 0 ? round($soma[$e] / $itens, 4) : null,
                'media_nos_itens' => $n[$e] > 0 ? round($soma[$e] / $n[$e], 4) : null,
                'n'               => $n[$e],
            ];
        };
        sort($lts);
        $p50 = null;
        if ($itens > 0) {
            $m = intdiv($itens, 2);
            $p50 = $itens % 2 ? (float)$lts[$m] : ($lts[$m - 1] + $lts[$m]) / 2;
        }
        return [
            'itens'            => $itens,
            'itens_sem_etapa'  => $semEtapa,
            'desenvolvimento'  => $etapa('d'),
            'homologacao'      => $etapa('h'),
            'implantacao'      => $etapa('i'),
            'implantacao_resumo' => $resumo,
            'lead_time_medio'  => $itens > 0 ? round(array_sum($lts) / $itens, 4) : null,
            'p50'              => $p50,
            'max'              => $itens > 0 ? (int)max($lts) : null,
        ];
    }

    /**
     * Sinalização de implantação pendente (NÃO altera nenhuma média de lead time).
     * População = todos os itens de valor entregues do conjunto (inclui os sem etapa).
     * concluída = tem fim_implantacao; pendente de produção = status 'Pendente de Produção'
     * sem fim; sem_fim_outros = sem fim e outro status (distribuição em sem_fim_outros_por_status).
     */
    private function implantacaoResumo(array $rows): array
    {
        $entregues = count($rows);
        $concl = 0; $pend = 0; $outros = 0; $porStatus = [];
        foreach ($rows as $r) {
            if ($r['fi']) { $concl++; continue; }
            if ($r['st'] === 'Pendente de Produção') { $pend++; continue; }
            $outros++;
            $k = $r['st'] ?? '(vazio)';
            $porStatus[$k] = ($porStatus[$k] ?? 0) + 1;
        }
        arsort($porStatus);
        return [
            'entregues'         => $entregues,
            'concluidas'        => $concl,
            'pendentes_producao' => $pend,
            'sem_fim_outros'    => $outros,
            'sem_fim_outros_por_status' => $porStatus === [] ? new \stdClass() : $porStatus,
            'pct_concluidas'    => $entregues > 0 ? round($concl / $entregues * 100, 1) : null,
        ];
    }

    private function diagnosticos(string $descPi, array $sprints, array $keys, array $times, array $itens): array
    {
        $base = function (string $extra, string $col) use ($descPi, $sprints, $keys) {
            $par = [':pi' => $descPi];
            $s = $this->in('ds', $sprints, $par); $k = $this->in('dk', $keys, $par);
            $st = $this->pdo->prepare(
                "SELECT COALESCE($col, '(vazio)') AS rotulo, COUNT(*) AS itens
                   FROM vw_item
                  WHERE pi = :pi AND sprint_ref IN ($s) AND projeto_key IN ($k) AND $extra
                  GROUP BY $col ORDER BY itens DESC, rotulo"
            );
            $st->execute($par);
            return $st->fetchAll();
        };

        $fora = [];
        foreach ($base('is_entrega_valor = 0', 'tipo_item') as $r) {
            $fora[] = ['tipo_item' => $r['rotulo'], 'itens' => (int)$r['itens']];
        }
        $naoEntregue = [];
        foreach ($base('is_entrega_valor = 1 AND entregue = 0', 'status') as $r) {
            $naoEntregue[] = ['status' => $r['rotulo'], 'itens' => (int)$r['itens']];
        }
        $semEtapa = [];
        foreach ($times as $t) {
            $q = 0;
            foreach ($itens[$t['projeto_key']] ?? [] as $r) if ($r['qtd'] === 0) $q++;
            $semEtapa[] = ['time' => $t['time'], 'projeto_key' => $t['projeto_key'], 'itens' => $q];
        }
        return [
            'itens_fora_por_tipo'           => $fora,
            'valor_nao_entregue_por_status' => $naoEntregue,
            'itens_sem_etapa_por_time'      => $semEtapa,
        ];
    }
}
