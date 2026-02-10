<?php
// Inclui o arquivo com as funções de conexão PDO
require_once 'conexoes/con_sic_noroaco.php';

// Função para formatar números com 2 casas decimais
function formatarNumero($valor) {
    if (is_numeric($valor)) {
        return number_format($valor, 2, ',', '.');
    }
    return $valor;
}

// Função para obter valor seguro do array
function getValor($array, $index, $default = 0) {
    return $array[$index] ?? $default;
}

// Função para obter valor seguro do array associativo (para bobinas)
function getValorBobina($array, $key, $default = '0') {
    // Verifica se é array e se a chave existe
    if (is_array($array) && array_key_exists($key, $array)) {
        $valor = $array[$key];
        // Converte para string e verifica se não está vazio
        return ($valor !== null && $valor !== '' && $valor !== false) ? $valor : $default;
    }
    return $default;
}

// Função para arrays numéricos (baseado no índice)
function getValorBobinaNumerico($array, $index, $default = '0') {
    return $array[$index] ?? $default;
}

// Consulta principal
$sql = "SELECT 
    COD,
    PRODUTO,
    ESPESSURA,
    QUALIDADE,
    TAMANHO,
    MARCA,
    MENOS4,
    MENOS3,
    MENOS2,
    MENOS1,
    MES_ATUAL,
    MEDIA_ULTIMOS_3_MESES,
    PROD_INTERNA,
    ESTOQUE,
    SOLIC_COMPRA_KG,
    PEDIDOS_COMPRA_KG,
    GIRO_ESTOQUE,
    ESTOQUE_BLOCO_2,
    PROD_INTERNA_UN,
    PEDIDOS_COMPRA_UN,
    SOLIC_COMPRA_UN
FROM PROC_DESB_CHAPAS(215, 2, '01.04.2025','01.10.2025')";

try {
    $resultados = consulta_sic_no($sql);
} catch (Exception $e) {
    die("Erro na consulta: " . $e->getMessage());
}

// Consulta das bobinas ATUALIZADA com a data
$sql_bobinas = "SELECT
    (SELECT v.seqlanc FROM vd_etiqueta v
    WHERE v.cod_link = cd.SEQPRODCARREG AND v.Origem IN ('NFC', 'NCR')) AS SEQETIQUETA,
    p.nome AS NOME_PRODUTO,
    p.codigo AS CODIGO_PRODUTO,
    cd.QTDE_CARREGADO AS QTDE_CARREGADO,
    cd.dt_inclusao AS DATA,
    f.codic AS COD_FORNEC,
    f.nome AS NOME_FORNECEDOR,
    n.seqnota AS SEQNOTA,
    c.SeqCarreg AS SEQCARREG,
    cd.SEQPRODCARREG AS SEQPRODCARREG
FROM Carga_Carregamento c
INNER JOIN arqes01 p ON p.codigo = c.cod_produto
INNER JOIN ArqEs05 i ON i.seqlanc = c.nro_docu
INNER JOIN arqes04 n ON n.seqnota = i.seqnota
INNER JOIN ArqCad f ON n.Tipof = f.Tipoc AND n.Codif = f.Codic
INNER JOIN Carga_Prod_Carregados cd ON c.seqcarreg = cd.seqcarrega
LEFT OUTER JOIN lote_estoque l ON l.SeqLote = cd.SeqLote
WHERE n.sit_conferencia = 2
AND p.nome LIKE '%BOBINA%'";

try {
    $bobinas = consulta_campos_sic_no($sql_bobinas);
} catch (Exception $e) {
    $bobinas = [];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Desbobinador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cor-primaria: #2c3e50;
            --cor-secundaria: #3498db;
            --cor-sucesso: #27ae60;
            --cor-alerta: #f39c12;
            --cor-perigo: #e74c3c;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
            padding: 20px 0;
        }
        
        .container {
            max-width: 95%;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 25px;
        }
        
        .header {
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
            margin-bottom: 25px;
        }
        
        .header h1 {
            color: var(--cor-primaria);
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn-programacao, .btn-producao, .btn-etiquetas {
            padding: 10px 20px;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-programacao {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
        }
        
        .btn-producao {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            border: none;
            color: white;
        }
        
        .btn-etiquetas {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: white;
        }
        
        .btn-programacao:hover, .btn-producao:hover, .btn-etiquetas:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        th {
            background: var(--cor-primaria);
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            border: 1px solid #dee2e6;
        }
        
        td {
            padding: 10px 8px;
            border: 1px solid #dee2e6;
            vertical-align: middle;
        }
        
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        tr:hover {
            background-color: #e8f4fd;
        }
        
        .numero {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        
        .input-decimal {
            width: 100%;
            padding: 6px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            text-align: right;
            font-family: 'Courier New', monospace;
        }
        
        .input-decimal:focus {
            border-color: #80bdff;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
        }
        
        .btn-selecionar-bobina {
            font-size: 12px;
            padding: 5px 10px;
        }
        
        .bobina-selecionada {
            background: #e8f5e9;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
            padding: 8px;
            margin-top: 5px;
            font-size: 12px;
        }
        
        .bobina-info-header {
            font-weight: bold;
            color: #155724;
            margin-bottom: 5px;
            border-bottom: 1px solid #c3e6cb;
            padding-bottom: 3px;
        }
        
        .bobina-info-item {
            margin-bottom: 2px;
            color: #0c5460;
        }
        
        .badge-status {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 10px;
        }
        
        .linha-com-bobina {
            background-color: #e8f5e9 !important;
        }
        
        .linha-sem-bobina {
            background-color: #fff3cd !important;
        }
        
        .oculto {
            display: none !important;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.85);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner {
            background: white;
            padding: 40px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 0 30px rgba(0,0,0,0.3);
        }
        
        .modal-xl {
            max-width: 95%;
        }
        
        .table-bobinas, .table-etiquetas {
            font-size: 12px;
        }
        
        .coluna-selecao {
            width: 50px;
            text-align: center;
        }
        
        .input-qtde-bobina, .input-certificado-modal {
            max-width: 120px;
            margin: 0 auto;
            display: block;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            table {
                font-size: 11px;
            }
            
            th, td {
                padding: 6px 4px;
            }
            
            .header-actions {
                flex-direction: column;
            }
            
            .header-actions .btn {
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay - INICIA OCULTO -->
    <div id="loadingOverlay" class="loading-overlay oculto">
        <div class="loading-spinner">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">Processando...</span>
            </div>
            <div class="mt-3">
                <h5>Salvando dados...</h5>
                <p class="mb-0 text-muted">Aguarde enquanto processamos suas informações no banco de dados.</p>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="header text-center mb-4">
            <h1>Sistema de Desbobinador</h1>
            <div class="header-actions">
                <button type="button" class="btn btn-programacao" id="btn-gerar-programacao"
                    onclick="window.location.href='prog_desb_programacao.php'">
                    🤖 Programação
                </button>
                <button type="button" class="btn btn-producao" id="btn-gerar-producao">
                    🏭 Gerar Produção
                </button>
                <button type="button" class="btn btn-etiquetas" data-bs-toggle="modal" data-bs-target="#modalEtiquetas">
                    🏷️ Gerar Etiquetas
                </button>
            </div>
        </div>

        <?php if (count($resultados) > 0): ?>
            <form id="form-valores" method="POST" action="prog_desbobinador_salvar.php">
                <table>
                    <thead>
                        <tr>
                            <th>COD</th>
                            <th>PRODUTO</th>
                            <th>ESPESSURA</th>
                            <th>QUALIDADE</th>
                            <th>TAMANHO</th>
                            <th>MARCA</th>
                            <th>M4</th>
                            <th>M3</th>
                            <th>M2</th>
                            <th>M1</th>
                            <th>M ATUAL</th>
                            <th>MEDIA</th>
                            <th>PROD INTERNA</th>
                            <th>ESTOQUE</th>
                            <th>SOLIC COMPRA</th>
                            <th>PED COMPRA</th>
                            <th>GIRO ESTOQUE</th>
                            <th class="oculto">ESTOQUE_BLOCO_2</th>
                            <th class="oculto">PROD_INTERNA_UN</th>
                            <th class="oculto">PEDIDOS_COMPRA_UN</th>
                            <th class="oculto">SOLIC_COMPRA_UN</th>
                            <th class="oculto">CODIGO_CHAPA</th>
                            <th>DEMANDA</th>
                            <th>GIRO DEMANDA</th>
                            <th>BOBINA</th>
                            <th>STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $index => $linha):
                            $estoqueBloco2 = getValor($linha, 17);
                            $producaoInternaUn = getValor($linha, 18);
                            $pedidoCompraUn = getValor($linha, 19);
                            $solicCompraUn = getValor($linha, 20);
                            $mediaMeses = getValor($linha, 11, 1);
                            $codProduto = getValor($linha, 0);
                        ?>
                            <tr id="linha-<?php echo $index; ?>" class="linha-sem-bobina">
                                <td><?php echo htmlspecialchars($codProduto); ?></td>
                                <td><?php echo htmlspecialchars(getValor($linha, 1)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 2)); ?></td>
                                <td><?php echo htmlspecialchars(getValor($linha, 3)); ?></td>
                                <td><?php echo htmlspecialchars(getValor($linha, 4)); ?></td>
                                <td><?php echo htmlspecialchars(getValor($linha, 5)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 6)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 7)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 8)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 9)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 10)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 11)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 12)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 13)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 14)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 15)); ?></td>
                                <td class="numero"><?php echo formatarNumero(getValor($linha, 16)); ?></td>

                                <td class="oculto"><input type="hidden" name="estoque_bloco2[<?php echo $index; ?>]" value="<?php echo $estoqueBloco2; ?>"></td>
                                <td class="oculto"><input type="hidden" name="producao_interna_un[<?php echo $index; ?>]" value="<?php echo $producaoInternaUn; ?>"></td>
                                <td class="oculto"><input type="hidden" name="pedidos_compra_un[<?php echo $index; ?>]" value="<?php echo $pedidoCompraUn; ?>"></td>
                                <td class="oculto"><input type="hidden" name="solic_compra_un[<?php echo $index; ?>]" value="<?php echo $solicCompraUn; ?>"></td>

                                <!-- CAMPO HIDDEN ADICIONADO PARA O CÓDIGO DA CHAPA -->
                                <td class="oculto">
                                    <input type="hidden" name="codigo_chapa[<?php echo $index; ?>]" id="codigo-chapa-<?php echo $index; ?>" value="<?php echo $codProduto; ?>">
                                </td>

                                <td>
                                    <input type="number" name="valor_digitado[<?php echo $index; ?>]" class="input-decimal demanda-input"
                                        step="0.01" min="0" placeholder="0,00"
                                        data-index="<?php echo $index; ?>"
                                        data-estoque-bloco2="<?php echo $estoqueBloco2; ?>"
                                        data-producao-un="<?php echo $producaoInternaUn; ?>"
                                        data-solicitacao-un="<?php echo $solicCompraUn; ?>"
                                        data-pedido-un="<?php echo $pedidoCompraUn; ?>"
                                        data-media="<?php echo $mediaMeses; ?>"
                                        data-cod="<?php echo htmlspecialchars($codProduto); ?>"
                                        id="demanda-<?php echo $index; ?>">
                                </td>
                                <td class="numero giro-demanda" id="giro-demanda-<?php echo $index; ?>">0,00</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-selecionar-bobina"
                                        data-bs-toggle="modal" data-bs-target="#modalBobinas"
                                        data-index="<?php echo $index; ?>"
                                        data-cod="<?php echo htmlspecialchars($codProduto); ?>">
                                        Selecionar Bobina
                                    </button>
                                    <div id="bobina-info-<?php echo $index; ?>" class="bobina-selecionada oculto"></div>

                                    <!-- Campos hidden para armazenar todas as informações da bobina -->
                                    <input type="hidden" name="bobina_selecionada[<?php echo $index; ?>]" id="bobina-input-<?php echo $index; ?>" value="">
                                    <input type="hidden" name="qtde_bobina[<?php echo $index; ?>]" id="qtde-input-<?php echo $index; ?>" value="">
                                    <input type="hidden" name="codigo_bobina[<?php echo $index; ?>]" id="codigo-bobina-<?php echo $index; ?>" value="">
                                    <input type="hidden" name="fornecedor_bobina[<?php echo $index; ?>]" id="fornecedor-bobina-<?php echo $index; ?>" value="">
                                    <input type="hidden" name="nota_bobina[<?php echo $index; ?>]" id="nota-bobina-<?php echo $index; ?>" value="">
                                    <input type="hidden" name="etiqueta_bobina[<?php echo $index; ?>]" id="etiqueta-bobina-<?php echo $index; ?>" value="">
                                    <input type="hidden" name="certificado_bobina[<?php echo $index; ?>]" id="certificado-bobina-<?php echo $index; ?>" value="">
                                    <!-- NOVO CAMPO: descrição da bobina -->
                                    <input type="hidden" name="descricao_bobina[<?php echo $index; ?>]" id="descricao-bobina-<?php echo $index; ?>" value="">
                                </td>
                                <td>
                                    <span class="badge bg-warning badge-status" id="status-<?php echo $index; ?>">Pendente</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="mt-4 p-3 bg-light rounded">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Resumo do Processamento:</h6>
                            <div id="resumo-processamento">
                                <p>Chapas com bobina: <span id="total-com-bobina" class="badge bg-success">0</span></p>
                                <p>Chapas pendentes: <span id="total-pendentes" class="badge bg-warning"><?php echo count($resultados); ?></span></p>
                                <p class="text-muted small">Os dados só serão salvos quando você clicar no botão ao lado</p>
                            </div>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="submit" class="btn btn-success btn-lg" id="btn-salvar">
                                💾 Salvar no Banco de Dados
                            </button>
                            <p class="text-muted small mt-1">Clique aqui para gravar os dados permanentemente</p>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="giro_demanda_calculado" id="giro-demanda-calculado" value="">
            </form>
        <?php else: ?>
            <div class="alert alert-warning text-center">
                <h5>Nenhum resultado encontrado</h5>
                <p class="mb-0">A consulta não retornou dados. Verifique os parâmetros da procedure.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Bobinas -->
    <div class="modal fade" id="modalBobinas" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Selecionar Bobina - Chapa: <span id="modal-chapa-cod"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body modal-bobinas">
                    <?php if (count($bobinas) > 0): ?>
                        <div class="alert alert-info mb-3">
                            <strong>Total de bobinas encontradas:</strong> <?php echo count($bobinas); ?>
                        </div>
                        <div style="max-height: 60vh; overflow-y: auto;">
                            <table class="table table-sm table-striped table-bobinas">
                                <thead>
                                    <tr>
                                        <th>Selecionar</th>
                                        <th>SEQETIQUETA</th>
                                        <th>PRODUTO</th>
                                        <th>CÓDIGO</th>
                                        <th>QTDE CARREGADA</th>
                                        <th>QTDE UTILIZAR</th>
                                        <th>DATA</th>
                                        <th>COD FORNEC</th>
                                        <th>FORNECEDOR</th>
                                        <th>SEQNOTA</th>
                                        <th>CERTIFICADO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bobinas as $b): ?>
                                        <?php
                                        // Usar array associativo (PDO FETCH_ASSOC)
                                        $seqetiqueta = getValorBobina($b, 'SEQETIQUETA');
                                        $nome_produto = getValorBobina($b, 'NOME_PRODUTO');
                                        $codigo = getValorBobina($b, 'CODIGO_PRODUTO');
                                        $qtde_carregado = getValorBobina($b, 'QTDE_CARREGADO');
                                        $data = getValorBobina($b, 'DATA');
                                        $cod_fornec = getValorBobina($b, 'COD_FORNEC');
                                        $nome_fornec = getValorBobina($b, 'NOME_FORNECEDOR');
                                        $seqnota = getValorBobina($b, 'SEQNOTA');
                                        $seqcarreg = getValorBobina($b, 'SEQCARREG');
                                        $seqprodcarreg = getValorBobina($b, 'SEQPRODCARREG');

                                        // Formatar data
                                        $data_formatada = $data;
                                        if (!empty($data) && $data != '0') {
                                            try {
                                                $data_obj = DateTime::createFromFormat('Y-m-d H:i:s', $data);
                                                if ($data_obj) {
                                                    $data_formatada = $data_obj->format('d/m/Y');
                                                }
                                            } catch (Exception $e) {
                                                // Mantém o formato original se houver erro
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="radio" name="bobina_modal" class="bobina-radio"
                                                    value="<?php echo $seqprodcarreg; ?>"
                                                    data-seqetiqueta="<?php echo $seqetiqueta; ?>"
                                                    data-produto="<?php echo htmlspecialchars($nome_produto); ?>"
                                                    data-qtde="<?php echo $qtde_carregado; ?>"
                                                    data-fornecedor="<?php echo htmlspecialchars($nome_fornec); ?>"
                                                    data-seqprodcarreg="<?php echo $seqprodcarreg; ?>"
                                                    data-data="<?php echo $data_formatada; ?>"
                                                    data-cod-fornec="<?php echo $cod_fornec; ?>"
                                                    data-seqnota="<?php echo $seqnota; ?>"
                                                    data-codigo-bobina="<?php echo $codigo; ?>">
                                            </td>
                                            <td><?php echo $seqetiqueta; ?></td>
                                            <td><?php echo htmlspecialchars($nome_produto); ?></td>
                                            <td><?php echo $codigo; ?></td>
                                            <td class="numero"><?php echo formatarNumero($qtde_carregado); ?></td>
                                            <td>
                                                <input type="number" class="input-qtde-bobina form-control form-control-sm"
                                                    step="0.01" min="0" max="<?php echo $qtde_carregado; ?>"
                                                    placeholder="0,00" disabled style="width: 100px;">
                                            </td>
                                            <td><?php echo $data_formatada; ?></td>
                                            <td><?php echo $cod_fornec; ?></td>
                                            <td><?php echo htmlspecialchars($nome_fornec); ?></td>
                                            <td><?php echo $seqnota; ?></td>
                                            <td>
                                                <input type="text" class="input-certificado-modal form-control form-control-sm"
                                                    placeholder="CERTIFICADO" maxlength="50" style="width: 120px;"
                                                    disabled>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <h5>Nenhuma bobina encontrada</h5>
                            <p class="mb-0">A consulta não retornou resultados. Verifique os filtros aplicados.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="confirmarBobina">Confirmar Seleção</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Etiquetas -->
    <div class="modal fade" id="modalEtiquetas" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Gerar Etiquetas - Chapas Desbobinadas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php
                    // Consulta SQL CORRETA - usando o ID da prog_desb_chapas
                    $sql_etiquetas = "SELECT 
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
    v.certificado
FROM prog_desb_chapas v
INNER JOIN arqes01 c ON c.codacessog = v.cod_prod_base
INNER JOIN arqes01 b ON b.codigo = v.cod_prod_vinc
INNER JOIN arqcad f ON f.codic = v.cod_forn AND f.tipoc = 'F'
WHERE v.tipo = 'C'
ORDER BY v.id DESC";

                    try {
                        $etiquetas = consulta_campos_sic_no($sql_etiquetas);
                    } catch (Exception $e) {
                        $etiquetas = [];
                        echo "<!-- Erro na consulta: " . $e->getMessage() . " -->";
                    }
                    ?>

                    <?php if (count($etiquetas) > 0): ?>
                        <div class="alert alert-info mb-3">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <strong>Total de registros encontrados:</strong> <?php echo count($etiquetas); ?>
                                </div>
                                <div class="col-md-6 text-end">
                                    <button type="button" class="btn btn-sm btn-success me-1" id="btn-selecionar-todos">
                                        ✅ Selecionar Todos
                                    </button>
                                    <button type="button" class="btn btn-sm btn-secondary" id="btn-desmarcar-todos">
                                        ❌ Desmarcar Todos
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div style="max-height: 60vh; overflow-y: auto;">
                            <table class="table table-sm table-striped table-etiquetas">
                                <thead>
                                    <tr>
                                        <th class="coluna-selecao">
                                            <input type="checkbox" id="selecionar-todos">
                                        </th>
                                        <th>ID</th>
                                        <th>COD CHAPA</th>
                                        <th>CHAPA</th>
                                        <th>COD BOBINA</th>
                                        <th>BOBINA</th>
                                        <th>DEMANDADO</th>
                                        <th>COD FORN</th>
                                        <th>FORNECEDOR</th>
                                        <th>SEQ NOTA</th>
                                        <th>COD RASTREIO</th>
                                        <th>CERTIFICADO</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($etiquetas as $index => $etiqueta):
                                        // Obter os valores como array associativo (PDO FETCH_ASSOC)
                                        $id = getValorBobina($etiqueta, 'ID');
                                        $cod_chapa = getValorBobina($etiqueta, 'COD_CHAPA');
                                        $chapa = getValorBobina($etiqueta, 'CHAPA');
                                        $cod_bobina = getValorBobina($etiqueta, 'COD_BOBINA');
                                        $bobina = getValorBobina($etiqueta, 'BOBINA');
                                        $demandado = getValorBobina($etiqueta, 'DEMANDADO');
                                        $cod_forn = getValorBobina($etiqueta, 'COD_FORNEC');
                                        $fornecedor = getValorBobina($etiqueta, 'FORNECEDOR');
                                        $seq_nota = getValorBobina($etiqueta, 'SEQ_NOTA');
                                        $cod_rastreio = getValorBobina($etiqueta, 'COD_RASTREIO');
                                        $certificado = getValorBobina($etiqueta, 'CERTIFICADO');
                                    ?>
                                        <tr>
                                            <td class="coluna-selecao">
                                                <input type="checkbox" class="selecionar-etiqueta"
                                                    name="etiquetas_selecionadas[]"
                                                    value="<?php echo $id; ?>"
                                                    data-certificado="<?php echo htmlspecialchars($certificado); ?>"
                                                    data-cod-bobina="<?php echo $cod_bobina; ?>"
                                                    data-fornecedor="<?php echo htmlspecialchars($fornecedor); ?>"
                                                    data-nota-fiscal="<?php echo $seq_nota; ?>"
                                                    data-cod-rastreio="<?php echo $cod_rastreio; ?>"
                                                    data-cod-chapa="<?php echo $cod_chapa; ?>">
                                            </td>
                                            <td><?php echo $id; ?></td>
                                            <td><?php echo $cod_chapa; ?></td>
                                            <td><?php echo htmlspecialchars($chapa); ?></td>
                                            <td><?php echo $cod_bobina; ?></td>
                                            <td><?php echo htmlspecialchars($bobina); ?></td>
                                            <td class="numero"><?php echo formatarNumero($demandado); ?></td>
                                            <td><?php echo $cod_forn; ?></td>
                                            <td><?php echo htmlspecialchars($fornecedor); ?></td>
                                            <td><?php echo $seq_nota; ?></td>
                                            <td><?php echo $cod_rastreio; ?></td>
                                            <td><?php echo htmlspecialchars($certificado); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <h5>Nenhum registro encontrado</h5>
                            <p class="mb-0">A consulta não retornou resultados para etiquetas. Salve alguns dados primeiro.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" id="btn-gerar-etiquetas">
                        🖨️ Gerar Etiquetas Selecionadas
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // [TODO: INSERIR AQUI TODO O JAVASCRIPT EXISTENTE DO SEU ARQUIVO]
        // Variáveis globais para controle
        let currentIndex = null;
        let currentCod = null;
        let linhasComBobina = new Set();
        let formSubmitted = false;

        // Função para calcular giro demanda
        function calcularGiroDemanda(estoqueBloco2, prodInternaUn, solicCompraUn, pedidoCompraUn, demanda, media) {
            // Converter para número e evitar NaN
            estoqueBloco2 = parseFloat(estoqueBloco2 ? estoqueBloco2.toString().replace(',', '.') : 0) || 0;
            prodInternaUn = parseFloat(prodInternaUn ? prodInternaUn.toString().replace(',', '.') : 0) || 0;
            solicCompraUn = parseFloat(solicCompraUn ? solicCompraUn.toString().replace(',', '.') : 0) || 0;
            pedidoCompraUn = parseFloat(pedidoCompraUn ? pedidoCompraUn.toString().replace(',', '.') : 0) || 0;
            demanda = parseFloat(demanda ? demanda.toString().replace(',', '.') : 0) || 0;
            media = parseFloat(media ? media.toString().replace(',', '.') : 0) || 1;

            // Se demanda = 0, soma +0
            const demandaValida = demanda > 0 ? demanda : 0;

            // Fórmula oficial
            const giro = (estoqueBloco2 - prodInternaUn + solicCompraUn + pedidoCompraUn + demandaValida) / media;

            return giro.toFixed(2).replace('.', ',');
        }

        // Função que atualiza todos os giros
        function atualizarTodosGiros() {
            document.querySelectorAll('.demanda-input').forEach(input => {
                const index = input.dataset.index;
                const estoqueBloco2 = input.dataset.estoqueBloco2;
                const prodInternaUn = input.dataset.producaoUn;
                const solicCompraUn = input.dataset.solicitacaoUn;
                const pedidoUn = input.dataset.pedidoUn;
                const media = input.dataset.media;
                const demanda = input.value || 0;

                const giro = calcularGiroDemanda(estoqueBloco2, prodInternaUn, solicCompraUn, pedidoUn, demanda, media);

                const elementoGiro = document.getElementById(`giro-demanda-${index}`);
                if (elementoGiro) {
                    elementoGiro.innerText = giro;
                }
            });
        }

        // Função para atualizar resumo
        function atualizarResumo() {
            const totalComBobina = linhasComBobina.size;
            const totalLinhas = document.querySelectorAll('tr[id^="linha-"]').length;
            const totalPendentes = totalLinhas - totalComBobina;

            document.getElementById('total-com-bobina').textContent = totalComBobina;
            document.getElementById('total-pendentes').textContent = totalPendentes;
        }

        // Função para converter texto para maiúsculas automaticamente
        function converterParaMaiusculas() {
            document.querySelectorAll('.input-certificado-modal').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });
        }

        // Função para mostrar loading
        function mostrarLoading() {
            const loadingElement = document.getElementById('loadingOverlay');
            if (loadingElement) {
                loadingElement.classList.remove('oculto');
                console.log('Loading mostrado - Salvamento em andamento');
            } else {
                console.error('Elemento loadingOverlay não encontrado');
            }
        }

        // Função para esconder loading
        function esconderLoading() {
            const loadingElement = document.getElementById('loadingOverlay');
            if (loadingElement) {
                loadingElement.classList.add('oculto');
                console.log('Loading escondido - Processamento concluído');
            } else {
                console.error('Elemento loadingOverlay não encontrado');
            }
        }

        // Função para mostrar modal de sucesso
        function mostrarModalSucesso(mensagemTexto) {
            // Primeiro, remover qualquer modal existente
            const modalExistente = document.getElementById('modalSucesso');
            if (modalExistente) {
                modalExistente.remove();
            }

            // Criar modal dinamicamente
            const modalHTML = `
                <div class="modal fade" id="modalSucesso" tabindex="-1" data-bs-backdrop="static">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-success text-white">
                                <h5 class="modal-title">✅ Dados Salvos com Sucesso!</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="alert alert-success">
                                    <h6>📊 Resumo da Operação</h6>
                                    <pre style="white-space: pre-wrap; font-family: 'Inter', sans-serif; background: transparent; border: none; padding: 0; margin: 0;">${mensagemTexto}</pre>
                                </div>
                                <p class="text-muted mb-0"><small>Os dados foram gravados permanentemente no banco de dados.</small></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                                <button type="button" class="btn btn-primary" onclick="window.location.reload()">
                                    🔄 Nova Operação
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            // Adicionar modal ao body
            document.body.insertAdjacentHTML('beforeend', modalHTML);

            // Inicializar e mostrar o modal
            const modalElement = document.getElementById('modalSucesso');
            const modal = new bootstrap.Modal(modalElement);

            // Configurar evento para remover o modal quando fechar
            modalElement.addEventListener('hidden.bs.modal', function() {
                this.remove();
            });

            // Mostrar o modal
            modal.show();
        }

        // Funções para controle de seleção de etiquetas
        function selecionarTodasEtiquetas() {
            document.querySelectorAll('.selecionar-etiqueta').forEach(checkbox => {
                checkbox.checked = true;
            });
        }

        function desmarcarTodasEtiquetas() {
            document.querySelectorAll('.selecionar-etiqueta').forEach(checkbox => {
                checkbox.checked = false;
            });
        }

        // FUNÇÃO PRINCIPAL PARA GERAR ETIQUETAS - COMPLETA
        function gerarEtiquetasEmLote(idsEtiquetas) {
            if (!idsEtiquetas || idsEtiquetas.length === 0) {
                alert('Nenhuma etiqueta selecionada para gerar!');
                return;
            }

            console.log('Gerando etiquetas para IDs:', idsEtiquetas);

            // Criar um formulário temporário para enviar os dados
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'prog_desbobinador_etiq.php';
            form.target = '_blank'; // Abrir em nova aba/janela

            // Adicionar campo para operação
            const opInput = document.createElement('input');
            opInput.type = 'hidden';
            opInput.name = 'op';
            opInput.value = 'imp';
            form.appendChild(opInput);

            // Adicionar cada etiqueta selecionada
            idsEtiquetas.forEach((id, index) => {
                const seqopInput = document.createElement('input');
                seqopInput.type = 'hidden';
                seqopInput.name = `seqop[${index}]`;
                seqopInput.value = id;

                // Para quantidade, vamos usar 1 por padrão (pode ajustar conforme necessidade)
                const qtdeInput = document.createElement('input');
                qtdeInput.type = 'hidden';
                qtdeInput.name = `qtde[${index}]`;
                qtdeInput.value = '1'; // Quantidade de etiquetas por item

                form.appendChild(seqopInput);
                form.appendChild(qtdeInput);
            });

            // Adicionar formulário ao DOM e submeter
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);

            console.log('Formulário de etiquetas enviado com sucesso');
        }

        // Inicialização quando o DOM estiver carregado
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM carregado - inicializando sistema');

            // Verificar se elementos críticos existem
            const elementosCriticos = ['form-valores', 'btn-salvar', 'loadingOverlay'];
            elementosCriticos.forEach(id => {
                if (!document.getElementById(id)) {
                    console.error(`Elemento crítico não encontrado: ${id}`);
                }
            });

            // GARANTIR que o loading overlay esteja OCULTO no início
            esconderLoading();

            // Fechar qualquer modal que possa estar aberto
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
            });

            // Calcular todos os giros inicialmente
            atualizarTodosGiros();
            atualizarResumo();

            // Configurar conversão automática para maiúsculas
            converterParaMaiusculas();

            // Prevenir enter no formulário
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });
            });

            // Configurar eventos para os botões de seleção de bobina
            document.querySelectorAll('.btn-selecionar-bobina').forEach(button => {
                button.addEventListener('click', function() {
                    currentIndex = this.dataset.index;
                    currentCod = this.dataset.cod;

                    console.log('Abrindo modal para linha:', currentIndex, 'Código:', currentCod);

                    // Atualizar título do modal
                    document.getElementById('modal-chapa-cod').textContent = currentCod;

                    // Limpar seleção anterior no modal
                    document.querySelectorAll('input[name="bobina_modal"]').forEach(radio => {
                        radio.checked = false;
                        const qtdeInput = radio.closest('tr').querySelector('.input-qtde-bobina');
                        qtdeInput.value = '';
                        qtdeInput.disabled = true;

                        // Limpar e desabilitar campo certificado no modal
                        const certificadoInput = radio.closest('tr').querySelector('.input-certificado-modal');
                        if (certificadoInput) {
                            certificadoInput.value = '';
                            certificadoInput.disabled = true;
                        }
                    });
                });
            });

            // Configurar eventos para os radio buttons no modal
            document.querySelectorAll('input[name="bobina_modal"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    console.log('Bobina selecionada:', this.value);

                    // Desabilitar todos os campos primeiro
                    document.querySelectorAll('.input-qtde-bobina, .input-certificado-modal').forEach(input => {
                        input.disabled = true;
                        if (input.classList.contains('input-qtde-bobina')) {
                            input.value = '';
                        }
                    });

                    // Habilitar apenas os campos da bobina selecionada
                    if (this.checked) {
                        const qtdeInput = this.closest('tr').querySelector('.input-qtde-bobina');
                        const certificadoInput = this.closest('tr').querySelector('.input-certificado-modal');

                        qtdeInput.disabled = false;
                        certificadoInput.disabled = false;

                        qtdeInput.focus();
                        console.log('Campos quantidade e certificado habilitados para bobina:', this.value);
                    }
                });
            });

            // Recalcular giro demanda ao digitar nos campos de demanda
            document.querySelectorAll('.demanda-input').forEach(input => {
                input.addEventListener('input', e => {
                    const index = input.dataset.index;
                    const estoqueBloco2 = input.dataset.estoqueBloco2;
                    const producaoUn = input.dataset.producaoUn;
                    const solicitacaoUn = input.dataset.solicitacaoUn;
                    const pedidoUn = input.dataset.pedidoUn;
                    const media = input.dataset.media;
                    const valor = input.value;

                    const giro = calcularGiroDemanda(estoqueBloco2, producaoUn, solicitacaoUn, pedidoUn, valor, media);
                    document.getElementById(`giro-demanda-${index}`).innerText = giro;
                });
            });

            // CONFIGURAÇÃO COMPLETA DO MODAL DE ETIQUETAS
            document.getElementById('selecionar-todos').addEventListener('change', function() {
                const checked = this.checked;
                document.querySelectorAll('.selecionar-etiqueta').forEach(checkbox => {
                    checkbox.checked = checked;
                });
            });

            document.getElementById('btn-selecionar-todos').addEventListener('click', function() {
                selecionarTodasEtiquetas();
                document.getElementById('selecionar-todos').checked = true;
            });

            document.getElementById('btn-desmarcar-todos').addEventListener('click', function() {
                desmarcarTodasEtiquetas();
                document.getElementById('selecionar-todos').checked = false;
            });

            // EVENTO PRINCIPAL PARA GERAR ETIQUETAS
            document.getElementById('btn-gerar-etiquetas').addEventListener('click', function() {
                const etiquetasSelecionadas = Array.from(document.querySelectorAll('.selecionar-etiqueta:checked'))
                    .map(checkbox => checkbox.value);

                if (etiquetasSelecionadas.length === 0) {
                    alert('Selecione pelo menos uma etiqueta para gerar!');
                    return;
                }

                console.log(`Gerando ${etiquetasSelecionadas.length} etiqueta(s) selecionada(s)...`);

                // Chamar a função para gerar etiquetas
                gerarEtiquetasEmLote(etiquetasSelecionadas);

                // Fechar o modal após gerar
                const modal = bootstrap.Modal.getInstance(document.getElementById('modalEtiquetas'));
                modal.hide();
            });
        });

        // Confirmar seleção de bobina
        document.getElementById('confirmarBobina').addEventListener('click', () => {
            const radio = document.querySelector('input[name="bobina_modal"]:checked');

            if (!radio) {
                alert('Selecione uma bobina!');
                return;
            }

            const qtdeInput = radio.closest('tr').querySelector('.input-qtde-bobina');
            const qtdeUsar = parseFloat(qtdeInput.value) || 0;
            const qtdeMaxima = parseFloat(radio.dataset.qtde);

            const certificadoInput = radio.closest('tr').querySelector('.input-certificado-modal');
            const certificado = certificadoInput ? certificadoInput.value.toUpperCase() : '';

            // OBTER O CÓDIGO REAL DA CHAPA DO CAMPO HIDDEN
            const codigoChapa = document.getElementById(`codigo-chapa-${currentIndex}`).value;

            console.log('Confirmando bobina:', {
                bobina: radio.value,
                qtdeUsar: qtdeUsar,
                qtdeMaxima: qtdeMaxima,
                certificado: certificado,
                currentIndex: currentIndex,
                codigoChapa: codigoChapa
            });

            if (qtdeUsar <= 0) {
                alert('Informe a quantidade a ser utilizada!');
                qtdeInput.focus();
                return;
            }

            if (qtdeUsar > qtdeMaxima) {
                alert(`Quantidade informada (${qtdeUsar}) é maior que a disponível (${qtdeMaxima})!`);
                qtdeInput.focus();
                return;
            }

            // SINCRONIZAR: Preencher o campo demanda com a quantidade da bobina
            const demandaInput = document.querySelector(`.demanda-input[data-index="${currentIndex}"]`);
            if (demandaInput) {
                demandaInput.value = qtdeUsar.toFixed(2);
                console.log('Campo demanda preenchido com:', qtdeUsar.toFixed(2));

                // Disparar evento input para recalcular o giro
                demandaInput.dispatchEvent(new Event('input'));
            }

            // Capturar todas as informações necessárias
            const bobinaInfo = {
                codigo_chapa: codigoChapa,
                codigo_bobina: radio.dataset.codigoBobina,
                sequencial_prod_carreg: radio.value,
                quantidade_utilizar: qtdeUsar,
                codigo_fornecedor: radio.dataset.codFornec,
                sequencial_nota: radio.dataset.seqnota,
                sequencial_etiqueta: radio.dataset.seqetiqueta,
                produto: radio.dataset.produto, // JÁ EXISTE - esta é a descrição da bobina
                descricao_bobina: radio.dataset.produto, // NOVO: salvar explicitamente a descrição
                data: radio.dataset.data,
                fornecedor_nome: radio.dataset.fornecedor,
                certificado: certificado
            };

            console.log('Informações da bobina:', bobinaInfo);

            // Atualizar interface - REMOVER O BOTÃO e mostrar informações
            const bobinaContainer = document.getElementById(`bobina-info-${currentIndex}`);
            const botaoSelecionar = document.querySelector(`button[data-index="${currentIndex}"]`);
            const linha = document.getElementById(`linha-${currentIndex}`);
            const statusBadge = document.getElementById(`status-${currentIndex}`);

            // Remover o botão de selecionar
            if (botaoSelecionar) {
                botaoSelecionar.remove();
            }

            // Mostrar informações da bobina selecionada - AGORA COM DESCRIÇÃO DESTACADA
            bobinaContainer.classList.remove('oculto');
            bobinaContainer.innerHTML = `
        <div class="bobina-info-header">
            <strong>📦 Bobina Selecionada</strong>
        </div>
        <div class="bobina-info-item"><strong>Descrição:</strong> ${bobinaInfo.descricao_bobina}</div>
        <div class="bobina-info-item"><strong>Código:</strong> ${bobinaInfo.codigo_bobina}</div>
        <div class="bobina-info-item"><strong>Quantidade:</strong> ${bobinaInfo.quantidade_utilizar} kg</div>
        <div class="bobina-info-item"><strong>Fornecedor:</strong> ${bobinaInfo.fornecedor_nome}</div>
        <div class="bobina-info-item"><strong>Nota:</strong> ${bobinaInfo.sequencial_nota}</div>
        <div class="bobina-info-item"><strong>Etiqueta:</strong> ${bobinaInfo.sequencial_etiqueta}</div>
        ${bobinaInfo.certificado ? `<div class="bobina-info-item"><strong>Certificado:</strong> ${bobinaInfo.certificado}</div>` : ''}
    `;

            // Atualizar estilo da linha
            if (linha) {
                linha.classList.remove('linha-sem-bobina');
                linha.classList.add('linha-com-bobina');
            }

            // Atualizar status
            if (statusBadge) {
                statusBadge.className = 'badge bg-success badge-status';
                statusBadge.textContent = 'Pronto';
            }

            // Preencher todos os campos hidden individualmente
            document.getElementById(`bobina-input-${currentIndex}`).value = bobinaInfo.sequencial_prod_carreg;
            document.getElementById(`qtde-input-${currentIndex}`).value = bobinaInfo.quantidade_utilizar;
            document.getElementById(`codigo-bobina-${currentIndex}`).value = bobinaInfo.codigo_bobina;
            document.getElementById(`fornecedor-bobina-${currentIndex}`).value = bobinaInfo.codigo_fornecedor;
            document.getElementById(`nota-bobina-${currentIndex}`).value = bobinaInfo.sequencial_nota;
            document.getElementById(`etiqueta-bobina-${currentIndex}`).value = bobinaInfo.sequencial_etiqueta;
            document.getElementById(`certificado-bobina-${currentIndex}`).value = bobinaInfo.certificado;
            // NOVO CAMPO: descrição da bobina
            document.getElementById(`descricao-bobina-${currentIndex}`).value = bobinaInfo.descricao_bobina;

            // Adicionar à lista de linhas com bobina
            linhasComBobina.add(currentIndex);
            atualizarResumo();

            console.log('Campos hidden preenchidos para linha:', currentIndex, 'Código chapa:', bobinaInfo.codigo_chapa);

            // Fechar modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('modalBobinas'));
            modal.hide();
        });

        // Validar formulário antes de enviar
        document.getElementById('form-valores').addEventListener('submit', function(e) {
            e.preventDefault(); // IMPEDE O ENVIO AUTOMÁTICO

            console.log('Botão salvar clicado - Iniciando validação');

            if (formSubmitted) {
                alert('O formulário já está sendo enviado. Aguarde...');
                return;
            }

            // Só continua se o usuário confirmar
            if (!confirm('Deseja realmente salvar os dados no banco de dados?\n\nEsta ação irá inserir os registros de bobinas e chapas na tabela PROG_DESB_CHAPAS.')) {
                console.log('Usuário cancelou o salvamento');
                return;
            }

            if (linhasComBobina.size === 0) {
                alert('Por favor, selecione pelo menos uma bobina antes de enviar o formulário.');
                console.log('Nenhuma bobina selecionada - salvamento cancelado');
                return;
            }

            // Verificar se todas as linhas com bobina têm demanda preenchida
            let todasComDemanda = true;
            let linhasSemDemanda = [];

            linhasComBobina.forEach(index => {
                const demandaInput = document.getElementById(`demanda-${index}`);
                if (!demandaInput.value || parseFloat(demandaInput.value) <= 0) {
                    todasComDemanda = false;
                    linhasSemDemanda.push(parseInt(index) + 1); // +1 para mostrar linha começando em 1
                }
            });

            if (!todasComDemanda) {
                alert(`Por favor, preencha o campo de demanda para as bobinas selecionadas.\n\nLinhas sem demanda: ${linhasSemDemanda.join(', ')}`);
                console.log('Demandas não preenchidas - salvamento cancelado');
                return;
            }

            console.log('Validação passou - iniciando salvamento');

            // Coletar todos os giros de demanda para envio
            const girosDemanda = [];
            document.querySelectorAll('.giro-demanda').forEach(giro => {
                girosDemanda.push(giro.innerText);
            });

            document.getElementById('giro-demanda-calculado').value = JSON.stringify(girosDemanda);

            // AGORA SIM mostrar loading - APENAS quando for salvar
            console.log('Mostrando loading overlay para salvamento');
            mostrarLoading();
            formSubmitted = true;

            // Desabilitar o botão de salvar
            const btnSalvar = document.getElementById('btn-salvar');
            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '⏳ Salvando...';

            // Enviar via AJAX tradicional (XMLHttpRequest)
            const formData = new FormData(this);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'prog_desbobinador_salvar.php', true);

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log('Processamento finalizado - escondendo loading');
                    esconderLoading();
                    formSubmitted = false;
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '💾 Salvar no Banco de Dados';

                    if (xhr.status === 200) {
                        // Sucesso - o PHP retornou texto simples
                        console.log('Resposta do servidor:', xhr.responseText);

                        // Mostrar a resposta do servidor
                        if (xhr.responseText.includes('✅') || xhr.responseText.includes('sucesso') || xhr.responseText.includes('Sucesso')) {
                            mostrarModalSucesso(xhr.responseText);
                        } else {
                            // Se houve erro, mostrar mensagem de erro
                            alert(xhr.responseText);
                        }
                    } else {
                        // Erro de rede
                        console.error('Erro de rede:', xhr.status, xhr.statusText);
                        alert('❌ Erro de rede ao enviar dados: ' + xhr.statusText);
                    }
                }
            };

            xhr.onerror = function() {
                console.error('Erro completo na requisição');
                esconderLoading();
                formSubmitted = false;
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '💾 Salvar no Banco de Dados';
                alert('❌ Erro de conexão com o servidor');
            };

            xhr.send(formData);
        });

        // Garantir que o loading esteja oculto quando a página carregar completamente
        window.addEventListener('load', function() {
            console.log('Página totalmente carregada - garantindo loading oculto');
            esconderLoading();

            // Verificação extra para garantir que o loading não está visível
            setTimeout(() => {
                const loadingElement = document.getElementById('loadingOverlay');
                if (loadingElement && !loadingElement.classList.contains('oculto')) {
                    console.log('Loading ainda visível após carga - forçando ocultação');
                    loadingElement.classList.add('oculto');
                }
            }, 1000);
        }); // Função para gerar PDF de produção - VERSÃO ATUALIZADA COM DESCRIÇÃO DA BOBINA
        function gerarPDFProducao() {
            console.log('Iniciando geração de PDF de produção...');

            // Coletar todos os produtos que possuem demanda preenchida
            var produtosComDemanda = [];

            var linhas = document.querySelectorAll('tr[id^="linha-"]');
            for (var i = 0; i < linhas.length; i++) {
                var linha = linhas[i];
                var index = linha.id.replace('linha-', '');
                var demandaInput = document.getElementById('demanda-' + index);
                var demandaValor = parseFloat(demandaInput.value) || 0;

                if (demandaValor > 0) {
                    // Obter código da chapa do campo hidden
                    var codigoChapa = document.getElementById('codigo-chapa-' + index).value;

                    // Obter descrição da chapa (segunda coluna)
                    var descricaoChapa = linha.cells[1].textContent;

                    // Obter quantidade demanda
                    var quantidadeDemanda = demandaValor;

                    // Obter informações da bobina
                    var codigoBobina = document.getElementById('codigo-bobina-' + index).value;
                    var etiquetaBobina = document.getElementById('etiqueta-bobina-' + index).value;

                    // OBTER A DESCRIÇÃO DA BOBINA DO CAMPO HIDDEN QUE VOCÊ ADICIONOU
                    var descricaoBobina = document.getElementById('descricao-bobina-' + index).value;

                    // Se não encontrou no campo hidden, tentar obter do container de informações
                    if (!descricaoBobina || descricaoBobina === '') {
                        var bobinaInfoContainer = document.getElementById('bobina-info-' + index);
                        if (bobinaInfoContainer && !bobinaInfoContainer.classList.contains('oculto')) {
                            // Tentar extrair a descrição do conteúdo HTML do container
                            var infoText = bobinaInfoContainer.textContent || bobinaInfoContainer.innerText;
                            var descMatch = infoText.match(/Descrição:\s*([^\n\r]+)/i);
                            if (descMatch && descMatch[1]) {
                                descricaoBobina = descMatch[1].trim();
                            } else {
                                // Fallback: procurar por qualquer texto após "Descrição:"
                                var lines = infoText.split('\n');
                                for (var j = 0; j < lines.length; j++) {
                                    if (lines[j].includes('Descrição:')) {
                                        descricaoBobina = lines[j].replace('Descrição:', '').trim();
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    // Se ainda não encontrou, usar valor padrão
                    if (!descricaoBobina || descricaoBobina === '') {
                        descricaoBobina = 'Bobina ' + codigoBobina;
                    }

                    produtosComDemanda.push({
                        codigo_chapa: codigoChapa,
                        descricao_chapa: descricaoChapa,
                        quantidade_demanda: quantidadeDemanda,
                        codigo_etiqueta: etiquetaBobina || codigoBobina,
                        descricao_bobina: descricaoBobina // AGORA COM A DESCRIÇÃO CORRETA
                    });

                    console.log('Produto adicionado:', {
                        codigo_chapa: codigoChapa,
                        descricao_bobina: descricaoBobina
                    });
                }
            }

            if (produtosComDemanda.length === 0) {
                alert('Nenhum produto com demanda preenchida encontrado!');
                return;
            }

            console.log('Produtos com demanda encontrados:', produtosComDemanda.length);
            console.log('Dados completos:', produtosComDemanda);

            // Mostrar loading
            mostrarLoadingPDF();

            // Preparar dados para envio
            var formData = new FormData();
            formData.append('produtos', JSON.stringify(produtosComDemanda));
            formData.append('titulo', 'CHAPAS');
            formData.append('data', new Date().toLocaleDateString('pt-BR'));

            // Usar XMLHttpRequest para melhor compatibilidade
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'prog_desbobinador_pdf.php', true);
            xhr.responseType = 'blob';

            xhr.onload = function() {
                esconderLoadingPDF();

                if (xhr.status === 200) {
                    // Criar blob do PDF
                    var blob = new Blob([xhr.response], {
                        type: 'application/pdf'
                    });
                    var url = window.URL.createObjectURL(blob);

                    // Tentar abrir em nova aba
                    var novaAba = window.open(url, '_blank');

                    if (!novaAba) {
                        // Se popup foi bloqueado, fazer download
                        var link = document.createElement('a');
                        link.href = url;
                        link.download = 'producao_chapas_' + new Date().toISOString().split('T')[0] + '.pdf';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        alert('PDF gerado com sucesso! Verifique sua pasta de downloads.');
                    } else {
                        // Focar na nova aba
                        novaAba.focus();
                    }

                    // Limpar URL depois de um tempo
                    setTimeout(function() {
                        window.URL.revokeObjectURL(url);
                        console.log('URL do blob liberada');
                    }, 1000);

                } else {
                    console.error('Erro no servidor:', xhr.status, xhr.statusText);

                    // Tentar ler a resposta como texto para debug
                    var reader = new FileReader();
                    reader.onload = function() {
                        console.error('Resposta do servidor:', reader.result);
                        alert('Erro ao gerar PDF: ' + xhr.status + ' - ' + xhr.statusText + '\nDetalhes: ' + reader.result);
                    };
                    reader.readAsText(xhr.response);
                }
            };

            xhr.onerror = function() {
                esconderLoadingPDF();
                console.error('Erro de rede');
                alert('Erro de conexão ao gerar PDF');
            };

            xhr.send(formData);
        }
        // Funções para mostrar/esconder loading do PDF
        function mostrarLoadingPDF() {
            var loading = document.getElementById('loadingPDF');
            if (!loading) {
                loading = document.createElement('div');
                loading.id = 'loadingPDF';
                loading.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 9999; display: flex; justify-content: center; align-items: center; color: white;';
                loading.innerHTML = '<div style="background: white; padding: 30px; border-radius: 10px; text-align: center; color: black; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">' +
                    '<div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">' +
                    '<span class="visually-hidden">Carregando...</span>' +
                    '</div>' +
                    '<p class="mt-3" style="font-weight: bold; font-size: 16px;">Gerando PDF de Produção...</p>' +
                    '<p style="font-size: 14px; color: #666;">Aguarde enquanto o relatório é processado</p>' +
                    '</div>';
                document.body.appendChild(loading);
            }
        }

        function esconderLoadingPDF() {
            var loading = document.getElementById('loadingPDF');
            if (loading) {
                loading.remove();
            }
        }

        // Adicionar evento ao botão de gerar produção
        document.addEventListener('DOMContentLoaded', function() {
            var btnGerarProducao = document.getElementById('btn-gerar-producao');
            if (btnGerarProducao) {
                btnGerarProducao.addEventListener('click', gerarPDFProducao);
            } else {
                console.error('Botão btn-gerar-producao não encontrado!');
            }
        });

        // Funções para mostrar/esconder loading do PDF
        function mostrarLoadingPDF() {
            let loading = document.getElementById('loadingPDF');
            if (!loading) {
                loading = document.createElement('div');
                loading.id = 'loadingPDF';
                loading.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
        `;
                loading.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 10px; text-align: center; color: black; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                <p class="mt-3" style="font-weight: bold; font-size: 16px;">Gerando PDF de Produção...</p>
                <p style="font-size: 14px; color: #666;">Aguarde enquanto o relatório é processado</p>
            </div>
        `;
                document.body.appendChild(loading);
            }
        }

        function esconderLoadingPDF() {
            const loading = document.getElementById('loadingPDF');
            if (loading) {
                loading.remove();
            }
        }

        // Adicionar evento ao botão de gerar produção
        document.addEventListener('DOMContentLoaded', function() {
            const btnGerarProducao = document.getElementById('btn-gerar-producao');
            if (btnGerarProducao) {
                btnGerarProducao.addEventListener('click', gerarPDFProducao);
            } else {
                console.error('Botão btn-gerar-producao não encontrado!');
            }
        });
        // Funções para mostrar/esconder loading do PDF
        function mostrarLoadingPDF() {
            let loading = document.getElementById('loadingPDF');
            if (!loading) {
                loading = document.createElement('div');
                loading.id = 'loadingPDF';
                loading.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
        `;
                loading.innerHTML = `
            <div style="background: white; padding: 30px; border-radius: 10px; text-align: center; color: black;">
                <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                <p class="mt-3" style="font-weight: bold;">Gerando PDF de Produção...</p>
                <p style="font-size: 12px; color: #666;">Aguarde enquanto o relatório é processado</p>
            </div>
        `;
                document.body.appendChild(loading);
            }
        }

        function esconderLoadingPDF() {
            const loading = document.getElementById('loadingPDF');
            if (loading) {
                loading.remove();
            }
        }

        // Adicionar evento ao botão de gerar produção
        document.addEventListener('DOMContentLoaded', function() {
            const btnGerarProducao = document.getElementById('btn-gerar-producao');
            if (btnGerarProducao) {
                btnGerarProducao.addEventListener('click', gerarPDFProducao);
            }
        });
        // Funções para mostrar/esconder loading do PDF
        function mostrarLoadingPDF() {
            let loading = document.getElementById('loadingPDF');
            if (!loading) {
                loading = document.createElement('div');
                loading.id = 'loadingPDF';
                loading.innerHTML = `
            <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; justify-content: center; align-items: center;">
                <div style="background: white; padding: 20px; border-radius: 10px; text-align: center;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Gerando PDF...</p>
                </div>
            </div>
        `;
                document.body.appendChild(loading);
            }
        }

        function esconderLoadingPDF() {
            const loading = document.getElementById('loadingPDF');
            if (loading) {
                loading.remove();
            }
        }

        // Adicionar evento ao botão de gerar produção
        document.addEventListener('DOMContentLoaded', function() {
            const btnGerarProducao = document.getElementById('btn-gerar-producao');
            if (btnGerarProducao) {
                btnGerarProducao.addEventListener('click', gerarPDFProducao);
            }
        });

        // Recalcular giros quando a página carrega completamente
        window.addEventListener('load', function() {
            console.log('Página totalmente carregada');
            atualizarTodosGiros();
            converterParaMaiusculas();
            atualizarResumo();
        });
    </script>
</body>
</html>