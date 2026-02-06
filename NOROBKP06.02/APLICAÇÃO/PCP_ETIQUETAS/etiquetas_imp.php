<?php
// ================= CONFIGURAÇÃO DE SESSÃO =================
// Configurar timeout da sessão
ini_set('session.gc_maxlifetime', 300);
ini_set('session.cookie_lifetime', 300);
session_set_cookie_params(300);

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações de codificação
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Headers para evitar cache
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Verificar se há OPs na sessão
if (!isset($_SESSION['ops']) || empty($_SESSION['ops'])) {
    // Verificar de onde veio
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'etiquetas.php') !== false) {
        // Se veio de etiquetas.php, pode ser problema de sessão
        echo "<script>
            alert('Nenhuma OP encontrada para impressão.\\n\\nA sessão pode ter expirado ou as OPs foram removidas.\\n\\nVoltando para a página anterior...');
            setTimeout(function() {
                window.history.back();
            }, 1000);
        </script>";
        exit;
    } else {
        // Redirecionar para a página principal
        header('Location: etiquetas.php');
        exit;
    }
}

// Verificar qual TCPDF está disponível
$tcpdfPath = __DIR__ . '/tcpdf/';
if (!file_exists($tcpdfPath . 'tcpdf.php')) {
    die("TCPDF não encontrado em: $tcpdfPath");
}

// Incluir TCPDF
require_once($tcpdfPath . 'tcpdf.php');

// Verificar arquivo de códigos de barras
$barcode1D = $tcpdfPath . 'tcpdf_barcodes_1d.php';
$barcode2D = $tcpdfPath . 'tcpdf_barcodes_2d.php';

if (file_exists($barcode1D)) {
    require_once($barcode1D);
    $barcodeClass = 'TCPDFBarcode';
} elseif (file_exists($barcode2D)) {
    require_once($barcode2D);
    $barcodeClass = 'TCPDF2DBarcode';
} else {
    die("Arquivo de códigos de barras do TCPDF não encontrado!");
}

// ================= FUNÇÕES AUXILIARES =================
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

// Preparar dados das OPs
$listaOps = prepararDadosUTF8($_SESSION['ops']);

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

function gerarBarcodeBase64($texto, $tipo = 'geral') {
    global $barcodeClass;
    
    if (empty(trim($texto))) {
        return '';
    }
    
    $texto_limpo = trim((string)$texto);
    
    // Configurações otimizadas
    $config = [
        'largura_modulo' => 2.5,
        'altura' => 75,
        'cor' => [0, 0, 0],
        'resolucao' => 300,
        'tipo' => 'C128'
    ];
    
    // Ajustar configurações baseado no tipo
    switch($tipo) {
        case 'op':
            $config['largura_modulo'] = 2.8;
            $config['altura'] = 80;
            break;
        case 'produto':
            $config['largura_modulo'] = 2.6;
            $config['altura'] = 78;
            break;
        case 'lote':
            $config['largura_modulo'] = 2.4;
            $config['altura'] = 76;
            break;
    }
    
    try {
        // Criar objeto de código de barras
        if ($barcodeClass === 'TCPDFBarcode') {
            $barcodeobj = new TCPDFBarcode($texto_limpo, $config['tipo']);
            $barcode_data = $barcodeobj->getBarcodePngData(
                $config['largura_modulo'],
                $config['altura'],
                $config['cor'],
                true
            );
        } else {
            $barcodeobj = new TCPDF2DBarcode($texto_limpo, 'CODE128');
            
            if (method_exists($barcodeobj, 'getBarcodePngData')) {
                $barcode_data = $barcodeobj->getBarcodePngData(
                    $config['largura_modulo'],
                    $config['altura'],
                    $config['cor']
                );
            } else {
                $barcode_data = $barcodeobj->getBarcodePNGData(
                    $config['largura_modulo'],
                    $config['altura'],
                    $config['cor']
                );
            }
        }
        
        // Converter para base64
        if (!empty($barcode_data)) {
            // Melhorar qualidade da imagem
            if (function_exists('imagecreatefromstring')) {
                $im = imagecreatefromstring($barcode_data);
                if ($im !== false) {
                    $largura = imagesx($im);
                    $altura = imagesy($im);
                    
                    $nova_im = imagecreatetruecolor($largura, $altura);
                    $branco = imagecolorallocate($nova_im, 255, 255, 255);
                    imagefill($nova_im, 0, 0, $branco);
                    
                    imagecopy($nova_im, $im, 0, 0, 0, 0, $largura, $altura);
                    
                    ob_start();
                    imagepng($nova_im, null, 9, PNG_ALL_FILTERS);
                    $barcode_data_melhorado = ob_get_clean();
                    
                    imagedestroy($im);
                    imagedestroy($nova_im);
                    
                    $barcode_data = $barcode_data_melhorado;
                }
            }
            
            return 'data:image/png;base64,' . base64_encode($barcode_data);
        }
    } catch (Exception $e) {
        error_log("Erro ao gerar código de barras: " . $e->getMessage());
        
        try {
            if ($barcodeClass === 'TCPDFBarcode') {
                $barcodeobj = new TCPDFBarcode($texto_limpo, 'C128');
                $barcode_data = $barcodeobj->getBarcodePngData(2, 70, array(0,0,0));
            } else {
                $barcodeobj = new TCPDF2DBarcode($texto_limpo, 'CODE128');
                if (method_exists($barcodeobj, 'getBarcodePngData')) {
                    $barcode_data = $barcodeobj->getBarcodePngData(2, 70, array(0,0,0));
                }
            }
            
            if (!empty($barcode_data)) {
                return 'data:image/png;base64,' . base64_encode($barcode_data);
            }
        } catch (Exception $e2) {
            error_log("Erro secundário ao gerar código de barras: " . $e2->getMessage());
        }
    }
    
    return '';
}

function exibirTexto($texto) {
    $texto = garantirUTF8((string)$texto);
    return htmlspecialchars($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Gerar todos os códigos de barras antecipadamente (para otimização)
$barcodesPreGerados = [];
foreach ($listaOps as $index => $opItem) {
    $op_numero = $opItem['seqop'] ?? '';
    $codigo = $opItem['cod_produto'] ?? '';
    $lote = $opItem['lote'] ?? '';
    
    $codigo_op = getBarcodeValue($op_numero, 'op');
    $codigo_produto = getBarcodeValue($codigo, 'produto');
    $codigo_lote = getBarcodeValue($lote, 'lote');
    
    $barcodesPreGerados[$index] = [
        'op' => gerarBarcodeBase64($codigo_op, 'op'),
        'produto' => gerarBarcodeBase64($codigo_produto, 'produto'),
        'lote' => gerarBarcodeBase64($codigo_lote, 'lote')
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Impressão de Etiquetas - Sistema</title>
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
        }

        @page {
            size: 567px 450px;
            border: none;
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

        .barcode {
            width: 100%;
            height: 42px;
            object-fit: contain;
            display: block;
            image-rendering: -webkit-optimize-contrast;
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
                background: white;
                padding: 0;
                margin: 0;
                width: 567px;
                height: 450px;
            }

            .etiqueta-container {
                width: 567px;
                height: 450px;
                margin: 0;
                page-break-inside: avoid;
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
            
            .etiqueta-container + .etiqueta-container {
                margin-top: 0;
            }
            
            .barcode {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                image-rendering: crisp-edges !important;
                image-rendering: pixelated !important;
                width: 100% !important;
                height: 42px !important;
                filter: contrast(1.2) !important;
            }
            
            img.barcode {
                display: block !important;
                visibility: visible !important;
                opacity: 1 !important;
            }
            
            .sem-barcode {
                display: none !important;
            }
            
            .campo-conferencia.operador {
                width: 50% !important;
                flex: 0 0 50% !important;
            }
            
            .campo-conferencia.quantidade {
                width: 20% !important;
                flex: 0 0 20% !important;
            }
            
            .campo-conferencia.data {
                width: 30% !important;
                flex: 0 0 30% !important;
            }
            
            .titulo-central {
                flex: 1 !important;
            }
            
            .label-op {
                line-height: 1.1 !important;
            }
            
            .info-op-direita {
                flex-shrink: 0 !important;
            }
            
            .loading-screen {
                display: none !important;
            }
            
            .contador-copia {
                display: none !important;
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
            <br>Aguarde um momento enquanto carregamos todos os códigos de barras.
        </div>
        <div class="loading-details" id="loadingDetails">
            Preparando <?php echo count($listaOps); ?> OPs para impressão...
        </div>
    </div>

    <!-- Conteúdo das etiquetas (inicialmente oculto) -->
    <div id="etiquetasContainer" style="display: none;">
        <?php
        $contadorTotal = 0;
        $contadorOP = 0;
        foreach ($listaOps as $index => $opItem):
            $quantidade = isset($opItem['quantidade']) ? (int)$opItem['quantidade'] : 1;
            
            // Obter dados
            $op_numero = isset($opItem['seqop']) ? exibirTexto($opItem['seqop']) : '';
            $codigo = isset($opItem['cod_produto']) ? exibirTexto($opItem['cod_produto']) : '';
            $lote = isset($opItem['lote']) ? exibirTexto($opItem['lote']) : '';
            $produto_nome = isset($opItem['produto']) ? exibirTexto($opItem['produto']) : '';
            $cliente_nome = isset($opItem['nome_cliente']) ? exibirTexto($opItem['nome_cliente']) : '';
            $cidade = isset($opItem['cidade']) ? exibirTexto($opItem['cidade']) : 'CIDADE NÃO INFORMADA';
            
            // Obter códigos de barras pré-gerados
            $barcode_op = $barcodesPreGerados[$index]['op'] ?? '';
            $barcode_produto = $barcodesPreGerados[$index]['produto'] ?? '';
            $barcode_lote = $barcodesPreGerados[$index]['lote'] ?? '';
            
            $loteNulo = empty($lote) || $lote == 'LOTE NÃO DEFINIDO';
            $cidadeNula = $cidade == 'CIDADE NÃO INFORMADA';
            
            // Gerar múltiplas cópias da mesma OP
            for ($copia = 1; $copia <= $quantidade; $copia++):
                $contadorTotal++;
                $contadorOP++;
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
                            <div class="barcode-container">
                                <?php if (!empty($barcode_op)): ?>
                                    <img src="<?php echo $barcode_op; ?>"
                                        class="barcode"
                                        alt="Código de Barras OP: <?php echo $op_numero; ?>"
                                        data-type="op"
                                        data-value="<?php echo htmlspecialchars($op_numero, ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="handleBarcodeError(this)"
                                        onload="handleBarcodeLoad(this)"
                                        style="filter: contrast(1.1);">
                                    <div class="sem-barcode" style="display: none;" data-type="op">SEM CÓDIGO OP</div>
                                <?php else: ?>
                                    <div class="sem-barcode" data-type="op">SEM CÓDIGO OP</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="side-card" data-type="produto">
                            <div class="side-card-title">PRODUTO</div>
                            <div class="side-card-value"><?php echo $codigo; ?></div>
                            <div class="barcode-container">
                                <?php if (!empty($barcode_produto)): ?>
                                    <img src="<?php echo $barcode_produto; ?>"
                                        class="barcode"
                                        alt="Código de Produto: <?php echo $codigo; ?>"
                                        data-type="produto"
                                        data-value="<?php echo htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="handleBarcodeError(this)"
                                        onload="handleBarcodeLoad(this)"
                                        style="filter: contrast(1.1);">
                                    <div class="sem-barcode" style="display: none;" data-type="produto">SEM CÓDIGO</div>
                                <?php else: ?>
                                    <div class="sem-barcode" data-type="produto">SEM CÓDIGO</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="side-card" data-type="lote">
                            <div class="side-card-title">LOTE</div>
                            <div class="side-card-value <?php echo $loteNulo ? 'dado-nulo' : ''; ?>">
                                <?php echo $loteNulo ? 'LOTE NÃO DEFINIDO' : $lote; ?>
                            </div>
                            <div class="barcode-container">
                                <?php if (!empty($barcode_lote)): ?>
                                    <img src="<?php echo $barcode_lote; ?>"
                                        class="barcode"
                                        alt="Código de Lote: <?php echo $lote; ?>"
                                        data-type="lote"
                                        data-value="<?php echo htmlspecialchars($lote, ENT_QUOTES, 'UTF-8'); ?>"
                                        onerror="handleBarcodeError(this)"
                                        onload="handleBarcodeLoad(this)"
                                        style="filter: contrast(1.1);">
                                    <div class="sem-barcode" style="display: none;" data-type="lote">SEM LOTE</div>
                                <?php else: ?>
                                    <div class="sem-barcode" data-type="lote">SEM LOTE</div>
                                <?php endif; ?>
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
        // Variáveis de controle
        let barcodesLoaded = 0;
        let totalBarcodes = 0;
        let autoPrintTriggered = false;
        let allBarcodesLoaded = false;
        
        function handleBarcodeLoad(imgElement) {
            barcodesLoaded++;
            updateLoadingProgress();
            
            var tipo = imgElement.getAttribute('data-type');
            var valor = imgElement.getAttribute('data-value');
            
            console.log(`✓ Código de barras carregado (${barcodesLoaded}/${totalBarcodes}):`, {
                tipo: tipo,
                valor: valor
            });
            
            imgElement.style.imageRendering = 'crisp-edges';
            imgElement.style.imageRendering = 'pixelated';
            
            var container = imgElement.closest('.barcode-container');
            var fallback = container.querySelector('.sem-barcode');
            if (fallback && fallback.style.display !== 'none') {
                fallback.style.display = 'none';
            }
            
            // Verificar se todos os códigos de barras foram carregados
            if (barcodesLoaded >= totalBarcodes) {
                allBarcodesLoaded = true;
                console.log('✅ Todos os códigos de barras carregados!');
                if (!autoPrintTriggered) {
                    triggerAutoPrint();
                }
            }
        }

        function handleBarcodeError(imgElement) {
            barcodesLoaded++;
            updateLoadingProgress();
            
            var tipo = imgElement.getAttribute('data-type');
            var valor = imgElement.getAttribute('data-value');
            
            console.error(`✗ Erro ao carregar código de barras (${barcodesLoaded}/${totalBarcodes}):`, {
                tipo: tipo,
                valor: valor
            });
            
            imgElement.style.display = 'none';
            
            var container = imgElement.closest('.barcode-container');
            var fallback = container.querySelector('.sem-barcode');
            
            if (fallback) {
                fallback.style.display = 'flex';
                
                var textos = {
                    'op': 'SEM CÓDIGO OP',
                    'produto': 'SEM PRODUTO',
                    'lote': 'SEM LOTE'
                };
                
                if (textos[tipo]) {
                    fallback.textContent = textos[tipo];
                }
            }
            
            // Verificar se todos foram processados (mesmo com erro)
            if (barcodesLoaded >= totalBarcodes) {
                allBarcodesLoaded = true;
                console.log('✅ Todos os códigos de barras processados!');
                if (!autoPrintTriggered) {
                    triggerAutoPrint();
                }
            }
        }
        
        function updateLoadingProgress() {
            const loadingDetails = document.getElementById('loadingDetails');
            if (loadingDetails) {
                const progress = Math.round((barcodesLoaded / totalBarcodes) * 100);
                loadingDetails.innerHTML = `
                    Carregando códigos de barras...<br>
                    <small>${barcodesLoaded} de ${totalBarcodes} (${progress}%)</small>
                `;
            }
        }
        
        function triggerAutoPrint() {
            if (autoPrintTriggered) return;
            
            autoPrintTriggered = true;
            console.log('🎯 Iniciando processo de impressão automática...');
            
            // Mostrar etiquetas
            const container = document.getElementById('etiquetasContainer');
            if (container) {
                container.style.display = 'block';
            }
            
            // Esconder loading screen
            const loadingScreen = document.getElementById('loadingScreen');
            if (loadingScreen) {
                loadingScreen.style.opacity = '0';
                setTimeout(() => {
                    loadingScreen.style.display = 'none';
                    
                    // Aguardar e imprimir
                    setTimeout(() => {
                        console.log('🖨️ Abrindo caixa de diálogo de impressão...');
                        window.print();
                    }, 500);
                }, 500);
            } else {
                setTimeout(() => {
                    console.log('🖨️ Abrindo caixa de diálogo de impressão...');
                    window.print();
                }, 1000);
            }
        }
        
        function redirectToEtiquetas() {
            console.log('↩️ Redirecionando para etiquetas.php...');
            
            // Redirecionar
            setTimeout(() => {
                window.location.href = 'etiquetas.php';
            }, 1000);
        }

        window.addEventListener('beforeprint', function() {
            console.log('📄 Preparando para impressão...');
            const totalEtiquetas = document.querySelectorAll('.etiqueta-container').length;
            console.log(`Total de etiquetas: ${totalEtiquetas}`);
            
            // Melhorar contraste para impressão
            document.querySelectorAll('img.barcode').forEach(function(img) {
                img.style.filter = 'contrast(1.2)';
            });
        });

        window.addEventListener('afterprint', function() {
            console.log('✅ Impressão concluída ou cancelada. Redirecionando...');
            
            // Aguardar e redirecionar
            setTimeout(redirectToEtiquetas, 1000);
        });

        // Capturar Ctrl+P
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                console.log('🖨️ Usuário pressionou Ctrl+P');
                window.print();
            }
            
            // Escape para cancelar
            if (e.key === 'Escape') {
                console.log('⎋ Usuário pressionou Escape - Cancelando impressão');
                redirectToEtiquetas();
            }
        });

        // Iniciar processo quando a página carregar
        window.onload = function() {
            console.log('🚀 Página de impressão carregada!');
            
            // Contar total de códigos de barras
            totalBarcodes = document.querySelectorAll('img.barcode').length;
            const totalEtiquetas = document.querySelectorAll('.etiqueta-container').length;
            console.log(`Total de códigos de barras a carregar: ${totalBarcodes}`);
            console.log(`Total de etiquetas: ${totalEtiquetas}`);
            
            updateLoadingProgress();
            
            // Configurar eventos para os códigos de barras
            document.querySelectorAll('img.barcode').forEach(function(img) {
                if (img.complete) {
                    if (img.naturalHeight === 0) {
                        handleBarcodeError(img);
                    } else {
                        handleBarcodeLoad(img);
                    }
                }
                
                img.addEventListener('load', function() {
                    handleBarcodeLoad(this);
                });
                img.addEventListener('error', function() {
                    handleBarcodeError(this);
                });
            });
            
            // Timeout de segurança
            setTimeout(() => {
                if (!autoPrintTriggered) {
                    console.log('⏰ Timeout de segurança - Imprimindo mesmo sem todos os códigos carregados');
                    triggerAutoPrint();
                }
            }, 10000);
            
            // Redirecionar se o usuário tentar sair sem imprimir
            window.addEventListener('beforeunload', function(e) {
                if (!autoPrintTriggered) {
                    redirectToEtiquetas();
                }
            });
        };
    </script>
</body>
</html>