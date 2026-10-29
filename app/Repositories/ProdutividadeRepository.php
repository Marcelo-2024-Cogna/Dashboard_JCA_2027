<?php

namespace App\Repositories;

use App\Database;
use InvalidArgumentException;
use PDO;

/**
 * ProdutividadeRepository
 * ───────────────────────
 * Gráfico PRODUTIVIDADE = horas para entregar 1 ponto (SOMENTE LEITURA).
 *
 * REGRA (decidida pelo usuário):
 *   produtividade = Σ(soma_tempo_gasto)/3600 ÷ Σ(story_points) dos itens de ENTREGA DE
 *   VALOR ENTREGUES (Aceito, Pendente de Produção, Em produção). Sem fallback para
 *   story_point_estimate quando o SP é nulo (reportado em itens_sp_nulo).
 *
 *   MÉDIA DA JORNADA = PONDERADA = Σ horas ÷ Σ pontos dos times da seleção na PI
 *   (produtividade_media_simples vai em campo separado, só como dado).
 *
 * COLUNAS: PIs do catálogo em ordem cronológica até a PI de referência (filtro 'versao';
 * padrão = PI corrente), só as que têm dados para a seleção.
 *
 * ORIGEM DOS VALORES
 *   - PI FECHADA (data_fim < hoje)         → produtividade_historico (consolidado);
 *   - PI de referência CORRENTE            → AO VIVO de vw_item, restrito à JANELA de
 *     sprints (sprint_ref; mesma janela das outras telas);
 *   - filtro de sprint                     → a PI de referência é recalculada AO VIVO só
 *     para essa sprint (as demais PIs ficam inalteradas).
 *
 * REFERÊNCIA DE PLANEJAMENTO: times_composicao.media_produtividade_pi (Velocity).
 * SUGESTÃO PARA A PRÓXIMA PI: produtividade da PI FECHADA mais recente com dados (do
 * histórico consolidado). SOMENTE LEITURA: nada é gravado em times_composicao.
 *
 * Filtros, PI, janela e times vêm de PrevisibilidadeRepository::resolverContexto().
 */
class ProdutividadeRepository
{
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
        $hoje = $hoje ?? date('Y-m-d');
        $ctx  = $this->base->resolverContexto($filtros, $hoje);
        $ref  = $ctx['pi'];

        $janela = [
            'origem'  => $ctx['origem'],
            'pi'      => $ref['desc_pi'] ?? null,
            'sprints' => $ctx['sprints'],
            'detalhe' => $ctx['sprints_detalhe'],
        ];
        $avisos = [];

        if ($ref === null) {
            return $this->resposta($ctx, $janela, [], [], $this->jornadaVazia(), ['Nenhuma PI cadastrada no catálogo.'], $this->diagVazio());
        }

        // Times candidatos: toda a seleção (o que decide se aparece é ter dado em alguma PI).
        $candidatos = array_merge($ctx['times'], $ctx['times_sem_extracao']);
        usort($candidatos, fn($a, $b) => [$a['jornada'], $a['time']] <=> [$b['jornada'], $b['time']]);
        $keys = array_column($candidatos, 'projeto_key');

        // PIs do catálogo até a de referência (ordem cronológica).
        $catalogo = $this->pdo->query(
            "SELECT id_versao_corrigida AS id, desc_pi, data_ini, data_fim
               FROM configuracao_pi WHERE ativo = 'S' ORDER BY data_ini, desc_pi"
        )->fetchAll();
        $ate = [];
        foreach ($catalogo as $p) {
            $ate[] = $p;
            if ($p['desc_pi'] === $ref['desc_pi']) break;
        }
        $fechada = fn(array $p) => $p['data_fim'] !== null && $p['data_fim'] < $hoje;
        $corrente = fn(array $p) => $p['data_ini'] && $p['data_fim'] && $p['data_ini'] <= $hoje && $hoje <= $p['data_fim'];

        $dados = []; $hist = []; $semConsolidado = [];
        if ($keys !== []) {
            $info = $this->infoPorPi(array_column($ate, 'id'), $keys);       // itens/SP nulo (ao vivo, informativo)
            $hist = $this->historico(array_column($ate, 'id'), $keys, $info); // key → pi → linha

            foreach ($ate as $p) {
                $ehRef = $p['desc_pi'] === $ref['desc_pi'];
                $vivo  = $ehRef && ($ctx['filtros']['sprint'] !== null || $corrente($p));
                if ($vivo) {
                    if ($ctx['sprints'] === []) {
                        $avisos[] = 'Nenhuma sprint de valor fechada na ' . $p['desc_pi'] . ' ainda. Escolha uma sprint no filtro ou aguarde o fechamento.';
                        continue;
                    }
                    foreach ($this->aoVivo((int)$p['id'], $ctx['sprints'], $keys) as $k => $linha) {
                        $dados[$k][$p['desc_pi']] = $linha;
                    }
                    continue;
                }
                foreach ($keys as $k) {
                    if (isset($hist[$k][$p['desc_pi']])) {
                        $dados[$k][$p['desc_pi']] = $hist[$k][$p['desc_pi']];
                    } elseif ($fechada($p) && isset($info[$k][$p['desc_pi']])) {
                        $semConsolidado[$p['desc_pi']][] = $k;
                    }
                }
            }
        }

        // PIs com dados para a seleção (colunas).
        $pis = [];
        foreach ($ate as $p) {
            $tem = false; $origem = null;
            foreach ($dados as $porPi) {
                if (isset($porPi[$p['desc_pi']])) { $tem = true; $origem = $porPi[$p['desc_pi']]['origem']; break; }
            }
            if (!$tem) continue;
            $pis[] = [
                'desc_pi' => $p['desc_pi'],
                'status'  => $corrente($p) ? 'corrente' : ($fechada($p) ? 'fechada' : 'referencia'),
                'referencia' => $p['desc_pi'] === $ref['desc_pi'],
                'origem'  => $origem,
                'data_ini' => $p['data_ini'], 'data_fim' => $p['data_fim'],
            ];
        }
        $descPis = array_column($pis, 'desc_pi');
        if (!in_array($ref['desc_pi'], $descPis, true) && $dados !== []) {
            $avisos[] = 'A ' . $ref['desc_pi'] . ' não tem dados de produtividade para a seleção.';
        }

        // Referência de planejamento (Velocity) por time.
        $refPlan = $this->referenciaPlanejamento($keys);

        // PIs fechadas (≤ referência), da mais recente para a mais antiga: base da sugestão.
        $fechadasDesc = array_reverse(array_values(array_filter($ate, $fechada)));

        $porTime = []; $semDados = [];
        foreach ($candidatos as $t) {
            $k = $t['projeto_key'];
            if (!isset($dados[$k])) { $semDados[] = $t; continue; }
            $porPi = [];
            foreach ($descPis as $d) $porPi[$d] = $dados[$k][$d] ?? null;

            $sug = null;
            foreach ($fechadasDesc as $p) {
                $h = $hist[$k][$p['desc_pi']] ?? null;
                if ($h !== null && $h['produtividade'] !== null) {
                    $sug = ['valor' => $h['produtividade'], 'pi_origem' => $p['desc_pi']];
                    break;
                }
            }
            $plan = $refPlan[$k] ?? null;
            $vref = $porPi[$ref['desc_pi']]['produtividade'] ?? null;
            $var = null;
            if ($plan !== null && $vref !== null) {
                $var = ['absoluta' => round($vref - $plan, 2), 'pct' => $plan > 0 ? round(($vref - $plan) / $plan * 100, 1) : null];
            }
            $porTime[] = [
                'jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $k,
                'referencia_planejamento' => $plan,
                'por_pi' => $porPi,
                'sugestao_proxima_pi' => $sug,
                'variacao_vs_referencia' => $var,
            ];
        }

        // JORNADA (seleção): ponderada + média simples, por PI; sugestão ponderada do histórico.
        $jornada = ['nome' => $ctx['filtros']['jornada'], 'por_pi' => [], 'sugestao_proxima_pi' => null];
        foreach ($descPis as $d) {
            $jornada['por_pi'][$d] = $this->agregarJornada(array_map(fn($t) => $t['por_pi'][$d], $porTime));
        }
        foreach ($fechadasDesc as $p) {
            $linhas = [];
            foreach ($porTime as $t) { $h = $hist[$t['projeto_key']][$p['desc_pi']] ?? null; if ($h) $linhas[] = $h; }
            $a = $this->agregarJornada($linhas);
            if ($a['produtividade_ponderada'] !== null) {
                $jornada['sugestao_proxima_pi'] = ['valor' => $a['produtividade_ponderada'], 'pi_origem' => $p['desc_pi']];
                break;
            }
        }

        // Avisos / diagnósticos
        if ($porTime === []) {
            $avisos[] = 'Nenhum time da seleção possui dados de produtividade até a ' . $ref['desc_pi'] . ' (times sem extração: '
                . ($semDados ? implode(', ', array_column($semDados, 'time')) : 'nenhum') . ').';
        } elseif ($semDados) {
            $nomes = array_column($semDados, 'time');
            $resto = count($nomes) - 8;
            $avisos[] = count($nomes) . ' time(s) sem dados de produtividade ficaram fora do gráfico: '
                . implode(', ', array_slice($nomes, 0, 8)) . ($resto > 0 ? " e mais {$resto}" : '') . '.';
        }
        foreach ($semConsolidado as $pi => $ks) {
            $avisos[] = "{$pi} está fechada mas sem consolidado em produtividade_historico para: " . implode(', ', array_unique($ks))
                . ' (rode bin/consolidar_produtividade.php).';
        }
        $spNulo = [];
        $semDadosPorPi = [];
        foreach ($descPis as $d) {
            $fora = [];
            foreach ($porTime as $t) {
                $x = $t['por_pi'][$d];
                if ($x === null) { $fora[] = $t['time']; continue; }
                if (($x['itens_sp_nulo'] ?? 0) > 0) {
                    $spNulo[] = ['pi' => $d, 'time' => $t['time'], 'projeto_key' => $t['projeto_key'],
                                 'itens_sp_nulo' => (int)$x['itens_sp_nulo'], 'itens' => $x['itens'], 'origem' => $x['origem']];
                }
            }
            $semDadosPorPi[$d] = $fora;
        }
        $nulosRef = array_sum(array_map(fn($x) => $x['pi'] === $ref['desc_pi'] ? $x['itens_sp_nulo'] : 0, $spNulo));
        if ($nulosRef > 0) {
            $avisos[] = $nulosRef . ' item(ns) entregue(s) da ' . $ref['desc_pi'] . ' estão sem Story Points e não entram nos pontos (sem fallback para o estimate).';
        }

        $diag = [
            'itens_sp_nulo' => $spNulo,
            'times_sem_dados_por_pi' => $semDadosPorPi,
            'times_sem_dados_na_selecao' => array_map(fn($t) => ['jornada' => $t['jornada'], 'time' => $t['time'], 'projeto_key' => $t['projeto_key']], $semDados),
            'pis_sem_consolidado' => $semConsolidado === [] ? new \stdClass() : array_map('array_values', array_map('array_unique', $semConsolidado)),
        ];

        return $this->resposta($ctx, $janela, $pis, $porTime, $jornada, $avisos, $diag);
    }

    // ═══════════════════════════════════════════════════════════════════════

    private function resposta(array $ctx, array $janela, array $pis, array $porTime, array $jornada, array $avisos, array $diag): array
    {
        return [
            'janela'   => $janela,
            'filtros'  => $ctx['filtros'],
            'pis'      => $pis,
            'por_time' => $porTime,
            'jornada'  => $jornada,
            'avisos'   => $avisos,
            'diagnosticos' => $diag,
        ];
    }

    private function jornadaVazia(): array
    {
        return ['nome' => null, 'por_pi' => new \stdClass(), 'sugestao_proxima_pi' => null];
    }

    private function diagVazio(): array
    {
        return ['itens_sp_nulo' => [], 'times_sem_dados_por_pi' => new \stdClass(), 'times_sem_dados_na_selecao' => [],
                'pis_sem_consolidado' => new \stdClass()];
    }

    /** Placeholders únicos (prepares nativos não repetem nomeados). */
    private function in(string $p, array $vals, array &$par): string
    {
        $ph = [];
        foreach (array_values($vals) as $i => $v) { $ph[] = ":{$p}{$i}"; $par[":{$p}{$i}"] = $v; }
        return implode(',', $ph);
    }

    private static function linha(float $horas, ?float $pontos, ?float $prod, ?int $itens, ?int $spNulo, string $origem): array
    {
        return [
            'horas' => round($horas, 2), 'pontos' => $pontos === null ? null : round($pontos, 2),
            'produtividade' => $prod === null ? null : round($prod, 2),
            'itens' => $itens, 'itens_sp_nulo' => $spNulo, 'origem' => $origem,
        ];
    }

    /**
     * Informativo (itens e SP nulo) por time x PI, de vw_produtividade_pi.
     * @return array<string,array<string,array{itens:int,sp_nulo:int}>> key → desc_pi
     */
    private function infoPorPi(array $piIds, array $keys): array
    {
        $par = [];
        $p = $this->in('p', $piIds, $par); $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT projeto_key, pi, itens, itens_sp_nulo FROM vw_produtividade_pi
              WHERE configuracao_pi_id IN ($p) AND projeto_key IN ($k)"
        );
        $st->execute($par);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[$r['projeto_key']][$r['pi']] = ['itens' => (int)$r['itens'], 'sp_nulo' => (int)$r['itens_sp_nulo']];
        return $out;
    }

    /** Histórico consolidado: key → desc_pi → linha (origem 'historico'). */
    private function historico(array $piIds, array $keys, array $info): array
    {
        $par = [];
        $p = $this->in('p', $piIds, $par); $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT t.projeto_key, cp.desc_pi, h.horas_gastas, h.pontos_entregues, h.produtividade
               FROM produtividade_historico h
               JOIN times t ON t.id_time = h.times_id AND t.ativo = 'S'
               JOIN configuracao_pi cp ON cp.id_versao_corrigida = h.configuracao_pi_id
              WHERE h.ativo = 'S' AND h.configuracao_pi_id IN ($p) AND t.projeto_key IN ($k)"
        );
        $st->execute($par);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $i = $info[$r['projeto_key']][$r['desc_pi']] ?? null;
            $out[$r['projeto_key']][$r['desc_pi']] = self::linha(
                (float)$r['horas_gastas'],
                $r['pontos_entregues'] === null ? null : (float)$r['pontos_entregues'],
                $r['produtividade'] === null ? null : (float)$r['produtividade'],
                $i['itens'] ?? null, $i['sp_nulo'] ?? null, 'historico'
            );
        }
        return $out;
    }

    /** PI de referência AO VIVO, restrita às sprints da janela (sprint_ref). */
    private function aoVivo(int $piId, array $sprints, array $keys): array
    {
        $par = [':pi' => $piId];
        $s = $this->in('s', $sprints, $par); $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT projeto_key, SUM(soma_tempo_gasto) / 3600 AS horas, SUM(story_points) AS pontos,
                    COUNT(*) AS itens, SUM(story_points IS NULL) AS sp_nulo
               FROM vw_item
              WHERE configuracao_pi_id = :pi AND is_entrega_valor = 1 AND entregue = 1
                AND sprint_ref IN ($s) AND projeto_key IN ($k)
              GROUP BY projeto_key"
        );
        $st->execute($par);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $h = (float)$r['horas'];
            $pts = $r['pontos'] === null ? null : (float)$r['pontos'];
            $out[$r['projeto_key']] = self::linha($h, $pts, $pts !== null && $pts > 0 ? $h / $pts : null,
                (int)$r['itens'], (int)$r['sp_nulo'], 'ao_vivo');
        }
        return $out;
    }

    /** @return array<string,float> projeto_key → h/ponto planejado (Velocity) */
    private function referenciaPlanejamento(array $keys): array
    {
        if ($keys === []) return [];
        $par = [];
        $k = $this->in('k', $keys, $par);
        $st = $this->pdo->prepare(
            "SELECT t.projeto_key, tc.media_produtividade_pi AS ref
               FROM times t JOIN times_composicao tc ON tc.times_id = t.id_time AND tc.ativo = 'S'
              WHERE t.ativo = 'S' AND t.projeto_key IN ($k)
              ORDER BY tc.id_time_composicao"
        );
        $st->execute($par);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            if ($r['ref'] !== null) $out[$r['projeto_key']] = round((float)$r['ref'], 2);
        }
        return $out;
    }

    /**
     * Jornada/seleção numa PI: ponderada (Σh ÷ Σpts) e média simples dos times (só dado).
     * @param array<?array> $linhas linhas de time (null = sem dado)
     */
    private function agregarJornada(array $linhas): array
    {
        $h = 0.0; $pts = 0.0; $prods = []; $n = 0;
        foreach ($linhas as $l) {
            if ($l === null) continue;
            $n++;
            $h += (float)$l['horas'];
            $pts += (float)($l['pontos'] ?? 0);
            if ($l['produtividade'] !== null) $prods[] = (float)$l['produtividade'];
        }
        return [
            'horas' => round($h, 2), 'pontos' => round($pts, 2), 'times' => $n,
            'produtividade_ponderada'      => $pts > 0 ? round($h / $pts, 2) : null,
            'produtividade_media_simples'  => $prods ? round(array_sum($prods) / count($prods), 2) : null,
        ];
    }
}
