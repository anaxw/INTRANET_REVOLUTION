<?php
session_start();
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Verificar se há OPs
if (!isset($_SESSION['ops_cruzadas']) || empty($_SESSION['ops_cruzadas'])) {
    echo "<script>alert('Nenhuma OP para imprimir!'); window.close();</script>";
    exit;
}

$listaOps = $_SESSION['ops_cruzadas'];

function garantirUTF8($string) {
    if (is_string($string)) {
        $string = str_replace("\xEF\xBB\xBF", '', $string);
        
        if (!mb_check_encoding($string, 'UTF-8')) {
            $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'ASCII', 'Windows-1252'], true);
            if ($encoding && $encoding != 'UTF-8') {
                $string = mb_convert_encoding($string, 'UTF-8', $encoding);
            } elseif (!$encoding) {
                $string = mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
            }
        }
        
        $string = preg_replace('/[\x00-\x1F\x7F]/', '', $string);
    }
    return $string;
}

function prepararDadosUTF8($dados) {
    if (is_array($dados)) {
        array_walk_recursive($dados, function(&$valor, $chave) {
            if (is_string($valor)) {
                $valor = garantirUTF8($valor);
            }
        });
    } elseif (is_string($dados)) {
        $dados = garantirUTF8($dados);
    }
    return $dados;
}

function getBarcodeValue($valor, $tipo = 'geral') {
    $valor = garantirUTF8((string)$valor);
    
    $valor = trim($valor);
    
    if (empty($valor) || $valor == 'LOTE NÃO DEFINIDO') {
        return '';
    }
    
    switch ($tipo) {
        case 'op':
            $valor = preg_replace('/[^0-9]/', '', $valor);
            break;
            
        case 'produto':
            $valor = preg_replace('/[^0-9]/', '', $valor);
            if (empty($valor)) {
                $valor = preg_replace('/[^A-Z0-9]/', '', strtoupper($valor));
            }
            break;
            
        case 'lote':
            preg_match_all('/\d+/', $valor, $matches);
            $numeros = $matches[0] ?? [];
            
            if (count($numeros) >= 3) {
                $valor = sprintf('%02d-%02d-%04d', 
                    intval($numeros[0]),
                    intval($numeros[1]),
                    intval($numeros[2])
                );
                
                if (count($numeros) >= 4) {
                    $valor .= ' ' . str_pad($numeros[3], 2, '0', STR_PAD_LEFT);
                }
            }
            break;
            
        default:
            $valor = preg_replace('/[^a-zA-Z0-9]/', '', $valor);
    }
    
    return $valor;
}

function exibirTexto($texto) {
    $texto = garantirUTF8((string)$texto);
    return htmlspecialchars($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Processar dados para UTF-8
$listaOps = prepararDadosUTF8($listaOps);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Impressão de Etiquetas - Dados Cruzados</title>
    <!-- Biblioteca para gerar códigos de barras -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * {
            margin: 0;
            border: none;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
            font-family: 'Arial', sans-serif;
            -webkit-print-color-adjust: exact;
            color-adjust: exact;
            width: 567px;
        }

        @page {
            size: 567px 450px;
            margin: 0;
        }

        .etiqueta-container {
            width: 567px;
            height: 450px;
            margin: 0 auto;
            background: white;
            position: relative;
            display: flex;
            flex-direction: column;
            page-break-after: always;
            border: 1px solid #ccc;
        }

        .etiqueta-container:last-child {
            page-break-after: avoid;
        }

        .cabecalho {
            padding: 8px 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
            border-bottom: 2px solid #000;
            height: 55px;
            flex-shrink: 0;
        }

        .logo-container {
            height: 40px;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }

        .logo {
            max-height: 40px;
            max-width: 140px;
        }

        .titulo-central {
            text-align: center;
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
        }

        .label-op {
            font-size: 12px;
            color: #000;
            text-transform: uppercase;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            line-height: 1.1;
            text-align: center;
            width: 100%;
            font-weight: bold;
            letter-spacing: 0.5px;
        }

        .info-op-direita {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            flex-shrink: 0;
            width: auto;
        }

        .numero-op {
            font-size: 24px;
            font-weight: bold;
            color: #000;
            line-height: 1.2;
            white-space: nowrap;
        }
        
        /* Contador de cópias */
        .contador-copia {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #3498db;
            color: white;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
            z-index: 10;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        .corpo-etiqueta {
            flex: 1;
            padding: 7px 5px;
            display: grid;
            grid-template-columns: 1fr 220px;
            gap: 5px;
            min-height: 0;
            overflow: hidden;
        }

        .side-card-grande {
            background: #fff;
            border: 1px solid #000;
            border-radius: 4px;
            padding: 5px;
            display: flex;
            flex-direction: column;
            min-height: 220px;
        }

        .info-section-lateral {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
        }

        .info-card-lateral {
            background: #fff;
            border: 1px solid #000;
            border-radius: 3px;
            padding: 8px;
            position: relative;
            min-height: 50px;
        }

        .info-card-lateral.produto {
            flex: 1;
            min-height: 70px;
        }

        .info-card-lateral.cliente {
            min-height: 60px;
        }

        .info-card-lateral.cidade {
            min-height: 30px;
        }

        .info-label-lateral {
            font-size: 11px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            display: block;
            margin-bottom: 4px;
        }

        .info-value-lateral-produto {
            font-size: 17px;
            font-weight: 600;
            color: #000;
            line-height: 1.3;
            display: flex;
            align-items: center;
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
        }

        .info-value-lateral {
            font-size: 15px;
            font-weight: 600;
            color: #000;
            line-height: 1.3;
            display: flex;
            align-items: center;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .dado-nulo {
            color: #e74c3c !important;
            font-style: italic;
            font-weight: 600;
        }

        .codes-section {
            width: 220px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .side-card {
            background: #fff;
            border-radius: 4px;
            padding: 5px;
            text-align: center;
            display: flex;
            flex-direction: column;
            height: 93px;
        }

        .side-card-title {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 3px;
            line-height: 1;
        }

        .side-card-value {
            font-family: 'Courier New', monospace;
            font-size: 19px;
            font-weight: bold;
            color: #000;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            flex-shrink: 0;
            line-height: 1;
            min-height: 20px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .side-card-value.dado-nulo {
            font-size: 11px;
            color: #e74c3c;
        }

        .barcode-container {
            flex: 1;
            padding: 4px;
            background: white;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 35px;
            position: relative;
        }

        .barcode-canvas {
            width: 100% !important;
            height: 42px !important;
            max-width: 200px;
            display: block;
            margin: 0 auto;
            image-rendering: crisp-edges;
            image-rendering: pixelated;
        }

        .barcode-img {
            width: 100% !important;
            height: 42px !important;
            max-width: 200px;
            display: block;
            margin: 0 auto;
            image-rendering: crisp-edges;
            image-rendering: pixelated;
        }

        .sem-barcode {
            color: #e74c3c;
            font-size: 9px;
            font-style: italic;
            font-weight: bold;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            width: 100%;
            background: white;
        }

        .rodape {
            padding: 0;
            border-top: 2px solid #000;
            background: #fff;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            width: 100%;
        }

        .titulo-conferencia {
            font-size: 10px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            text-align: left;
            padding: 3px 15px;
            background: #fff;
            border-bottom: 1px solid #000;
            letter-spacing: 0.5px;
            width: 100%;
        }

        .conferencia-area {
            display: flex;
            justify-content: space-between;
            width: 100%;
            padding: 10px 15px;
            height: 65px;
            box-sizing: border-box;
        }

        .campo-conferencia.operador {
            width: 50%;
            flex: 0 0 50%;
            padding-right: 10px;
        }

        .campo-conferencia.quantidade {
            width: 20%;
            flex: 0 0 20%;
            padding-right: 10px;
        }

        .campo-conferencia.data {
            width: 30%;
            flex: 0 0 30%;
        }

        .campo-label {
            font-size: 10px;
            font-weight: bold;
            color: #000;
            display: block;
            text-transform: uppercase;
            margin-bottom: 5px;
            white-space: nowrap;
        }

        .campo-borda {
            border: 2px solid #000;
            height: 32px;
            border-radius: 4px;
            background: white;
            width: 100%;
        }

        /* Loading enquanto prepara impressão */
        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-family: Arial, sans-serif;
            transition: opacity 0.5s ease;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 20px;
        }

        .loading-text {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .loading-subtext {
            font-size: 14px;
            opacity: 0.8;
            max-width: 400px;
            text-align: center;
            margin-bottom: 30px;
        }

        .loading-details {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            font-size: 14px;
            text-align: center;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media print {
            body {
                background: white !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 567px !important;
            }

            .etiqueta-container {
                width: 567px !important;
                height: 450px !important;
                margin: 0 !important;
                page-break-inside: avoid !important;
                border: none !important;
            }

            .cabecalho {
                border-bottom: 2px solid #000 !important;
            }

            .campo-borda {
                border: 2px solid #000 !important;
            }

            .side-card-grande,
            .info-card-lateral {
                border: 1px solid #000 !important;
            }

            .titulo-conferencia {
                border-bottom: 1px solid #000 !important;
            }
            
            .barcode-canvas,
            .barcode-img {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                filter: contrast(1.5) !important;
                opacity: 1 !important;
                visibility: visible !important;
                width: 100% !important;
                height: 42px !important;
            }
            
            .sem-barcode {
                display: none !important;
            }
            
            .loading-screen {
                display: none !important;
            }
            
            .contador-copia {
                display: none !important;
            }
            
            /* Garantir que cada etiqueta seja impressa em página separada */
            .etiqueta-container {
                page-break-after: always !important;
            }
            
            .etiqueta-container:last-child {
                page-break-after: auto !important;
            }
        }
    </style>
</head>
<body>
    <!-- Tela de carregamento -->
    <div class="loading-screen" id="loadingScreen">
        <div class="loading-spinner"></div>
        <div class="loading-text">Preparando impressão...</div>
        <div class="loading-subtext">
            As etiquetas estão sendo preparadas para impressão.
            <br>Aguarde um momento enquanto geramos os códigos de barras.
        </div>
        <div class="loading-details" id="loadingDetails">
            Gerando códigos de barras...
        </div>
    </div>

    <!-- Conteúdo das etiquetas (inicialmente oculto) -->
    <div id="etiquetasContainer" style="display: none;">
        <?php
        $contadorTotal = 0;
        foreach ($listaOps as $opItem):
            $quantidade = isset($opItem['quantidade']) ? (int)$opItem['quantidade'] : 1;
            
            // Preparar dados
            $op_numero = isset($opItem['seqop']) ? exibirTexto($opItem['seqop']) : '';
            $lote = isset($opItem['lote']) ? exibirTexto($opItem['lote']) : '';
            $produto_nome = isset($opItem['descricao_produto']) ? exibirTexto($opItem['descricao_produto']) : 'DESCRIÇÃO NÃO ENCONTRADA';
            $cliente_nome = isset($opItem['nome_cliente']) ? exibirTexto($opItem['nome_cliente']) : 'CLIENTE NÃO INFORMADO';
            $cidade = isset($opItem['cidade']) ? exibirTexto($opItem['cidade']) : 'CIDADE NÃO INFORMADA';
            $pedido = isset($opItem['pedido']) ? exibirTexto($opItem['pedido']) : 'NÃO INFORMADO';
            
            // Usar o pedido como código de produto (ou parte dele)
            $codigo = strlen($pedido) > 8 ? substr($pedido, 0, 8) : $pedido;
            
            // Gerar valores para códigos de barras
            $codigo_op = getBarcodeValue($opItem['seqop'] ?? '', 'op');
            $codigo_produto = getBarcodeValue($codigo, 'produto');
            $codigo_lote = getBarcodeValue($opItem['lote'] ?? '', 'lote');
            
            $loteNulo = empty($codigo_lote) || ($opItem['lote'] ?? '') == 'LOTE NÃO DEFINIDO';
            $cidadeNula = $cidade == 'CIDADE NÃO INFORMADA';
            
            // Gerar múltiplas cópias da mesma OP
            for ($copia = 1; $copia <= $quantidade; $copia++):
                $contadorTotal++;
        ?>
            
            <div class="etiqueta-container" data-etiqueta="<?php echo $contadorTotal; ?>" data-op="<?php echo $op_numero; ?>">
                <?php if ($copia > 1): ?>
                    <div class="contador-copia" title="Cópia <?php echo $copia; ?> de <?php echo $quantidade; ?>">
                        <?php echo $copia; ?>/<?php echo $quantidade; ?>
                    </div>
                <?php endif; ?>
                
                <div class="cabecalho">
                    <div class="logo-container">
                        <img src="imgs/logo.png" class="logo" alt="Logo" onerror="this.style.display='none'">
                    </div>

                    <div class="titulo-central">
                        <div class="label-op">
                            IDENTIFICAÇÃO<br>DE PRODUTO
                        </div>
                    </div>

                    <div class="info-op-direita">
                        <div class="numero-op">OP: <?php echo $op_numero; ?></div>
                    </div>
                </div>

                <div class="corpo-etiqueta">
                    <div class="side-card-grande">
                        <div class="info-section-lateral">
                            <div class="info-card-lateral produto">
                                <span class="info-label-lateral">PRODUTO:</span>
                                <div class="info-value-lateral-produto">
                                    <?php echo $produto_nome; ?>
                                </div>
                            </div>

                            <div class="info-card-lateral cliente">
                                <span class="info-label-lateral">CLIENTE:</span>
                                <div class="info-value-lateral">
                                    <?php echo $cliente_nome; ?>
                                </div>
                            </div>

                            <div class="info-card-lateral cidade">
                                <span class="info-label-lateral">CIDADE:</span>
                                <div class="info-value-lateral <?php echo $cidadeNula ? 'dado-nulo' : ''; ?>">
                                    <?php echo $cidade; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="codes-section">
                        <div class="side-card" data-type="op">
                            <div class="side-card-title">OP Nº</div>
                            <div class="side-card-value"><?php echo $op_numero; ?></div>
                            <div class="barcode-container" data-barcode-type="op" data-value="<?php echo htmlspecialchars($codigo_op, ENT_QUOTES, 'UTF-8'); ?>">
                                <canvas class="barcode-canvas" id="barcode-op-<?php echo $contadorTotal; ?>"></canvas>
                                <div class="sem-barcode" id="fallback-op-<?php echo $contadorTotal; ?>" style="display: none;">
                                    OP: <?php echo $op_numero; ?>
                                </div>
                            </div>
                        </div>

                        <div class="side-card" data-type="produto">
                            <div class="side-card-title">PEDIDO</div>
                            <div class="side-card-value"><?php echo $codigo; ?></div>
                            <div class="barcode-container" data-barcode-type="produto" data-value="<?php echo htmlspecialchars($codigo_produto, ENT_QUOTES, 'UTF-8'); ?>">
                                <canvas class="barcode-canvas" id="barcode-produto-<?php echo $contadorTotal; ?>"></canvas>
                                <div class="sem-barcode" id="fallback-produto-<?php echo $contadorTotal; ?>" style="display: none;">
                                    PED: <?php echo $codigo; ?>
                                </div>
                            </div>
                        </div>

                        <div class="side-card" data-type="lote">
                            <div class="side-card-title">LOTE</div>
                            <div class="side-card-value <?php echo $loteNulo ? 'dado-nulo' : ''; ?>">
                                <?php 
                                if ($loteNulo) {
                                    echo 'LOTE NÃO DEFINIDO';
                                } else {
                                    echo $lote;
                                }
                                ?>
                            </div>
                            <div class="barcode-container" data-barcode-type="lote" data-value="<?php echo htmlspecialchars($codigo_lote, ENT_QUOTES, 'UTF-8'); ?>">
                                <canvas class="barcode-canvas" id="barcode-lote-<?php echo $contadorTotal; ?>"></canvas>
                                <div class="sem-barcode" id="fallback-lote-<?php echo $contadorTotal; ?>" style="display: none;">
                                    <?php echo $loteNulo ? 'SEM LOTE' : $lote; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rodape">
                    <div class="titulo-conferencia">
                        CONFERÊNCIA
                    </div>
                    
                    <div class="conferencia-area">
                        <div class="campo-conferencia operador">
                            <span class="campo-label">OPERADOR</span>
                            <div class="campo-borda"></div>
                        </div>

                        <div class="campo-conferencia quantidade">
                            <span class="campo-label">QUANTIDADE</span>
                            <div class="campo-borda"></div>
                        </div>

                        <div class="campo-conferencia data">
                            <span class="campo-label">DATA</span>
                            <div class="campo-borda"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endfor; // fim do loop de cópias ?>
        <?php endforeach; // fim do loop de OPs ?>
    </div>

    <script>
        // Função para gerar códigos de barras
        function gerarCodigosBarras() {
            console.log('📊 Iniciando geração de códigos de barras...');
            
            let totalBarcodes = 0;
            let barcodesGerados = 0;
            
            // Contar total de barcodes a gerar
            document.querySelectorAll('.barcode-container[data-value]').forEach(container => {
                if (container.dataset.value && container.dataset.value.trim() !== '') {
                    totalBarcodes++;
                }
            });
            
            console.log(`Total de códigos de barras a gerar: ${totalBarcodes}`);
            
            // Atualizar loading details
            const loadingDetails = document.getElementById('loadingDetails');
            if (loadingDetails) {
                loadingDetails.textContent = `Gerando 0/${totalBarcodes} códigos de barras...`;
            }
            
            // Para cada container de barcode
            document.querySelectorAll('.barcode-container[data-value]').forEach((container, index) => {
                const barcodeValue = container.dataset.value;
                const barcodeType = container.dataset.barcodeType;
                const canvas = container.querySelector('.barcode-canvas');
                const fallback = container.querySelector('.sem-barcode');
                
                if (!barcodeValue || barcodeValue.trim() === '') {
                    // Mostrar fallback se não houver valor
                    if (canvas) canvas.style.display = 'none';
                    if (fallback) fallback.style.display = 'flex';
                    barcodesGerados++;
                    atualizarProgresso(barcodesGerados, totalBarcodes);
                    return;
                }
                
                // Valores específicos para cada tipo
                let options = {
                    format: "CODE128",
                    width: 2,
                    height: 40,
                    displayValue: false,
                    margin: 0,
                    background: "#ffffff",
                    lineColor: "#000000",
                    valid: function(valid) {
                        if (!valid && fallback) {
                            canvas.style.display = 'none';
                            fallback.style.display = 'flex';
                        }
                        barcodesGerados++;
                        atualizarProgresso(barcodesGerados, totalBarcodes);
                    }
                };
                
                // Ajustes específicos por tipo
                if (barcodeType === 'lote') {
                    options.fontSize = 0;
                    options.marginTop = 0;
                    options.marginBottom = 0;
                }
                
                try {
                    if (canvas) {
                        // Usar JsBarcode para gerar no canvas
                        JsBarcode(canvas, barcodeValue, options);
                        
                        // Esconder fallback
                        if (fallback) fallback.style.display = 'none';
                    }
                } catch (error) {
                    console.error(`Erro ao gerar barcode ${barcodeType}:`, error);
                    if (canvas) canvas.style.display = 'none';
                    if (fallback) fallback.style.display = 'flex';
                    barcodesGerados++;
                    atualizarProgresso(barcodesGerados, totalBarcodes);
                }
            });
            
            return totalBarcodes;
        }
        
        function atualizarProgresso(atual, total) {
            const loadingDetails = document.getElementById('loadingDetails');
            if (loadingDetails) {
                loadingDetails.textContent = `Gerando ${atual}/${total} códigos de barras...`;
            }
            
            // Verificar se todos foram gerados
            if (atual >= total) {
                console.log('✅ Todos os códigos de barras gerados!');
                if (loadingDetails) {
                    loadingDetails.textContent = '✅ Todos os códigos de barras gerados!';
                }
            }
        }
        
        function triggerAutoPrint() {
            console.log('🎯 Iniciando processo de impressão automática...');
            
            // Mostrar etiquetas
            const container = document.getElementById('etiquetasContainer');
            if (container) {
                container.style.display = 'block';
            }
            
            // Gerar códigos de barras
            const totalBarcodes = gerarCodigosBarras();
            
            // Aguardar um pouco para garantir que os barcodes foram renderizados
            setTimeout(() => {
                // Esconder loading screen com animação
                const loadingScreen = document.getElementById('loadingScreen');
                if (loadingScreen) {
                    loadingScreen.style.opacity = '0';
                    setTimeout(() => {
                        loadingScreen.style.display = 'none';
                        
                        // Aguardar mais um pouco para renderização completa
                        setTimeout(() => {
                            console.log('🖨️ Abrindo caixa de diálogo de impressão...');
                            
                            // Forçar redesenho dos canvases
                            document.querySelectorAll('.barcode-canvas').forEach(canvas => {
                                if (canvas.style.display !== 'none') {
                                    const ctx = canvas.getContext('2d');
                                    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                                    ctx.putImageData(imageData, 0, 0);
                                }
                            });
                            
                            // Imprimir
                            window.print();
                        }, 500);
                    }, 500);
                } else {
                    setTimeout(() => {
                        console.log('🖨️ Abrindo caixa de diálogo de impressão...');
                        window.print();
                    }, 1000);
                }
            }, Math.max(500, totalBarcodes * 10)); // Tempo baseado na quantidade de barcodes
        }
        
        function redirectToEtiquetas() {
            console.log('↩️ Redirecionando para etiquetas_plasma.php...');
            
            // Redirecionar de volta
            setTimeout(() => {
                window.location.href = 'etiquetas_plasma.php';
            }, 1000);
        }

        // Eventos de impressão
        window.addEventListener('beforeprint', function() {
            console.log('📄 Preparando para impressão...');
            const totalEtiquetas = document.querySelectorAll('.etiqueta-container').length;
            console.log(`Total de etiquetas: ${totalEtiquetas}`);
            
            // Garantir que os barcodes estão visíveis
            document.querySelectorAll('.barcode-canvas').forEach(canvas => {
                canvas.style.visibility = 'visible';
                canvas.style.opacity = '1';
            });
        });

        window.addEventListener('afterprint', function() {
            console.log('✅ Impressão concluída ou cancelada. Redirecionando...');
            
            // Aguardar um momento e redirecionar
            setTimeout(redirectToEtiquetas, 1000);
        });

        // Capturar Ctrl+P para redirecionar após imprimir
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                console.log('🖨️ Usuário pressionou Ctrl+P');
                window.print();
            }
            
            // Escape para cancelar e voltar
            if (e.key === 'Escape') {
                console.log('⎋ Usuário pressionou Escape - Cancelando impressão');
                redirectToEtiquetas();
            }
        });

        // Iniciar processo quando a página carregar
        window.onload = function() {
            console.log('🚀 Página de impressão carregada!');
            
            const totalEtiquetas = document.querySelectorAll('.etiqueta-container').length;
            console.log(`Total de etiquetas: ${totalEtiquetas}`);
            
            // Iniciar impressão automática após 1 segundo
            setTimeout(triggerAutoPrint, 1000);
            
            // Timeout de segurança: imprimir mesmo que não tenha carregado tudo
            setTimeout(() => {
                triggerAutoPrint();
            }, 10000); // 10 segundos de timeout
        };
        
        // Fallback para navegadores que não suportam canvas
        function verificarSuporteCanvas() {
            const canvas = document.createElement('canvas');
            return !!(canvas.getContext && canvas.getContext('2d'));
        }
        
        if (!verificarSuporteCanvas()) {
            console.warn('⚠️ Canvas não suportado. Usando fallback para imagens SVG.');
            // Implementar fallback SVG se necessário
        }
    </script>
</body>
</html>