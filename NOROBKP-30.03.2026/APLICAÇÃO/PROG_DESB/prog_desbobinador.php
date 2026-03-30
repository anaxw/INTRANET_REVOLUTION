<?php
/**
 * prog_desbobinador.php - Sistema de Desbobinador com Seleção Múltipla de Bobinas
 * Permite selecionar múltiplas bobinas por linha e soma automaticamente as quantidades
 */

// Configurações iniciais
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/erros.log');
date_default_timezone_set('America/Sao_Paulo');

if (!file_exists(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0777, true);
}

require_once 'conexao_pdo.php';

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarNumero($valor): string {
    if (is_numeric($valor) && $valor != 0) {
        return number_format((float)$valor, 2, ',', '.');
    }
    return '0,00';
}

function getValor(array $array, $index, $default = 0) {
    return isset($array[$index]) && $array[$index] !== null && $array[$index] !== '' 
        ? $array[$index] 
        : $default;
}

function debugLog($mensagem, $dados = null) {
    $log = date('Y-m-d H:i:s') . " - " . $mensagem;
    if ($dados !== null) {
        $log .= " - " . print_r($dados, true);
    }
    error_log($log);
}

// ============================================
// CONSULTAS
// ============================================

$resultados = [];
$bobinas = [];
$etiquetas = [];
$erro_consulta = null;

try {
    // Consulta principal - dados das chapas/produtos
    $data_inicio = '01.01.2026';
    $data_fim = '27.03.2026';
    $empresa = 8405;
    $filial = 2;
    
    $sql_principal = "SELECT * FROM PROC_DESB_CHAPAS($empresa, $filial, '$data_inicio', '$data_fim')";
    $resultados = consulta_pdo($sql_principal);
    debugLog("Registros principais: " . count($resultados));
    
} catch (Exception $e) {
    $erro_consulta = $e->getMessage();
    debugLog("ERRO: " . $erro_consulta);
}

// Consulta de bobinas disponíveis - com campos tratados
if (empty($erro_consulta)) {
    try {
        debugLog("Buscando bobinas disponíveis...");
        
        $sql_bobinas = "
            SELECT
                (SELECT v.seqlanc FROM vd_etiqueta v
                WHERE v.cod_link = cd.SEQPRODCARREG AND v.Origem IN ('NFC', 'NCR')) AS SEQETIQUETA,
                p.nome AS NOME_PRODUTO,
                p.codigo AS CODIGO_PRODUTO,
                cd.QTDE_CARREGADO AS QTDE_DISPONIVEL,
                cd.dt_inclusao AS DATA,
                f.codic AS COD_FORNEC,
                f.nome AS NOME_FORNECEDOR,
                n.seqnota AS SEQNOTA,
                c.SeqCarreg AS SEQCARREG,
                cd.SEQPRODCARREG AS SEQCARREG_PROD
            FROM Carga_Carregamento c
            INNER JOIN arqes01 p ON p.codigo = c.cod_produto
            INNER JOIN ArqEs05 i ON i.seqlanc = c.nro_docu
            INNER JOIN arqes04 n ON n.seqnota = i.seqnota
            INNER JOIN ArqCad f ON n.Tipof = f.Tipoc AND n.Codif = f.Codic
            INNER JOIN Carga_Prod_Carregados cd ON c.seqcarreg = cd.seqcarrega
            WHERE n.sit_conferencia = 2
            AND UPPER(p.nome) LIKE '%BOBINA%'
            AND cd.QTDE_CARREGADO > 0
            ORDER BY cd.dt_inclusao DESC
        ";
        
        $bobinas = consulta_pdo($sql_bobinas);
        debugLog("Bobinas encontradas: " . count($bobinas));
        
        // Converter e padronizar os campos
        foreach ($bobinas as &$bobina) {
            $bobina['id'] = (int)($bobina['SEQCARREG_PROD'] ?? 0);
            $bobina['seq_etiqueta'] = $bobina['SEQETIQUETA'] !== null ? (int)$bobina['SEQETIQUETA'] : 0;
            $bobina['nome_produto'] = $bobina['NOME_PRODUTO'] ?? '';
            $bobina['codigo'] = (int)($bobina['CODIGO_PRODUTO'] ?? 0);
            $bobina['qtde_kg'] = (float)($bobina['QTDE_DISPONIVEL'] ?? 0);
            $bobina['fornecedor'] = $bobina['NOME_FORNECEDOR'] ?? '';
            $bobina['nota'] = (int)($bobina['SEQNOTA'] ?? 0);
            $bobina['fornecedor_cod'] = $bobina['COD_FORNEC'] ?? '';
        }
        
    } catch (Exception $e) {
        debugLog("ERRO ao buscar bobinas: " . $e->getMessage());
        $bobinas = []; // garantir array vazio em caso de erro
    }
    
    // Consulta de etiquetas já processadas (para referência)
    try {
        $sql_etiquetas = "SELECT FIRST 200
            v.id,
            c.codacessog AS COD_CHAPA, 
            c.nome AS CHAPA, 
            b.codigo AS COD_BOBINA, 
            b.nome AS BOBINA, 
            v.qntd_utilizada AS DEMANDADO, 
            f.codic AS COD_FORNEC,
            f.nome AS FORNECEDOR, 
            v.seq_nota AS SEQ_NOTA, 
            v.seq_etiq AS COD_RASTREIO, 
            COALESCE(v.certificado, '') AS CERTIFICADO
        FROM prog_desb_chapas v
        INNER JOIN arqes01 c ON c.codacessog = v.cod_prod_base
        INNER JOIN arqes01 b ON b.codigo = v.cod_prod_vinc
        INNER JOIN arqcad f ON f.codic = v.cod_forn AND f.tipoc = 'F'
        WHERE v.tipo = 'C'
        ORDER BY v.id DESC";
        
        $etiquetas = consulta_pdo($sql_etiquetas);
        debugLog("Etiquetas já processadas: " . count($etiquetas));
        
    } catch (Exception $e) {
        debugLog("ERRO ao buscar etiquetas: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desbobinador | Seleção Múltipla de Bobinas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0f2f5; }
        .container-fluid { max-width: 98%; padding: 20px; }
        .header-card { background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: white; padding: 20px; border-radius: 15px; margin-bottom: 25px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .table-container { background: white; border-radius: 15px; padding: 0; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .table { margin-bottom: 0; font-size: 0.82rem; }
        .table thead th { background: #2c3e50; color: white; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; vertical-align: middle; white-space: nowrap; position: sticky; top: 0; z-index: 10; padding: 12px 8px; }
        .table tbody td { vertical-align: middle; padding: 10px 8px; }
        .numero { text-align: right; }
        .demanda-input { width: 110px; text-align: right; border-radius: 8px; border: 1px solid #ced4da; padding: 4px 8px; font-size: 0.8rem; }
        .demanda-input:disabled { background-color: #e9ecef; cursor: not-allowed; }
        .linha-com-bobina { background-color: #e8f5e9 !important; border-left: 4px solid #4caf50; }
        .linha-sem-bobina { background-color: #fff9e6 !important; border-left: 4px solid #ffc107; }
        .badge-status { padding: 5px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600; }
        .badge-pendente { background-color: #ffc107; color: #000; }
        .badge-pronto { background-color: #4caf50; color: #fff; }
        .btn-bobina { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 20px; padding: 4px 12px; font-size: 0.75rem; transition: all 0.2s; }
        .btn-bobina:hover { background: #e9ecef; transform: scale(0.98); }
        .btn-finalizar-bobina { background: #dc3545; border: 1px solid #dc3545; color: white; border-radius: 20px; padding: 4px 12px; font-size: 0.75rem; transition: all 0.2s; }
        .btn-finalizar-bobina:hover { background: #c82333; transform: scale(0.98); }
        .info-bobina { font-size: 0.7rem; background: #f8f9fa; padding: 5px 8px; border-radius: 10px; margin-top: 5px; max-width: 250px; word-break: break-word; }
        .summary-bar { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 20px; border-radius: 12px; margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .total-kg { background: rgba(255,255,255,0.2); padding: 8px 20px; border-radius: 40px; font-weight: bold; font-size: 1.2rem; }
        .coil-row-select { cursor: pointer; transition: background 0.1s; }
        .coil-row-select:hover { background: #e3f2fd; }
        .qtde-multi-input { width: 110px; text-align: right; }
        .modal-coil-list { max-height: 60vh; overflow-y: auto; }
        .selected-coils-badge { background: #2c7da0; color: white; border-radius: 20px; padding: 2px 8px; font-size: 0.7rem; display: inline-block; margin-bottom: 3px; }
        .giro-negativo { color: #dc3545; font-weight: bold; }
        .giro-alto { color: #28a745; font-weight: bold; }
        .giro-medio { color: #fd7e14; font-weight: bold; }
        .periodo-container { display: flex; gap: 10px; align-items: center; }
        @media (max-width: 1200px) { .demanda-input { width: 90px; } .table { font-size: 0.7rem; } }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="header-card">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h2 class="mb-0"><i class="bi bi-boxes"></i> Sistema de Desbobinador</h2>
                    <small>Seleção múltipla de bobinas - soma automática das quantidades</small>
                </div>
                <div class="periodo-container mt-2 mt-sm-0">
                    <span class="badge bg-light text-dark px-3 py-2">
                        <i class="bi bi-calendar3"></i> Período: 01.01.2026 a 27.03.2026
                    </span>
                    <button type="button" class="btn btn-finalizar-bobina" data-bs-toggle="modal" data-bs-target="#modalFinalizarBobina">
                        <i class="bi bi-check-circle"></i> Finalizar Bobina
                    </button>
                </div>
            </div>
        </div>

        <?php if ($erro_consulta): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i> Erro na consulta: <?= htmlspecialchars($erro_consulta) ?>
            </div>
        <?php elseif (empty($resultados)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle-fill"></i> Nenhum registro encontrado.
            </div>
        <?php else: ?>

        <form id="formMultiBobina" method="POST" action="prog_desbobinador_salvar.php">
            <div class="table-container">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table table-bordered table-hover" id="tabelaPrincipal">
                        <thead>
                            <tr>
                                <th>COD</th>
                                <th>PRODUTO</th>
                                <th>ESP</th>
                                <th>QUALIDADE</th>
                                <th>TAM</th>
                                <th>MARCA</th>
                                <th>MÉDIA</th>
                                <th>ESTOQUE</th>
                                <th>DEMANDA (kg)</th>
                                <th>GIRO</th>
                                <th>BOBINAS SELECIONADAS</th>
                                <th>STATUS</th>
                                <th>AÇÃO</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resultados as $index => $linha): 
                                $cod_chapa = (int)getValor($linha, 'COD', 0);
                                $media = (float)getValor($linha, 'MEDIA_ULTIMOS_3_MESES', 1);
                                $estoque = (float)getValor($linha, 'ESTOQUE', 0);
                                $estoque_bloco2 = (float)getValor($linha, 'ESTOQUE_BLOCO_2', 0);
                            ?>
                            <tr id="linha-<?= $index ?>" class="linha-sem-bobina" data-index="<?= $index ?>" data-cod-chapa="<?= $cod_chapa ?>">
                                <td><?= $cod_chapa ?></td>
                                <td><?= htmlspecialchars(getValor($linha, 'PRODUTO', '')) ?></td>
                                <td class="numero"><?= formatarNumero(getValor($linha, 'ESPESSURA', 0)) ?></td>
                                <td><?= htmlspecialchars(getValor($linha, 'QUALIDADE', '')) ?></td>
                                <td><?= htmlspecialchars(getValor($linha, 'TAMANHO', '')) ?></td>
                                <td><?= htmlspecialchars(getValor($linha, 'MARCA', '')) ?></td>
                                <td class="numero"><?= formatarNumero($media) ?></td>
                                <td class="numero"><?= formatarNumero($estoque) ?></td>
                                <td>
                                    <input type="number" name="demanda[<?= $index ?>]" 
                                           class="form-control form-control-sm demanda-input" 
                                           step="0.01" min="0" placeholder="0,00"
                                           data-index="<?= $index ?>"
                                           data-media="<?= $media ?>"
                                           data-estoque="<?= $estoque ?>"
                                           id="demanda-<?= $index ?>" 
                                           value="0" disabled>
                                </td>
                                <td class="numero giro-valor" id="giro-<?= $index ?>">0,00</td>
                                <td id="bobina-resumo-<?= $index ?>" class="small" style="max-width: 220px;">—</td>
                                <td>
                                    <span class="badge-status badge-pendente" id="status-<?= $index ?>">Pendente</span>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-bobina btn-sm btn-multi-bobina"
                                            data-bs-toggle="modal" data-bs-target="#modalMultiBobinas"
                                            data-idx="<?= $index ?>" data-cod-chapa="<?= $cod_chapa ?>">
                                        <i class="bi bi-box-seam"></i> Selecionar
                                    </button>
                                </td>
                            </tr>
                            <!-- Campos ocultos para envio -->
                            <input type="hidden" name="bobinas_json[<?= $index ?>]" id="bobinasJson-<?= $index ?>" value='[]'>
                            <input type="hidden" name="soma_quantidade[<?= $index ?>]" id="somaQuantidade-<?= $index ?>" value="0">
                            <input type="hidden" name="codigo_chapa[<?= $index ?>]" value="<?= $cod_chapa ?>">
                            <input type="hidden" name="estoque_bloco2[<?= $index ?>]" value="<?= $estoque_bloco2 ?>">
                            <input type="hidden" name="media_meses[<?= $index ?>]" value="<?= $media ?>">
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="summary-bar">
                <div>
                    <button type="submit" class="btn btn-light btn-lg px-4">
                        <i class="bi bi-save"></i> Salvar Todos
                    </button>
                    <button type="button" class="btn btn-outline-light ms-2" onclick="location.reload()">
                        <i class="bi bi-arrow-repeat"></i> Atualizar
                    </button>
                </div>
                <div class="total-kg" id="totalKgSelecionado">0,00 kg</div>
            </div>
        </form>
        <?php endif; ?>

        <!-- MODAL DE SELEÇÃO MÚLTIPLA DE BOBINAS -->
        <?php if (!empty($bobinas)): ?>
        <div class="modal fade" id="modalMultiBobinas" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="bi bi-check2-square"></i> Selecionar Bobinas (Múltiplas)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info small py-2">
                            <i class="bi bi-info-circle-fill"></i> 
                            <strong>Instruções:</strong> Marque uma ou mais bobinas. Para cada bobina selecionada, informe a quantidade (kg) a ser utilizada.
                            O sistema somará automaticamente os valores e atualizará o campo DEMANDA.
                        </div>
                        <div class="table-responsive modal-coil-list">
                            <table class="table table-sm table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width: 40px"><input type="checkbox" id="selecionarTodasModal" class="form-check-input"></th>
                                        <th>Etiqueta</th>
                                        <th>Produto</th>
                                        <th>Código</th>
                                        <th>Disponível (kg)</th>
                                        <th>Qtde Utilizar (kg)</th>
                                        <th>Fornecedor</th>
                                        <th>Nota Fiscal</th>
                                    </tr>
                                </thead>
                                <tbody id="modalCoilsBody">
                                    <?php foreach ($bobinas as $b): ?>
                                    <tr class="coil-row-select" 
                                        data-id="<?= $b['id'] ?>" 
                                        data-etiqueta="<?= $b['seq_etiqueta'] ?>" 
                                        data-nome="<?= htmlspecialchars($b['nome_produto']) ?>" 
                                        data-codigo="<?= $b['codigo'] ?>" 
                                        data-max="<?= $b['qtde_kg'] ?>" 
                                        data-fornecedor="<?= htmlspecialchars($b['fornecedor']) ?>" 
                                        data-nota="<?= $b['nota'] ?>"
                                        data-fornecedor-cod="<?= htmlspecialchars($b['fornecedor_cod'] ?? '') ?>">
                                        <td class="text-center">
                                            <input type="checkbox" class="checkbox-bobina-modal coil-check" value="<?= $b['id'] ?>">
                                        </td>
                                        <td><?= $b['seq_etiqueta'] ?></td>
                                        <td><?= htmlspecialchars($b['nome_produto']) ?></td>
                                        <td><?= $b['codigo'] ?></td>
                                        <td class="fw-bold text-end"><?= formatarNumero($b['qtde_kg']) ?></td>
                                        <td>
                                            <input type="number" step="0.01" class="form-control form-control-sm qtde-multi-input" 
                                                   placeholder="kg" disabled value="0">
                                        </td>
                                        <td><?= htmlspecialchars($b['fornecedor']) ?></td>
                                        <td><?= $b['nota'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 p-2 bg-light rounded d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-calculator-fill"></i> <strong>Soma total selecionada:</strong> <span id="modalSomaTotal">0,00</span> kg</span>
                            <span class="badge bg-info text-dark" id="contagemSelecionada">0 bobina(s)</span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" id="confirmarMultiBobinas">
                            <i class="bi bi-check-lg"></i> Aplicar Seleção Múltipla
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- MODAL DE FINALIZAÇÃO DE BOBINA (SEM ATRELAR A CHAPA) -->
        <div class="modal fade" id="modalFinalizarBobina" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-check-circle"></i> Finalizar Bobina (Sem Atrelar a Chapa)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning small py-2">
                            <i class="bi bi-exclamation-triangle-fill"></i> 
                            <strong>Atenção:</strong> Esta funcionalidade permite finalizar uma bobina sem vinculá-la a uma chapa específica.
                            A bobina será removida do estoque de bobinas disponíveis.
                        </div>
                        
                        <div class="table-responsive" style="max-height: 50vh; overflow-y: auto;">
                            <table class="table table-sm table-bordered" id="tabelaBobinasFinalizar">
                                <thead class="table-dark">
                                    <tr>
                                        <th style="width: 40px"><input type="checkbox" id="selecionarTodasFinalizar" class="form-check-input"></th>
                                        <th>Etiqueta</th>
                                        <th>Produto</th>
                                        <th>Disponível (kg)</th>
                                        <th>Quantidade a Finalizar (kg)</th>
                                        <th>Fornecedor</th>
                                        <th>Nota Fiscal</th>
                                    </tr>
                                </thead>
                                <tbody id="finalizarCoilsBody">
                                    <?php if (!empty($bobinas)): ?>
                                        <?php foreach ($bobinas as $b): ?>
                                        <tr class="coil-row-finalizar" 
                                            data-id="<?= $b['id'] ?>" 
                                            data-etiqueta="<?= $b['seq_etiqueta'] ?>" 
                                            data-nome="<?= htmlspecialchars($b['nome_produto']) ?>" 
                                            data-max="<?= $b['qtde_kg'] ?>"
                                            data-fornecedor="<?= htmlspecialchars($b['fornecedor']) ?>" 
                                            data-nota="<?= $b['nota'] ?>"
                                            data-fornecedor-cod="<?= htmlspecialchars($b['fornecedor_cod'] ?? '') ?>">
                                            <td class="text-center">
                                                <input type="checkbox" class="checkbox-finalizar-modal" value="<?= $b['id'] ?>">
                                            </td>
                                            <td><?= $b['seq_etiqueta'] ?></td>
                                            <td><?= htmlspecialchars($b['nome_produto']) ?></td>
                                            <td class="fw-bold text-end"><?= formatarNumero($b['qtde_kg']) ?></td>
                                            <td>
                                                <input type="number" step="0.01" class="form-control form-control-sm qtde-finalizar-input" 
                                                       placeholder="kg" disabled value="0">
                                            </td>
                                            <td><?= htmlspecialchars($b['fornecedor']) ?></td>
                                            <td><?= $b['nota'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-3">
                                                Nenhuma bobina disponível para finalizar.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3 p-2 bg-light rounded d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-calculator-fill"></i> <strong>Total a finalizar:</strong> <span id="totalFinalizar">0,00</span> kg</span>
                            <span class="badge bg-info text-dark" id="contagemFinalizar">0 bobina(s)</span>
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-danger" id="confirmarFinalizarBobina">
                            <i class="bi bi-check-circle"></i> Finalizar Bobina(s)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Estado global: armazena as bobinas selecionadas para cada linha
        let selecoesPorLinha = {};
        let linhaAtualIndex = null;
        let modalInstance = null;
        let modalFinalizarInstance = null;

        // Atualiza a exibição da linha e campos
        function atualizarLinha(index) {
            const selecoes = selecoesPorLinha[index] || [];
            const somaKg = selecoes.reduce((acc, item) => acc + (parseFloat(item.quantidadeUsar) || 0), 0);
            
            const demandaInput = document.getElementById(`demanda-${index}`);
            const somaHidden = document.getElementById(`somaQuantidade-${index}`);
            const jsonHidden = document.getElementById(`bobinasJson-${index}`);
            
            if (demandaInput) {
                demandaInput.value = somaKg.toFixed(2);
                // Só permite escrever se houver bobina(s) selecionada(s)
                demandaInput.disabled = (selecoes.length === 0);
            }
            
            if (somaHidden) somaHidden.value = somaKg.toFixed(2);
            if (jsonHidden) jsonHidden.value = JSON.stringify(selecoes);
            
            // Atualizar resumo visual
            const resumoDiv = document.getElementById(`bobina-resumo-${index}`);
            if (resumoDiv) {
                if (selecoes.length === 0) {
                    resumoDiv.innerHTML = '<span class="text-muted">—</span>';
                } else {
                    let html = '';
                    selecoes.forEach((s, i) => {
                        if (i < 3) {
                            html += `<div class="selected-coils-badge">📌 ${s.nome.substring(0, 20)}: ${s.quantidadeUsar} kg</div>`;
                        }
                    });
                    if (selecoes.length > 3) {
                        html += `<small class="text-muted">+${selecoes.length - 3} outra(s)</small>`;
                    }
                    resumoDiv.innerHTML = html;
                }
            }
            
            // Atualizar status visual da linha
            const linhaTr = document.getElementById(`linha-${index}`);
            const statusSpan = document.getElementById(`status-${index}`);
            if (linhaTr) {
                if (selecoes.length > 0) {
                    linhaTr.classList.remove('linha-sem-bobina');
                    linhaTr.classList.add('linha-com-bobina');
                    if (statusSpan) {
                        statusSpan.className = 'badge-status badge-pronto';
                        statusSpan.textContent = 'Pronto';
                    }
                } else {
                    linhaTr.classList.remove('linha-com-bobina');
                    linhaTr.classList.add('linha-sem-bobina');
                    if (statusSpan) {
                        statusSpan.className = 'badge-status badge-pendente';
                        statusSpan.textContent = 'Pendente';
                    }
                }
            }
            
            // Recalcular giro
            calcularGiro(index, somaKg);
            
            // Atualizar total geral
            atualizarSomaGlobal();
        }
        
        function calcularGiro(index, demandaKg) {
            const input = document.getElementById(`demanda-${index}`);
            if (!input) return;
            const media = parseFloat(input.dataset.media) || 1;
            const estoque = parseFloat(input.dataset.estoque) || 0;
            const giro = (estoque + demandaKg) / media;
            const giroCell = document.getElementById(`giro-${index}`);
            if (giroCell) {
                giroCell.innerText = giro.toFixed(2).replace('.', ',');
                giroCell.className = 'numero giro-valor';
                if (giro < 0) giroCell.classList.add('giro-negativo');
                else if (giro > 3) giroCell.classList.add('giro-alto');
                else giroCell.classList.add('giro-medio');
            }
        }
        
        function atualizarSomaGlobal() {
            let totalGeral = 0;
            for (let idx in selecoesPorLinha) {
                const soma = selecoesPorLinha[idx].reduce((acc, s) => acc + (parseFloat(s.quantidadeUsar) || 0), 0);
                totalGeral += soma;
            }
            const totalElement = document.getElementById('totalKgSelecionado');
            if (totalElement) {
                totalElement.innerText = totalGeral.toFixed(2).replace('.', ',') + ' kg';
            }
        }
        
        // Abrir modal e carregar seleções atuais
        function abrirModalMulti(index) {
            linhaAtualIndex = index;
            const selecoesAtuais = selecoesPorLinha[index] || [];
            
            const checkboxes = document.querySelectorAll('#modalCoilsBody .checkbox-bobina-modal');
            const rows = document.querySelectorAll('#modalCoilsBody .coil-row-select');
            
            // Resetar
            checkboxes.forEach(cb => cb.checked = false);
            rows.forEach(row => {
                const qtdeInput = row.querySelector('.qtde-multi-input');
                if (qtdeInput) {
                    qtdeInput.disabled = true;
                    qtdeInput.value = '';
                }
            });
            
            // Pré-marcar conforme seleções existentes
            selecoesAtuais.forEach(sel => {
                const targetRow = Array.from(rows).find(row => parseInt(row.dataset.id) === sel.id);
                if (targetRow) {
                    const cb = targetRow.querySelector('.checkbox-bobina-modal');
                    const qtde = targetRow.querySelector('.qtde-multi-input');
                    if (cb) cb.checked = true;
                    if (qtde) {
                        qtde.disabled = false;
                        qtde.value = sel.quantidadeUsar;
                    }
                }
            });
            
            // Configurar eventos
            document.querySelectorAll('.checkbox-bobina-modal').forEach(cb => {
                const tr = cb.closest('.coil-row-select');
                const qtdField = tr.querySelector('.qtde-multi-input');
                const updateState = () => {
                    if (cb.checked) {
                        qtdField.disabled = false;
                        if (!qtdField.value || parseFloat(qtdField.value) <= 0) {
                            const maxVal = parseFloat(tr.dataset.max);
                            qtdField.value = maxVal > 0 ? maxVal : 0;
                        }
                    } else {
                        qtdField.disabled = true;
                        qtdField.value = '';
                    }
                    atualizarSomaModal();
                };
                cb.removeEventListener('change', updateState);
                cb.addEventListener('change', updateState);
                if (qtdField) {
                    qtdField.removeEventListener('input', atualizarSomaModal);
                    qtdField.addEventListener('input', atualizarSomaModal);
                }
                updateState();
            });
            
            // Selecionar todas
            const selTodas = document.getElementById('selecionarTodasModal');
            if (selTodas) {
                selTodas.onchange = (e) => {
                    const isChecked = e.target.checked;
                    checkboxes.forEach(cb => {
                        cb.checked = isChecked;
                        cb.dispatchEvent(new Event('change'));
                    });
                };
            }
            
            atualizarSomaModal();
            modalInstance.show();
        }
        
        function atualizarSomaModal() {
            let total = 0;
            let count = 0;
            const rows = document.querySelectorAll('#modalCoilsBody .coil-row-select');
            rows.forEach(row => {
                const cb = row.querySelector('.checkbox-bobina-modal');
                if (cb && cb.checked) {
                    const qtdeField = row.querySelector('.qtde-multi-input');
                    let val = parseFloat(qtdeField?.value) || 0;
                    const max = parseFloat(row.dataset.max);
                    if (val > max) {
                        val = max;
                        if (qtdeField) qtdeField.value = val;
                    }
                    total += val;
                    count++;
                }
            });
            const modalSomaTotal = document.getElementById('modalSomaTotal');
            const contagemSelecionada = document.getElementById('contagemSelecionada');
            if (modalSomaTotal) modalSomaTotal.innerText = total.toFixed(2).replace('.', ',');
            if (contagemSelecionada) contagemSelecionada.innerText = `${count} bobina(s)`;
        }
        
        // Funções para o modal de finalização
        function atualizarSomaFinalizar() {
            let total = 0;
            let count = 0;
            const rows = document.querySelectorAll('#finalizarCoilsBody .coil-row-finalizar');
            rows.forEach(row => {
                const cb = row.querySelector('.checkbox-finalizar-modal');
                if (cb && cb.checked) {
                    const qtdeField = row.querySelector('.qtde-finalizar-input');
                    let val = parseFloat(qtdeField?.value) || 0;
                    const max = parseFloat(row.dataset.max);
                    if (val > max) {
                        val = max;
                        if (qtdeField) qtdeField.value = val;
                    }
                    total += val;
                    count++;
                }
            });
            const totalFinalizar = document.getElementById('totalFinalizar');
            const contagemFinalizar = document.getElementById('contagemFinalizar');
            if (totalFinalizar) totalFinalizar.innerText = total.toFixed(2).replace('.', ',');
            if (contagemFinalizar) contagemFinalizar.innerText = `${count} bobina(s)`;
        }
        
        function inicializarModalFinalizar() {
            const checkboxes = document.querySelectorAll('#finalizarCoilsBody .checkbox-finalizar-modal');
            const rows = document.querySelectorAll('#finalizarCoilsBody .coil-row-finalizar');
            
            // Resetar
            checkboxes.forEach(cb => cb.checked = false);
            rows.forEach(row => {
                const qtdeInput = row.querySelector('.qtde-finalizar-input');
                if (qtdeInput) {
                    qtdeInput.disabled = true;
                    qtdeInput.value = '';
                }
            });
            
            // Configurar eventos
            document.querySelectorAll('.checkbox-finalizar-modal').forEach(cb => {
                const tr = cb.closest('.coil-row-finalizar');
                const qtdField = tr.querySelector('.qtde-finalizar-input');
                const updateState = () => {
                    if (cb.checked) {
                        qtdField.disabled = false;
                        if (!qtdField.value || parseFloat(qtdField.value) <= 0) {
                            const maxVal = parseFloat(tr.dataset.max);
                            qtdField.value = maxVal > 0 ? maxVal : 0;
                        }
                    } else {
                        qtdField.disabled = true;
                        qtdField.value = '';
                    }
                    atualizarSomaFinalizar();
                };
                cb.removeEventListener('change', updateState);
                cb.addEventListener('change', updateState);
                if (qtdField) {
                    qtdField.removeEventListener('input', atualizarSomaFinalizar);
                    qtdField.addEventListener('input', atualizarSomaFinalizar);
                }
                updateState();
            });
            
            // Selecionar todas
            const selTodas = document.getElementById('selecionarTodasFinalizar');
            if (selTodas) {
                selTodas.onchange = (e) => {
                    const isChecked = e.target.checked;
                    checkboxes.forEach(cb => {
                        cb.checked = isChecked;
                        cb.dispatchEvent(new Event('change'));
                    });
                };
            }
            
            // Limpar motivo
            document.getElementById('motivoFinalizar').value = '';
            atualizarSomaFinalizar();
        }
        
        // Confirmar finalização
        document.getElementById('confirmarFinalizarBobina')?.addEventListener('click', () => {
            const rows = document.querySelectorAll('#finalizarCoilsBody .coil-row-finalizar');
            const bobinasFinalizar = [];
            
            rows.forEach(row => {
                const cb = row.querySelector('.checkbox-finalizar-modal');
                if (cb && cb.checked) {
                    const qtdeField = row.querySelector('.qtde-finalizar-input');
                    let quantidade = parseFloat(qtdeField?.value) || 0;
                    const maxDisponivel = parseFloat(row.dataset.max);
                    if (quantidade > maxDisponivel) quantidade = maxDisponivel;
                    if (quantidade <= 0) return;
                    
                    bobinasFinalizar.push({
                        id: parseInt(row.dataset.id),
                        etiqueta: row.dataset.etiqueta,
                        nome: row.dataset.nome,
                        quantidadeFinalizar: quantidade,
                        fornecedor: row.dataset.fornecedor,
                        nota: parseInt(row.dataset.nota),
                        fornecedor_cod: row.dataset.fornecedorCod || ''
                    });
                }
            });
            
            const motivo = document.getElementById('motivoFinalizar').value.trim();
            
            if (bobinasFinalizar.length === 0) {
                alert('Selecione pelo menos uma bobina para finalizar.');
                return;
            }
            
            if (!motivo) {
                alert('Informe o motivo da finalização.');
                return;
            }
            
            // Confirmar ação
            if (confirm(`Deseja finalizar ${bobinasFinalizar.length} bobina(s) totalizando ${bobinasFinalizar.reduce((acc, b) => acc + b.quantidadeFinalizar, 0).toFixed(2)} kg?\nMotivo: ${motivo}`)) {
                // Enviar dados via AJAX
                const dados = {
                    bobinas: bobinasFinalizar,
                    motivo: motivo,
                    acao: 'finalizar_bobina'
                };
                
                fetch('prog_desbobinador_salvar.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(dados)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Bobina(s) finalizada(s) com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro ao finalizar bobina(s): ' + (data.message || 'Erro desconhecido'));
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao comunicar com o servidor.');
                });
            }
        });
        
        // Confirmar e salvar seleções
        document.getElementById('confirmarMultiBobinas')?.addEventListener('click', () => {
            if (linhaAtualIndex === null) return;
            const rows = document.querySelectorAll('#modalCoilsBody .coil-row-select');
            const novasSelecoes = [];
            
            rows.forEach(row => {
                const cb = row.querySelector('.checkbox-bobina-modal');
                if (cb && cb.checked) {
                    const qtdeField = row.querySelector('.qtde-multi-input');
                    let quantidade = parseFloat(qtdeField?.value) || 0;
                    const maxDisponivel = parseFloat(row.dataset.max);
                    if (quantidade > maxDisponivel) quantidade = maxDisponivel;
                    if (quantidade <= 0) return;
                    
                    novasSelecoes.push({
                        id: parseInt(row.dataset.id),
                        etiqueta: row.dataset.etiqueta,
                        nome: row.dataset.nome,
                        codigo: parseInt(row.dataset.codigo),
                        quantidadeUsar: quantidade,
                        fornecedor: row.dataset.fornecedor,
                        nota: parseInt(row.dataset.nota),
                        fornecedor_cod: row.dataset.fornecedorCod || '',
                        maxOriginal: maxDisponivel
                    });
                }
            });
            
            selecoesPorLinha[linhaAtualIndex] = novasSelecoes;
            atualizarLinha(linhaAtualIndex);
            modalInstance.hide();
        });
        
        // Inicializar
        document.querySelectorAll('.btn-multi-bobina').forEach(btn => {
            btn.addEventListener('click', function() {
                const idx = parseInt(this.dataset.idx);
                abrirModalMulti(idx);
            });
        });
        
        // Inicializar todas as linhas
        for (let i = 0; i < <?= count($resultados) ?>; i++) {
            if (!selecoesPorLinha[i]) selecoesPorLinha[i] = [];
            atualizarLinha(i);
        }
        
        // Edição manual do campo demanda (quando habilitado)
        document.querySelectorAll('.demanda-input').forEach(inp => {
            inp.addEventListener('change', function() {
                const idx = parseInt(this.dataset.index);
                const novoTotal = parseFloat(this.value) || 0;
                let selecoes = selecoesPorLinha[idx] || [];
                if (selecoes.length === 0) return;
                
                const somaAtual = selecoes.reduce((s, item) => s + item.quantidadeUsar, 0);
                if (Math.abs(somaAtual - novoTotal) < 0.01) return;
                
                // Ajusta a primeira bobina para refletir o novo total
                if (selecoes.length > 0 && novoTotal >= 0) {
                    let diferenca = novoTotal - somaAtual;
                    let primeira = selecoes[0];
                    let novoValorPrimeira = primeira.quantidadeUsar + diferenca;
                    if (novoValorPrimeira < 0) novoValorPrimeira = 0;
                    const maxPermitido = primeira.maxOriginal;
                    if (novoValorPrimeira > maxPermitido) novoValorPrimeira = maxPermitido;
                    primeira.quantidadeUsar = novoValorPrimeira;
                    selecoes[0] = primeira;
                    // Remover bobinas com quantidade zero
                    selecoes = selecoes.filter(s => s.quantidadeUsar > 0);
                    selecoesPorLinha[idx] = selecoes;
                    atualizarLinha(idx);
                }
            });
        });
        
        // Inicializar modais
        const modalEl = document.getElementById('modalMultiBobinas');
        if (modalEl) modalInstance = new bootstrap.Modal(modalEl);
        
        const modalFinalizarEl = document.getElementById('modalFinalizarBobina');
        if (modalFinalizarEl) {
            modalFinalizarInstance = new bootstrap.Modal(modalFinalizarEl);
            modalFinalizarEl.addEventListener('show.bs.modal', function() {
                inicializarModalFinalizar();
            });
        }
        
        console.log("✅ Sistema com seleção múltipla de bobinas carregado com sucesso!");
        console.log("✅ Botão de finalizar bobina adicionado com sucesso!");
    </script>
</body>
</html>