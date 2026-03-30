<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();  // APENAS ISSO!
}
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// ================= VERIFICAÇÃO DE SESSÃO =================
if (!isset($_SESSION['ops_cruzadas']) || empty($_SESSION['ops_cruzadas'])) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (strpos($referer, 'etiquetas_plasma.php') !== false) {
        echo "<script>
            alert('Nenhuma OP encontrada para impressão.\\n\\nA sessão pode ter expirado ou as OPs foram removidas.');
            if (window.opener) {
                window.opener.focus();
                window.close();
            } else {
                window.location.href = 'etiquetas_plasma.php';
            }
        </script>";
        exit;
    } else {
        header('Location: etiquetas_plasma.php');
        exit;
    }
}

// ================= TCPDF - MESMO DO OUTRO ARQUIVO =================
$tcpdfPath = __DIR__ . '/tcpdf/';
if (!file_exists($tcpdfPath . 'tcpdf.php')) {
    die("TCPDF não encontrado em: $tcpdfPath");
}

require_once($tcpdfPath . 'tcpdf.php');

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
function garantirUTF8($string)
{
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

function prepararDadosUTF8($dados)
{
    if (is_array($dados)) {
        array_walk_recursive($dados, function (&$valor, $chave) {
            if (is_string($valor)) {
                $valor = garantirUTF8($valor);
            }
        });
    } elseif (is_string($dados)) {
        $dados = garantirUTF8($dados);
    }
    return $dados;
}

function getBarcodeValue($valor, $tipo = 'geral')
{
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
                $valor = sprintf(
                    '%02d-%02d-%04d',
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

function gerarBarcodeBase64($texto, $tipo = 'geral')
{
    global $barcodeClass;

    if (empty(trim($texto))) {
        return '';
    }

    $texto_limpo = trim((string)$texto);

    $config = [
        'largura_modulo' => 2.5,
        'altura' => 75,
        'cor' => [0, 0, 0],
        'resolucao' => 300,
        'tipo' => 'C128'
    ];

    switch ($tipo) {
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

        if (!empty($barcode_data)) {
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
                $barcode_data = $barcodeobj->getBarcodePngData(2, 70, array(0, 0, 0));
            } else {
                $barcodeobj = new TCPDF2DBarcode($texto_limpo, 'CODE128');
                if (method_exists($barcodeobj, 'getBarcodePngData')) {
                    $barcode_data = $barcodeobj->getBarcodePngData(2, 70, array(0, 0, 0));
                }
            }

            if (!empty($barcode_data)) {
                return 'data:image/png;base64,' . base64_encode($barcode_data);
            }
        } catch (Exception $e2) {
            error_log("Erro secundário: " . $e2->getMessage());
        }
    }

    return '';
}

function exibirTexto($texto)
{
    $texto = garantirUTF8((string)$texto);
    return htmlspecialchars($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// Processar dados
$listaOps = prepararDadosUTF8($_SESSION['ops_cruzadas']);

// ================= GERAR CÓDIGOS DE BARRAS ANTECIPADAMENTE =================
$barcodesPreGerados = [];
foreach ($listaOps as $index => $opItem) {
    $op_numero = $opItem['seqop'] ?? '';
    $pedido = $opItem['pedido'] ?? '';
    $codigo = strlen($pedido) > 8 ? substr($pedido, 0, 8) : $pedido;
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
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impressão de Etiquetas - Sistema Plasma / Laser</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =============== ESTILOS GLOBAIS =============== */
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
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
            overflow: hidden;
        }

        .info-section-lateral {
            display: flex;
            flex-direction: column;
            gap: 10px;
            flex: 1;
            overflow: hidden;
        }

        .info-card-lateral {
            background: #fff;
            border: 1px solid #000;
            border-radius: 3px;
            padding: 8px;
            position: relative;
            min-height: 50px;
            overflow: hidden;
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

        /* ========== ESTILOS PARA TRUNCAR TEXTO DO PRODUTO NA IMPRESSÃO ========== */
        .info-value-lateral-produto {
            font-size: 17px;
            font-weight: 600;
            color: #000;
            line-height: 1.3;
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;

            /* Truncar texto com reticências - limite de 2 linhas */
            display: -webkit-box;
            -webkit-line-clamp: 5;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            max-height: calc(1.3em * 5);
            /* 2 linhas * line-height */
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
            white-space: nowrap;
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

        /* Tooltip para texto truncado (apenas visualização na tela, não imprime) */
        @media screen {
            .info-value-lateral-produto[title] {
                cursor: help;
                border-bottom: 1px dotted #999;
            }
        }

        /* =============== TELA DE CARREGAMENTO =============== */
        .loading-screen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
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
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-bottom: 20px;
        }

        .loading-text {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
        }

        .loading-subtext {
            font-size: 16px;
            opacity: 0.9;
            max-width: 500px;
            text-align: center;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .loading-details {
            background: rgba(255, 255, 255, 0.15);
            padding: 15px 25px;
            border-radius: 10px;
            margin-top: 10px;
            font-size: 16px;
            text-align: center;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-cancelar {
            margin-top: 40px;
            padding: 15px 40px;
            background: rgba(255, 255, 255, 0.15);
            border: 2px solid white;
            color: white;
            font-size: 18px;
            font-weight: bold;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s ease;
            animation: pulse 2s infinite;
            backdrop-filter: blur(5px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .btn-cancelar:hover {
            background: rgba(255, 107, 107, 0.3);
            transform: scale(1.05);
            border-color: #ff6b6b;
            color: #ff6b6b;
            box-shadow: 0 0 20px rgba(255, 107, 107, 0.5);
        }

        .btn-cancelar i {
            font-size: 20px;
        }

        .btn-cancelar:active {
            transform: scale(0.98);
        }

        .btn-cancelar:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            animation: none;
            transform: none;
        }

        .progress-bar-container {
            width: 400px;
            height: 8px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            margin-top: 20px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: white;
            width: 0%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4);
            }

            70% {
                box-shadow: 0 0 0 15px rgba(255, 255, 255, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* =============== ESTILOS DE IMPRESSÃO =============== */
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
                box-shadow: none;
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

            .etiqueta-container+.etiqueta-container {
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

            .loading-screen {
                display: none !important;
            }

            .contador-copia {
                display: none !important;
            }

            .btn-cancelar {
                display: none !important;
            }

            .progress-bar-container {
                display: none !important;
            }

            .info-value-lateral-produto {
                -webkit-line-clamp: 5 !important;
                /* Altere para o mesmo valor que você quer */
                overflow: hidden !important;
                text-overflow: ellipsis !important;
                max-height: calc(1.3em * 5) !important;
                /* Adicione esta linha também */
            }
        }
    </style>
</head>

<body>
    <!-- =============== TELA DE CARREGAMENTO =============== -->
    <div class="loading-screen" id="loadingScreen">
        <div class="loading-spinner"></div>
        <div class="loading-text">Preparando impressão...</div>
        <div class="loading-subtext">
            <i class="fas fa-tag"></i> Gerando etiquetas e códigos de barras<br>
            <small style="font-size: 14px; opacity: 0.8;">Aguarde enquanto preparamos suas etiquetas</small>
        </div>

        <!-- Barra de progresso -->
        <div class="progress-bar-container" id="progressBarContainer">
            <div class="progress-bar" id="progressBar"></div>
        </div>

        <div class="loading-details" id="loadingDetails">
            <i class="fas fa-cog fa-spin"></i> Carregando códigos de barras...
        </div>

        <!-- Botão de cancelar -->
        <button id="btnCancelar" class="btn-cancelar" onclick="cancelarImpressao()">
            <i class="fas fa-times-circle"></i> Cancelar Impressão
        </button>

        <div style="margin-top: 20px; font-size: 14px; opacity: 0.7;">
            <i class="fas fa-keyboard"></i> Pressione ESC para cancelar
        </div>
    </div>

    <!-- =============== CONTEÚDO DAS ETIQUETAS =============== -->
    <div id="etiquetasContainer" style="display: none;">
        <?php
        $contadorTotal = 0;

        // Para cada OP na sessão
        foreach ($listaOps as $opIndex => $opItem):
            $quantidade = isset($opItem['quantidade']) ? (int)$opItem['quantidade'] : 1;

            // Preparar dados base (uma vez só)
            $op_numero = isset($opItem['seqop']) ? exibirTexto($opItem['seqop']) : '';
            $lote = isset($opItem['lote']) ? exibirTexto($opItem['lote']) : '';
            $produto_nome = isset($opItem['descricao_produto']) ? exibirTexto($opItem['descricao_produto']) : 'DESCRIÇÃO NÃO ENCONTRADA';
            $cliente_nome = isset($opItem['nome_cliente']) ? exibirTexto($opItem['nome_cliente']) : 'CLIENTE NÃO INFORMADO';
            $cidade = isset($opItem['cidade']) ? exibirTexto($opItem['cidade']) : 'CIDADE NÃO INFORMADA';
            $pedido = isset($opItem['pedido']) ? exibirTexto($opItem['pedido']) : 'NÃO INFORMADO';

            // Usar o pedido como código de produto
            $codigo = strlen($pedido) > 8 ? substr($pedido, 0, 8) : $pedido;

            // VALORES PARA CÓDIGO DE BARRAS (sem gerar a imagem ainda)
            $codigo_op = getBarcodeValue($opItem['seqop'] ?? '', 'op');
            $codigo_produto = getBarcodeValue($codigo, 'produto');
            $codigo_lote = getBarcodeValue($opItem['lote'] ?? '', 'lote');

            $loteNulo = empty($lote) || $lote == 'LOTE NÃO DEFINIDO';
            $cidadeNula = $cidade == 'CIDADE NÃO INFORMADA';

            // GERAR MÚLTIPLAS CÓPIAS
            for ($copia = 1; $copia <= $quantidade; $copia++):
                $contadorTotal++;

                // GERAR CÓDIGO DE BARRAS ÚNICO PARA CADA CÓPIA
                $barcode_op = gerarBarcodeBase64($codigo_op, 'op');
                $barcode_produto = gerarBarcodeBase64($codigo_produto, 'produto');
                $barcode_lote = gerarBarcodeBase64($codigo_lote, 'lote');
        ?>
                <div class="etiqueta-container" data-etiqueta="<?php echo $contadorTotal; ?>" data-op="<?php echo $op_numero; ?>">
                    <?php if ($copia > 1): ?>
                        <div class="contador-copia" title="Cópia <?php echo $copia; ?> de <?php echo $quantidade; ?>">
                            <i class="fas fa-copy"></i> <?php echo $copia; ?>/<?php echo $quantidade; ?>
                        </div>
                    <?php endif; ?>

                    <div class="cabecalho">
                        <div class="logo-container">
                            <img src="imgs/noroaco.png" class="logo" alt="NOROAÇO" onerror="this.style.display='none'">
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
                                    <span class="info-label-lateral"><i class="fas fa-box"></i> PRODUTO:</span>
                                    <div class="info-value-lateral-produto" title="<?php echo $produto_nome; ?>">
                                        <?php echo $produto_nome; ?>
                                    </div>
                                </div>

                                <div class="info-card-lateral cliente">
                                    <span class="info-label-lateral"><i class="fas fa-user"></i> CLIENTE:</span>
                                    <div class="info-value-lateral">
                                        <?php echo $cliente_nome; ?>
                                    </div>
                                </div>

                                <div class="info-card-lateral cidade">
                                    <span class="info-label-lateral"><i class="fas fa-map-marker-alt"></i> CIDADE:</span>
                                    <div class="info-value-lateral <?php echo $cidadeNula ? 'dado-nulo' : ''; ?>">
                                        <?php echo $cidade; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="codes-section">
                            <div class="side-card" data-type="op">
                                <div class="side-card-title"><i class="fas fa-barcode"></i> OP Nº</div>
                                <div class="side-card-value"><?php echo $op_numero; ?></div>
                                <div class="barcode-container">
                                    <?php if (!empty($barcode_op)): ?>
                                        <img src="<?php echo $barcode_op; ?>"
                                            class="barcode"
                                            alt="Código de Barras OP: <?php echo $op_numero; ?>"
                                            data-type="op"
                                            data-value="<?php echo htmlspecialchars($op_numero, ENT_QUOTES, 'UTF-8'); ?>"
                                            onerror="handleBarcodeError(this)"
                                            onload="handleBarcodeLoad(this)">
                                        <div class="sem-barcode" style="display: none;" data-type="op">SEM CÓDIGO OP</div>
                                    <?php else: ?>
                                        <div class="sem-barcode" data-type="op">SEM CÓDIGO OP</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="side-card" data-type="produto">
                                <div class="side-card-title"><i class="fas fa-file-invoice"></i> PEDIDO</div>
                                <div class="side-card-value"><?php echo $codigo; ?></div>
                                <div class="barcode-container">
                                    <?php if (!empty($barcode_produto)): ?>
                                        <img src="<?php echo $barcode_produto; ?>"
                                            class="barcode"
                                            alt="Código de Pedido: <?php echo $codigo; ?>"
                                            data-type="produto"
                                            data-value="<?php echo htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8'); ?>"
                                            onerror="handleBarcodeError(this)"
                                            onload="handleBarcodeLoad(this)">
                                        <div class="sem-barcode" style="display: none;" data-type="produto">SEM CÓDIGO</div>
                                    <?php else: ?>
                                        <div class="sem-barcode" data-type="produto">SEM CÓDIGO</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="side-card" data-type="lote">
                                <div class="side-card-title"><i class="fas fa-calendar-alt"></i> LOTE</div>
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
                                            onload="handleBarcodeLoad(this)">
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
                            <i class="fas fa-check-circle"></i> CONFERÊNCIA
                        </div>

                        <div class="conferencia-area">
                            <div class="campo-conferencia operador">
                                <span class="campo-label"><i class="fas fa-user-check"></i> OPERADOR</span>
                                <div class="campo-borda"></div>
                            </div>

                            <div class="campo-conferencia quantidade">
                                <span class="campo-label"><i class="fas fa-cubes"></i> QUANTIDADE</span>
                                <div class="campo-borda"></div>
                            </div>

                            <div class="campo-conferencia data">
                                <span class="campo-label"><i class="fas fa-calendar"></i> DATA</span>
                                <div class="campo-borda"></div>
                            </div>
                        </div>
                    </div>
                </div>
        <?php
            endfor; // fim do loop de cópias
        endforeach; // fim do loop de OPs
        ?>
    </div>

    <!-- =============== JAVASCRIPT =============== -->
    <script>
        // Variáveis de controle
        let barcodesLoaded = 0;
        let totalBarcodes = 0;
        let autoPrintTriggered = false;
        let allBarcodesLoaded = false;
        let printDialogOpened = false;
        let isCancelling = false;

        // =============== FUNÇÕES DE GERENCIAMENTO DE GUIA ===============

        /**
         * Cancela a impressão e fecha a guia
         */
        function cancelarImpressao() {
            if (isCancelling) return;
            isCancelling = true;

            console.log('❌ Impressão cancelada pelo usuário');

            const btn = document.getElementById('btnCancelar');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Cancelando...';
            }

            // Mostrar mensagem de cancelamento
            const loadingScreen = document.getElementById('loadingScreen');
            if (loadingScreen) {
                loadingScreen.innerHTML = `
                    <div style="text-align: center; animation: fadeIn 0.5s;">
                        <i class="fas fa-check-circle" style="font-size: 80px; margin-bottom: 20px; color: #2ecc71;"></i>
                        <div class="loading-text">Operação cancelada!</div>
                        <div class="loading-subtext" style="margin-bottom: 30px;">
                            Nenhuma etiqueta foi impressa.<br>
                            Voltando para a página anterior...
                        </div>
                        <div class="loading-spinner" style="border-top-color: #2ecc71;"></div>
                    </div>
                `;
            }

            // Fechar a guia após um momento
            setTimeout(function() {
                fecharGuia();
            }, 1500);
        }

        /**
         * Fecha a guia atual e volta para a página anterior
         */
        function fecharGuia() {
            console.log('🔒 Fechando guia...');

            // Tentar fechar a janela
            if (window.opener && !window.opener.closed) {
                try {
                    // Voltar o foco para a página anterior
                    if (typeof window.opener.focus === 'function') {
                        window.opener.focus();
                    }

                    // Fechar a guia atual
                    window.close();

                    // Fallback: se não fechar, redirecionar
                    setTimeout(function() {
                        window.location.href = 'etiquetas_plasma.php';
                    }, 500);
                } catch (e) {
                    console.error('Erro ao fechar janela:', e);
                    window.location.href = 'etiquetas_plasma.php';
                }
            } else {
                // Se não tiver opener, redirecionar
                window.location.href = 'etiquetas_plasma.php';
            }
        }

        // =============== FUNÇÕES DE CÓDIGOS DE BARRAS ===============

        function handleBarcodeLoad(imgElement) {
            barcodesLoaded++;
            updateLoadingProgress();

            const tipo = imgElement.getAttribute('data-type');
            const valor = imgElement.getAttribute('data-value');

            console.log(`✓ Código carregado (${barcodesLoaded}/${totalBarcodes}):`, {
                tipo,
                valor
            });

            imgElement.style.imageRendering = 'crisp-edges';
            imgElement.style.imageRendering = 'pixelated';

            const container = imgElement.closest('.barcode-container');
            const fallback = container?.querySelector('.sem-barcode');
            if (fallback && fallback.style.display !== 'none') {
                fallback.style.display = 'none';
            }

            // Verificar se todos foram carregados
            if (barcodesLoaded >= totalBarcodes) {
                allBarcodesLoaded = true;
                console.log('✅ Todos os códigos carregados!');

                const progressBar = document.getElementById('progressBar');
                if (progressBar) progressBar.style.width = '100%';

                if (!autoPrintTriggered && !isCancelling) {
                    triggerAutoPrint();
                }
            }
        }

        function handleBarcodeError(imgElement) {
            barcodesLoaded++;
            updateLoadingProgress();

            const tipo = imgElement.getAttribute('data-type');
            const valor = imgElement.getAttribute('data-value');

            console.error(`✗ Erro no código (${barcodesLoaded}/${totalBarcodes}):`, {
                tipo,
                valor
            });

            imgElement.style.display = 'none';

            const container = imgElement.closest('.barcode-container');
            const fallback = container?.querySelector('.sem-barcode');

            if (fallback) {
                fallback.style.display = 'flex';

                const textos = {
                    'op': 'SEM CÓDIGO OP',
                    'produto': 'SEM PEDIDO',
                    'lote': 'SEM LOTE'
                };

                if (textos[tipo]) {
                    fallback.textContent = textos[tipo];
                }
            }

            // Verificar se todos foram processados
            if (barcodesLoaded >= totalBarcodes) {
                allBarcodesLoaded = true;
                console.log('✅ Todos os códigos processados!');

                if (!autoPrintTriggered && !isCancelling) {
                    triggerAutoPrint();
                }
            }
        }

        function updateLoadingProgress() {
            const loadingDetails = document.getElementById('loadingDetails');
            const progressBar = document.getElementById('progressBar');

            if (loadingDetails && totalBarcodes > 0) {
                const progress = Math.round((barcodesLoaded / totalBarcodes) * 100);

                if (progressBar) {
                    progressBar.style.width = progress + '%';
                }

                loadingDetails.innerHTML = `
                    <i class="fas fa-spinner fa-pulse"></i> Carregando códigos de barras...<br>
                    <strong style="font-size: 24px; margin: 10px 0; display: block;">${progress}%</strong>
                    <small style="opacity: 0.8;">${barcodesLoaded} de ${totalBarcodes} códigos</small>
                `;
            }
        }

        function triggerAutoPrint() {
            if (autoPrintTriggered || isCancelling) return;

            autoPrintTriggered = true;
            printDialogOpened = true;

            console.log('🎯 Iniciando impressão automática...');

            // Mostrar etiquetas
            const container = document.getElementById('etiquetasContainer');
            if (container) container.style.display = 'block';

            // Esconder loading screen
            const loadingScreen = document.getElementById('loadingScreen');
            if (loadingScreen) {
                loadingScreen.style.opacity = '0';
                setTimeout(() => {
                    loadingScreen.style.display = 'none';
                    setTimeout(() => window.print(), 500);
                }, 500);
            } else {
                setTimeout(() => window.print(), 1000);
            }
        }

        // =============== FUNÇÃO PARA ADICIONAR TOOLTIP NAS DESCRIÇÕES TRUNCADAS ===============
        function adicionarTooltipDescricao() {
            document.querySelectorAll('.info-value-lateral-produto').forEach(function(el) {
                // Verifica se o texto está realmente truncado (scrollHeight > clientHeight)
                if (el.scrollHeight > el.clientHeight + 2) {
                    // Já tem title? Se não tiver, adiciona
                    if (!el.getAttribute('title')) {
                        el.setAttribute('title', el.textContent.trim());
                    }
                } else {
                    // Se não está truncado, remove o title para não mostrar tooltip desnecessário
                    if (el.getAttribute('title') && !el.hasAttribute('data-force-title')) {
                        el.removeAttribute('title');
                    }
                }
            });
        }

        // =============== EVENT LISTENERS ===============

        window.onload = function() {
            console.log('🚀 Página de impressão carregada!');

            // Verificar se tem opener
            console.log('📌 Opener:', window.opener ? 'Sim' : 'Não');

            // Contar códigos de barras
            totalBarcodes = document.querySelectorAll('img.barcode').length;
            const totalEtiquetas = document.querySelectorAll('.etiqueta-container').length;

            console.log(`📊 Total de códigos: ${totalBarcodes}, Etiquetas: ${totalEtiquetas}`);

            // Atualizar informações de carregamento
            updateLoadingProgress();

            // Configurar eventos dos códigos de barras
            document.querySelectorAll('img.barcode').forEach(function(img) {
                if (img.complete) {
                    img.naturalHeight === 0 ? handleBarcodeError(img) : handleBarcodeLoad(img);
                }
                img.addEventListener('load', function() {
                    handleBarcodeLoad(this);
                });
                img.addEventListener('error', function() {
                    handleBarcodeError(this);
                });
            });

            // Se não houver códigos, imprimir imediatamente
            if (totalBarcodes === 0) {
                console.log('⚠️ Nenhum código de barras');
                allBarcodesLoaded = true;
                triggerAutoPrint();
            }

            // Adicionar tooltips nas descrições truncadas
            adicionarTooltipDescricao();

            // Timeout de segurança
            setTimeout(() => {
                if (!autoPrintTriggered && !isCancelling) {
                    console.log('⏰ Timeout de segurança');
                    triggerAutoPrint();
                }
            }, 15000);
        };

        // Evento antes da impressão
        window.addEventListener('beforeprint', function() {
            console.log('📄 Preparando impressão...');
            document.querySelectorAll('img.barcode').forEach(img => {
                img.style.filter = 'contrast(1.2)';
            });
        });

        // Evento depois da impressão
        window.addEventListener('afterprint', function() {
            console.log('✅ Impressão concluída ou cancelada');
            if (!isCancelling) {
                setTimeout(fecharGuia, 1000);
            }
        });

        // Tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                console.log('⎋ ESC pressionado');
                if (!autoPrintTriggered && !isCancelling) {
                    cancelarImpressao();
                }
            }

            // Ctrl+P
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                console.log('🖨️ Ctrl+P');
                if (!autoPrintTriggered && !isCancelling) {
                    triggerAutoPrint();
                } else {
                    window.print();
                }
            }
        });

        // Evento de descarregamento da página
        window.addEventListener('beforeunload', function(e) {
            if (autoPrintTriggered || isCancelling) {
                return; // Fechar sem confirmação
            }

            if (!printDialogOpened) {
                const mensagem = 'A impressão não foi concluída. Deseja realmente sair?';
                e.returnValue = mensagem;
                return mensagem;
            }
        });
    </script>
</body>

</html>