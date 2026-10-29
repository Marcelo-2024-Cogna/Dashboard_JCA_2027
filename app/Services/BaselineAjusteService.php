<?php

namespace App\Services;

use App\Database;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * BaselineAjusteService
 * ─────────────────────
 * Caminho FORMAL para alterar a baseline (snapshot imutável por PI), com
 * motivo e responsável obrigatórios.
 *
 * Como funciona: numa transação, grava @ajuste_motivo/@ajuste_usuario na
 * sessão, faz o UPDATE em `baseline` e limpa as variáveis. Quem escreve a
 * trilha é o trigger `trg_baseline_audit_upd` (db/triggers.sql), que lê essas
 * variáveis (origem 'formal'). Sem as variáveis, o mesmo trigger registra o
 * UPDATE como 'sql_direto'. Se o trigger não estiver instalado (nenhuma linha
 * de auditoria nasce), a transação é DESFEITA: não existe ajuste formal sem trilha.
 *
 * Nunca apaga histórico: reverter() reaplica o valor anterior como um NOVO ajuste.
 *
 * Validação → InvalidArgumentException (mensagem pronta para o usuário).
 * Falha de infraestrutura → RuntimeException.
 */
class BaselineAjusteService
{
    private const CANAL = 'importacao';

    public const MOTIVO_MIN = 10;
    public const MOTIVO_MAX = 500;

    /** campo => tipo ('num' | 'txt') e limite de tamanho (txt). */
    public const CAMPOS = [
        'story_point_estimate' => ['tipo' => 'num'],
        'story_points'         => ['tipo' => 'num'],
        'sprint'               => ['tipo' => 'txt', 'nulo' => true],
        'status'               => ['tipo' => 'txt', 'max' => 100],
        'tipo_item'            => ['tipo' => 'txt', 'max' => 100],
        'pai'                  => ['tipo' => 'txt', 'max' => 50, 'nulo' => true],
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * Ajusta UM campo de UM item da baseline da PI.
     *
     * @return array{alterado:bool,mensagem:string,chave:string,pi:string,campo:string,
     *               valor_anterior:?string,valor_novo:?string,ajustes:array}
     * @throws InvalidArgumentException validação
     */
    public function ajustar(string $pi, string $chave, string $campo, mixed $valor, string $motivo, string $usuario): array
    {
        $motivo  = $this->validarMotivoUsuario($motivo, $usuario);
        $usuario = trim($usuario);
        $campo   = trim($campo);
        if (!isset(self::CAMPOS[$campo])) {
            throw new InvalidArgumentException("Campo '{$campo}' não permitido. Permitidos: " . implode(', ', array_keys(self::CAMPOS)) . '.');
        }
        $piId = $this->piId($pi);
        $item = $this->item($piId, $chave);
        $novo = $this->normalizar($campo, $valor);

        return $this->executar($pi, $piId, $item, $campo, $novo, $motivo, $usuario);
    }

    /**
     * Reverte um ajuste: reaplica valor_anterior como NOVO ajuste formal.
     * Só campos editáveis (sprint_ref é derivado: reverta o campo 'sprint').
     */
    public function reverter(int $idAjuste, string $motivo, string $usuario): array
    {
        $motivo = $this->validarMotivoUsuario($motivo, $usuario);
        $usuario = trim($usuario);

        $st = $this->pdo->prepare(
            'SELECT a.*, p.desc_pi FROM baseline_ajuste a
               LEFT JOIN configuracao_pi p ON p.id_versao_corrigida = a.configuracao_pi_id
              WHERE a.id_ajuste = ?'
        );
        $st->execute([$idAjuste]);
        $aj = $st->fetch();
        if (!$aj) {
            throw new InvalidArgumentException("Ajuste #{$idAjuste} não encontrado na trilha de auditoria.");
        }
        if (!isset(self::CAMPOS[$aj['campo']])) {
            throw new InvalidArgumentException(
                "O campo '{$aj['campo']}' do ajuste #{$idAjuste} não é revertível por aqui"
                . ($aj['campo'] === 'sprint_ref' ? " (é derivado de 'sprint': reverta o ajuste do campo sprint)." : '.')
            );
        }
        if ($aj['configuracao_pi_id'] === null || $aj['chave'] === null) {
            throw new InvalidArgumentException("Ajuste #{$idAjuste} sem PI/chave: não é possível localizar o item.");
        }
        $item = $this->item((int)$aj['configuracao_pi_id'], (string)$aj['chave']);

        $motivoRev = "Reversão do ajuste #{$idAjuste}: " . $motivo;
        if (mb_strlen($motivoRev) > self::MOTIVO_MAX) {
            throw new InvalidArgumentException('Motivo longo demais para a reversão (máximo ' . (self::MOTIVO_MAX - mb_strlen("Reversão do ajuste #{$idAjuste}: ")) . ' caracteres).');
        }
        // valor_anterior vem da trilha já como texto canônico (ou NULL): não passa por validação de entrada.
        $novo = $aj['valor_anterior'];
        if ($novo !== null && self::CAMPOS[$aj['campo']]['tipo'] === 'num') {
            $novo = round((float)$novo, 2);
        }
        return $this->executar((string)$aj['desc_pi'], (int)$aj['configuracao_pi_id'], $item, $aj['campo'], $novo, $motivoRev, $usuario);
    }

    /**
     * Lista a trilha (mais antigos primeiro).
     *
     * @param array{pi?:string,chave?:string,origem?:string,campo?:string,limite?:int} $f
     */
    public function listar(array $f = []): array
    {
        $where = []; $par = [];
        if (($f['pi'] ?? '') !== '')     { $where[] = 'p.desc_pi = :pi';  $par[':pi'] = trim($f['pi']); }
        if (($f['chave'] ?? '') !== '')  { $where[] = 'a.chave = :ch';    $par[':ch'] = strtoupper(trim($f['chave'])); }
        if (($f['origem'] ?? '') !== '') { $where[] = 'a.origem = :og';   $par[':og'] = trim($f['origem']); }
        if (($f['campo'] ?? '') !== '')  { $where[] = 'a.campo = :cp';    $par[':cp'] = trim($f['campo']); }
        $limite = max(1, min(5000, (int)($f['limite'] ?? 500)));

        $sql = 'SELECT a.id_ajuste, p.desc_pi AS pi, a.chave, a.campo, a.valor_anterior, a.valor_novo,
                       a.motivo, a.usuario, a.origem, a.criado_em
                  FROM baseline_ajuste a
                  LEFT JOIN configuracao_pi p ON p.id_versao_corrigida = a.configuracao_pi_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY a.criado_em, a.id_ajuste LIMIT ' . $limite;
        $st = $this->pdo->prepare($sql);
        $st->execute($par);
        return $st->fetchAll();
    }

    // ── Núcleo ───────────────────────────────────────────────────────────

    /** Aplica o UPDATE com as variáveis de sessão para o trigger; $novo já normalizado. */
    private function executar(string $pi, int $piId, array $item, string $campo, mixed $novo, string $motivo, string $usuario): array
    {
        $atual = $item[$campo];
        $base = ['chave' => $item['chave'], 'pi' => $pi, 'campo' => $campo, 'ajustes' => []];
        if ($this->igual($campo, $atual, $novo)) {
            return $base + ['alterado' => false, 'mensagem' => 'sem alteração: o valor informado já é o atual.',
                            'valor_anterior' => $atual, 'valor_novo' => $atual];
        }

        $sets = ["`{$campo}` = :v"];
        $par  = [':v' => $novo, ':id' => $item['id_baseline']];
        if ($campo === 'sprint') {
            // sprint_ref é derivada de sprint: recalculada no MESMO update.
            $sets[] = 'sprint_ref = :sr';
            $par[':sr'] = SprintRef::fromText($novo);
        }

        try {
            $this->pdo->beginTransaction();
            $ult = (int)$this->pdo->query('SELECT COALESCE(MAX(id_ajuste), 0) FROM baseline_ajuste')->fetchColumn();

            $this->pdo->prepare('SET @ajuste_motivo = ?, @ajuste_usuario = ?')->execute([$motivo, $usuario]);
            $up = $this->pdo->prepare('UPDATE baseline SET ' . implode(', ', $sets) . ' WHERE id_baseline = :id');
            $up->execute($par);
            $this->limparVariaveis();

            $st = $this->pdo->prepare(
                'SELECT id_ajuste, campo, valor_anterior, valor_novo, motivo, usuario, origem, criado_em
                   FROM baseline_ajuste WHERE id_ajuste > ? AND baseline_id = ? ORDER BY id_ajuste'
            );
            $st->execute([$ult, $item['id_baseline']]);
            $linhas = $st->fetchAll();
            if (!$linhas) {
                throw new RuntimeException('A trilha de auditoria não foi gravada (trigger trg_baseline_audit_upd ausente? aplique db/triggers.sql com conta privilegiada). Ajuste desfeito.');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->limparVariaveis();
            Logger::exception(self::CANAL, 'baseline_ajuste: falha, rollback executado', $e, [
                'pi' => $pi, 'chave' => $item['chave'], 'campo' => $campo,
            ]);
            if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) throw $e;
            throw new RuntimeException('Falha ao gravar o ajuste (rollback executado): ' . $e->getMessage(), 0, $e);
        }

        Logger::info(self::CANAL, 'baseline_ajuste: ajuste formal aplicado', [
            'pi' => $pi, 'chave' => $item['chave'], 'campo' => $campo,
            'anterior' => $atual, 'novo' => $novo, 'usuario' => $usuario, 'motivo' => $motivo,
            'linhas_auditoria' => array_column($linhas, 'id_ajuste'),
        ]);

        return $base + [
            'alterado' => true, 'mensagem' => 'ajuste aplicado e registrado na trilha.',
            'valor_anterior' => $atual,
            'valor_novo' => $novo === null ? null : (self::CAMPOS[$campo]['tipo'] === 'num' ? number_format((float)$novo, 2, '.', '') : (string)$novo),
            'ajustes' => $linhas,
        ];
    }

    private function limparVariaveis(): void
    {
        try { $this->pdo->exec('SET @ajuste_motivo = NULL, @ajuste_usuario = NULL'); } catch (Throwable $ignored) {}
    }

    // ── Validação ────────────────────────────────────────────────────────

    private function validarMotivoUsuario(string $motivo, string $usuario): string
    {
        $motivo = trim($motivo);
        if (trim($usuario) === '') {
            throw new InvalidArgumentException('Responsável (usuario) é obrigatório.');
        }
        if (mb_strlen(trim($usuario)) > 100) {
            throw new InvalidArgumentException('Responsável (usuario) excede 100 caracteres.');
        }
        if ($motivo === '') {
            throw new InvalidArgumentException('Motivo é obrigatório (mínimo ' . self::MOTIVO_MIN . ' caracteres).');
        }
        if (mb_strlen($motivo) < self::MOTIVO_MIN) {
            throw new InvalidArgumentException('Motivo muito curto (' . mb_strlen($motivo) . ' caracteres): descreva com no mínimo ' . self::MOTIVO_MIN . '.');
        }
        if (mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new InvalidArgumentException('Motivo excede ' . self::MOTIVO_MAX . ' caracteres.');
        }
        return $motivo;
    }

    private function piId(string $pi): int
    {
        $pi = trim($pi);
        if ($pi === '') {
            throw new InvalidArgumentException('PI é obrigatória (ex.: "PI 4/26").');
        }
        $st = $this->pdo->prepare('SELECT id_versao_corrigida FROM configuracao_pi WHERE desc_pi = ?');
        $st->execute([$pi]);
        $id = $st->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("PI '{$pi}' não encontrada em configuracao_pi.");
        }
        return (int)$id;
    }

    /** Item da baseline da PI (trava a linha só dentro de transação aberta pelo chamador; aqui leitura simples). */
    private function item(int $piId, string $chave): array
    {
        $chave = strtoupper(trim($chave));
        if ($chave === '') {
            throw new InvalidArgumentException('Chave do item é obrigatória (ex.: APAP-123).');
        }
        $st = $this->pdo->prepare('SELECT * FROM baseline WHERE configuracao_pi_id = ? AND chave = ?');
        $st->execute([$piId, $chave]);
        $rows = $st->fetchAll();
        if (!$rows) {
            throw new InvalidArgumentException("Item '{$chave}' não existe na baseline desta PI.");
        }
        if (count($rows) > 1) {
            throw new InvalidArgumentException("Item '{$chave}' aparece " . count($rows) . ' vezes na baseline da PI; ajuste manual necessário.');
        }
        return $rows[0];
    }

    /** Normaliza o valor de entrada: numéricos ≥ 0 (2 casas); textos aparados e dentro do limite. */
    private function normalizar(string $campo, mixed $valor): float|string|null
    {
        $def = self::CAMPOS[$campo];
        $v = is_string($valor) ? trim($valor) : $valor;

        if ($def['tipo'] === 'num') {
            if ($v === null || $v === '') {
                throw new InvalidArgumentException("Valor numérico obrigatório para '{$campo}'.");
            }
            $s = is_string($v) ? str_replace(',', '.', $v) : $v;
            if (!is_numeric($s)) {
                throw new InvalidArgumentException("Valor '{$v}' inválido para '{$campo}': informe um número.");
            }
            $n = round((float)$s, 2);
            if ($n < 0) {
                throw new InvalidArgumentException("Valor inválido para '{$campo}': não pode ser negativo ({$v}).");
            }
            if ($n > 99999999.99) {
                throw new InvalidArgumentException("Valor acima do permitido para '{$campo}'.");
            }
            return $n;
        }

        $v = $v === null ? '' : (string)$v;
        if ($v === '') {
            if (!empty($def['nulo'])) return null;
            throw new InvalidArgumentException("Valor obrigatório para '{$campo}'.");
        }
        if (isset($def['max']) && mb_strlen($v) > $def['max']) {
            throw new InvalidArgumentException("Valor excede {$def['max']} caracteres para '{$campo}'.");
        }
        return $v;
    }

    private function igual(string $campo, mixed $atual, mixed $novo): bool
    {
        if ($atual === null || $novo === null) return $atual === null && $novo === null;
        if (self::CAMPOS[$campo]['tipo'] === 'num') return abs((float)$atual - (float)$novo) < 0.005;
        return (string)$atual === (string)$novo;
    }
}
