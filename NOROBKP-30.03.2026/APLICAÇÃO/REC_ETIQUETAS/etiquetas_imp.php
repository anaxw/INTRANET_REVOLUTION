<?php
session_start();
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Verificar se TCPDF existe e incluir corretamente
$tcpdfPath = __DIR__ . '/tcpdf/';
if (!file_exists($tcpdfPath . 'tcpdf.php')) {
    die("TCPDF não encontrado em: $tcpdfPath");
}

// Incluir TCPDF
require_once($tcpdfPath . 'tcpdf.php');

// Verificar qual arquivo de códigos de barras existe
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

// Verificar se há notas na sessão
if (!isset($_SESSION['notas']) || empty($_SESSION['notas'])) {
    die("
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Erro - Nenhuma Nota</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #f0f0f0;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
            }
            .erro {
                background: #fff;
                padding: 40px;
                border-radius: 10px;
                text-align: center;
                box-shadow: 0 5px 15px rgba(0,0,0,0.15);
            }
        </style>
    </head>
    <body>
        <div class='erro'>
            <h2>Nenhuma nota encontrada</h2>
            <p>Volte à tela principal e adicione uma nota.</p>
            <a href='etiquetas.php'>← Voltar</a>
        </div>
    </body>
    </html>
    ");
}

$listaNotas = prepararDadosUTF8($_SESSION['notas']);

function getBarcodeValue($valor, $tipo = 'geral') {
    $valor = garantirUTF8((string)$valor);
    
    $valor = trim($valor);
    
    if (empty($valor) || $valor == 'N/D') {
        return '';
    }
    
    // Converter para inteiro se for numérico
    if (is_numeric($valor)) {
        $valor = (int)$valor;
    }
    
    $valor = (string)$valor;
    
    // Manter apenas números
    $valor = preg_replace('/[^0-9]/', '', $valor);
    
    return $valor;
}

function formatarQuantidade($valor) {
    if (empty($valor) || $valor == 0) {
        return '0';
    }
    
    // Tentar converter para número
    if (is_numeric($valor)) {
        $valor = (float)$valor;
        // Formatar sem casas decimais se for inteiro
        if ($valor == (int)$valor) {
            return number_format((int)$valor, 0, '', '.');
        }
        // Formatar com 3 casas decimais
        return number_format($valor, 3, ',', '.');
    }
    
    return $valor;
}

function gerarBarcodeBase64($texto, $tipo = 'geral') {
    global $barcodeClass;
    
    if (empty(trim($texto))) {
        return '';
    }
    
    $texto_limpo = trim((string)$texto);
    
    // Configurações otimizadas para melhor definição
    $config = [
        'largura_modulo' => 2.5,
        'altura' => 75,
        'cor' => [0, 0, 0],
        'resolucao' => 300,
        'tipo' => 'C128'
    ];
    
    // Ajustar configurações baseado no tipo
    switch($tipo) {
        case 'nota':
            $config['largura_modulo'] = 2.8;
            $config['altura'] = 80;
            break;
        case 'etiqueta':
            $config['largura_modulo'] = 2.6;
            $config['altura'] = 78;
            break;
        default:
            $config['largura_modulo'] = 2.4;
            $config['altura'] = 76;
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

// Função para converter valor para inteiro se possível
function paraInteiro($valor) {
    if (is_numeric($valor)) {
        return (int)$valor;
    }
    return $valor;
}

// Função para formatar número com separador de milhar
function formatarNumero($numero) {
    if (is_numeric($numero)) {
        return number_format((int)$numero, 0, '', '.');
    }
    return $numero;
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <title>Etiquetas de Recebimento - Sistema de Impressão</title>
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
            font-family: Arial, sans-serif;
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
            border: 1px solid #000;
            box-sizing: border-box;
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

        .label-titulo {
            font-size: 11px;
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
            margin-top: 6px;
            letter-spacing: 1px;
        }

        .info-grupo {
            font-size: 18px;
            font-weight: bold;
            color: #000;
            margin-top: 3px;
            text-transform: uppercase;
            margin-top: 5px;
            letter-spacing: 0.5px;
        }

        .info-direita {
            text-align: right;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            justify-content: center;
            flex-shrink: 0;
            width: auto;
        }

        .numero-etiqueta {
            font-size: 28px;
            font-weight: bold;
            color: #000;
            line-height: 1.2;
            white-space: nowrap;
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

        /* LADO ESQUERDO - DADOS PRINCIPAIS */
        .info-principal {
            background: #fff;
            padding: 5px;
            display: flex;
            flex-direction: column;
            min-height: 220px;
            gap: 8px;
        }

        .info-fornecedor {
            background: #fff;
            padding: 8px;
            border: 1px solid #000;
            border-radius: 3px;
            min-height: 100px;
        }

        .info-produto {
            background: #fff;
            padding: 8px;
            border: 1px solid #000;
            border-radius: 3px;
            flex: 1;
            min-height: 60px;
        }

        .dados-carga-esquerda {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            margin-top: 5px;
        }

        .card-carga-esquerda {
            background: #fff;
            border: 1px solid #000;
            border-radius: 4px;
            padding: 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
            min-height: 70px;
            justify-content: space-between;
        }

        .card-carga-esquerda.localizacao {
            justify-content: flex-start;
        }

        .card-title-esquerda {
            font-size: 12px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 3px;
            line-height: 1;
        }

        .card-subtitle-esquerda {
            font-size: 12px;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .card-value-esquerda {
            font-family: 'Courier New', monospace;
            font-size: 28px;
            font-weight: bold;
            color: #000;
            letter-spacing: 0.5px;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            
        }

        .localizacao-title {
            font-size: 12px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 8px;
            line-height: 1;
        }

        .localizacao-value {
            font-family: 'Courier New', monospace;
            font-size: 32px;
            font-weight: bold;
            color: #000;
            letter-spacing: 1px;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            text-align: center;
        }

        .localizacao-value.dado-nulo {
            font-size: 14px;
            color: #e74c3c;
            font-style: italic;
        }

        /* LADO DIREITO - CÓDIGOS DE BARRAS (MODIFICADO) */
        .dados-carga-direita {
            width: 220px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .card-carga-direita {
            background: #fff;
            border-radius: 4px;
            padding: 5px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 140px;
        }

        .card-title-direita {
            font-size: 14px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            margin-bottom: 3px;
            line-height: 1;
            text-align: center;
            height: 18px;
        }

        .numero-codigo-barra {
            font-size: 32px;
            font-weight: bold;
            color: #000;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 5px;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }

        .barcode-container {
            flex: 1;
            padding: 4px;
            background: white;
            border-radius: 3px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 50px;
            position: relative;
            height: 55px;
        }

        .barcode {
            width: 100%;
            height: 55px;
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

        .info-label {
            font-size: 11px;
            font-weight: bold;
            color: #000;
            text-transform: uppercase;
            display: block;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 15px;
            font-weight: 600;
            color: #000;
            line-height: 1.3;
            display: flex;
            align-items: center;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .info-value-produto {
            font-size: 16px;
            font-weight: 600;
            color: #000;
            line-height: 1.3;
        }

        .dado-nulo {
            color: #e74c3c !important;
            font-style: italic;
            font-weight: 600;
        }

        /* RODAPÉ COM AS NOVAS PROPORÇÕES - CORRIGIDO */
        .rodape {
            padding: 0;
            background: #fff;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            width: 100%;
            border-top: 2px solid #000;
            box-sizing: border-box;
            height: 60px;
        }

        .info-rodape {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            border-top: 1px solid #000;
            width: 100%;
            box-sizing: border-box;
            height: 100%;
        }

        .campo-rodape {
            display: flex;
            flex-direction: column;
            height: 100%;
            border: none;
            margin: 0;
            padding: 0;
        }

        .campo-rodape.ordem {
            width: calc(567px * 0.15); /* 15% de 567px */
            min-width: calc(567px * 0.15);
            max-width: calc(567px * 0.15);
        }

        .campo-rodape.data {
            width: calc(567px * 0.20); /* 20% de 567px */
            min-width: calc(567px * 0.20);
            max-width: calc(567px * 0.20);
        }

        .campo-rodape.certificado {
            width: calc(567px * 0.55); /* 65% de 567px */
            min-width: calc(567px * 0.55);
            max-width: calc(567px * 0.55);
        }

        .campo-label {
            font-size: 10px;
            font-weight: bold;
            color: #000;
            display: block;
            text-transform: uppercase;
            margin-bottom: 2px;
            white-space: nowrap;
            text-align: center;
            width: 100%;
        }

        .campo-valor {
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: bold;
            color: #000;
            border: 2px solid #000;
            height: 28px;
            border-radius: 4px;
            background: white;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex: 1;
        }

        .campo-rodape.certificado .campo-valor {
            font-size: 14px;
            color: #666;
        }

        .controles-impressao {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }

        .btn-impressao {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .btn-impressao:hover {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
        }

        .btn-voltar {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            text-decoration: none;
        }

        .btn-voltar:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0,0,0,0.3);
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
                border: 1px solid #000 !important;
            }

            .cabecalho {
                border-bottom: 2px solid #000 !important;
            }

            .info-fornecedor,
            .info-produto,
            .card-carga-esquerda
            {
                border: 1px solid #000 !important;
            }

            .campo-valor {
                border: 2px solid #000 !important;
            }

            .rodape {
                border-top: 2px solid #000 !important;
            }

            .info-rodape {
                border-top: 1px solid #000 !important;
                display: flex !important;
                justify-content: space-between !important;
            }
            
            .campo-rodape.ordem {
                width: 85px !important; /* 15% de 567px = 85.05px */
                min-width: 85px !important;
                max-width: 85px !important;
            }
            
            .campo-rodape.data {
                width: 113px !important; /* 20% de 567px = 113.4px */
                min-width: 113px !important;
                max-width: 113px !important;
            }
            
            .campo-rodape.certificado {
                width: 312px !important; /* 65% de 567px = 368.55px */
                min-width: 312px !important;
                max-width: 312px !important;
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
                height: 55px !important;
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
            
            .controles-impressao {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <div class="controles-impressao">
        <a href="etiquetas.php" class="btn-voltar">
            <span class="icon">←</span>
            Voltar
        </a>
        <button onclick="window.print()" class="btn-impressao">
            <span class="icon">🖨️</span>
            Imprimir Etiquetas
        </button>
    </div>

    <?php
    $contador = 1;
    foreach ($listaNotas as $notaItem):
    ?>
        <?php
        // Converter valores para inteiros
        $numero_nota = isset($notaItem['nota']) ? exibirTexto(paraInteiro($notaItem['nota'])) : '';
        $cod_forn = isset($notaItem['cod_forn']) ? exibirTexto(paraInteiro($notaItem['cod_forn'])) : '';
        $fornecedor = isset($notaItem['fornecedor']) ? exibirTexto($notaItem['fornecedor']) : '';
        $cod_prod = isset($notaItem['cod_prod']) ? exibirTexto(paraInteiro($notaItem['cod_prod'])) : '';
        $produto = isset($notaItem['produto']) ? exibirTexto($notaItem['produto']) : '';
        $grupo_produto = isset($notaItem['grupo_produto']) ? exibirTexto($notaItem['grupo_produto']) : '';
        $qtde_carregado = isset($notaItem['qtde_carregado']) ? $notaItem['qtde_carregado'] : '0';
        $qtde_pc = isset($notaItem['qtde_carregado_pc']) ? $notaItem['qtde_carregado_pc'] : '0';
        $localizacao = isset($notaItem['localizacao']) ? exibirTexto($notaItem['localizacao']) : '';
        $seqetiqueta = isset($notaItem['seqetiqueta']) ? exibirTexto(paraInteiro($notaItem['seqetiqueta'])) : '';
        $data_hora = isset($notaItem['data']) ? exibirTexto($notaItem['data']) : date('d/m/Y H:i:s');
        
        // Extrair apenas a data (remover hora se existir)
        $data_array = explode(' ', $data_hora);
        $data = $data_array[0] ?? date('d/m/Y');
        
        // Gerar códigos de barras:
        // 1. Número da etiqueta (seqetiqueta) 
        // 2. Número da nota fiscal
        $codigo_etiqueta = getBarcodeValue($seqetiqueta, 'etiqueta');
        $codigo_nota = getBarcodeValue($numero_nota, 'nota');
        
        // Formatar quantidades com separador de milhar
        $qtde_carregado_format = formatarQuantidade($qtde_carregado);
        $qtde_pc_format = formatarQuantidade($qtde_pc);
        
        // Verificar se há dados nulos
        $localizacaoNula = empty(trim($localizacao));
        $etiquetaNula = empty($seqetiqueta);
        
        // Gerar códigos de barras em base64
        $barcode_etiqueta = gerarBarcodeBase64($codigo_etiqueta, 'etiqueta');
        $barcode_nota = gerarBarcodeBase64($codigo_nota, 'nota');
        
        // Definir número de etiqueta (usar seqetiqueta ou contador)
        $num_etiqueta = !$etiquetaNula ? (int)$seqetiqueta : $contador;
        
        // Garantir que os valores exibidos sejam inteiros formatados
        $cod_forn_exib = is_numeric($cod_forn) ? formatarNumero((int)$cod_forn) : $cod_forn;
        $cod_prod_exib = is_numeric($cod_prod) ? formatarNumero((int)$cod_prod) : $cod_prod;
        $num_etiqueta_exib = is_numeric($num_etiqueta) ? formatarNumero((int)$num_etiqueta) : $num_etiqueta;
        $numero_nota_exib = is_numeric($numero_nota) ? formatarNumero((int)$numero_nota) : $numero_nota;
        ?>
        
        <div class="etiqueta-container" data-etiqueta="<?php echo $contador; ?>" data-nota="<?php echo $numero_nota_exib; ?>">
            <div class="cabecalho">
                <div class="logo-container">
                    <img src="imgs/logo.png" class="logo" alt="Logo Noroaco" onerror="this.style.display='none'">
                </div>

                <div class="titulo-central">
                    <div class="label-titulo">
                        IDENTIFICAÇÃO DO PRODUTO
                    </div>
                    <?php if (!empty($grupo_produto)): ?>
                        <div class="info-grupo">
                            <?php echo $grupo_produto; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="info-direita">
                    <div class="numero-etiqueta">ETIQ: <?php echo $num_etiqueta_exib; ?></div>
                </div>
            </div>

            <div class="corpo-etiqueta">
                <!-- LADO ESQUERDO: DADOS PRINCIPAIS E CARGAS -->
                <div class="info-principal">
                    <div class="info-fornecedor">
                        <span class="info-label">FORNECEDOR:</span>
                        <div class="info-value">
                            <?php echo $cod_forn_exib; ?> - <?php echo $fornecedor; ?>
                        </div>
                    </div>

                    <div class="info-produto">
                        <span class="info-label">PRODUTO:</span>
                        <div class="info-value-produto">
                            <?php echo $cod_prod_exib; ?> - <?php echo $produto; ?>
                        </div>
                    </div>

                    <div class="dados-carga-esquerda">
                        <div class="card-carga-esquerda" data-type="peso">
                            <div class="card-subtitle-esquerda">PESO</div>
                            <div class="card-value-esquerda"><?php echo $qtde_carregado_format; ?></div>
                        </div>

                        <div class="card-carga-esquerda" data-type="peca">
                            <div class="card-subtitle-esquerda">PEÇA</div>
                            <div class="card-value-esquerda"><?php echo $qtde_pc_format; ?></div>
                        </div>

                        <div class="card-carga-esquerda localizacao" data-type="localizacao">
                            <div class="localizacao-title">LOCALIZAÇÃO</div>
                            <div class="localizacao-value <?php echo $localizacaoNula ? 'dado-nulo' : ''; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- LADO DIREITO: CÓDIGOS DE BARRAS - MODIFICADO CONFORME SOLICITAÇÃO -->
                <div class="dados-carga-direita">
                    <div class="card-carga-direita" data-type="etiqueta">
                        <div class="card-title-direita">ETIQUETA</div>
                        <div class="numero-codigo-barra"><?php echo !$etiquetaNula ? $seqetiqueta : '221'; ?></div>
                        <div class="barcode-container">
                            <?php if (!empty($barcode_etiqueta)): ?>
                                <img src="<?php echo $barcode_etiqueta; ?>"
                                    class="barcode"
                                    alt="Código de Etiqueta: <?php echo $codigo_etiqueta; ?>"
                                    data-type="etiqueta"
                                    data-value="<?php echo htmlspecialchars($codigo_etiqueta, ENT_QUOTES, 'UTF-8'); ?>"
                                    onerror="handleBarcodeError(this)"
                                    onload="handleBarcodeLoad(this)"
                                    style="filter: contrast(1.1);">
                                <div class="sem-barcode" style="display: none;" data-type="etiqueta">SEM ETIQUETA</div>
                            <?php else: ?>
                                <div class="sem-barcode" data-type="etiqueta">SEM ETIQUETA</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-carga-direita" data-type="nota">
                        <div class="card-title-direita">NOTA FISCAL</div>
                        <div class="numero-codigo-barra"><?php echo !empty($numero_nota) ? $numero_nota : '3400'; ?></div>
                        <div class="barcode-container">
                            <?php if (!empty($barcode_nota)): ?>
                                <img src="<?php echo $barcode_nota; ?>"
                                    class="barcode"
                                    alt="Código de Nota: <?php echo $codigo_nota; ?>"
                                    data-type="nota"
                                    data-value="<?php echo htmlspecialchars($codigo_nota, ENT_QUOTES, 'UTF-8'); ?>"
                                    onerror="handleBarcodeError(this)"
                                    onload="handleBarcodeLoad(this)"
                                    style="filter: contrast(1.1);">
                                <div class="sem-barcode" style="display: none;" data-type="nota">SEM NOTA</div>
                            <?php else: ?>
                                <div class="sem-barcode" data-type="nota">SEM NOTA</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RODAPÉ COM AS NOVAS PROPORÇÕES CORRIGIDAS -->
            <div class="rodape">
                <div class="info-rodape">
                    <div class="campo-rodape certificado">
                        <span class="campo-label">CERTIFICADO</span>
                        <div class="campo-valor">&nbsp;</div>
                    </div>

                    <div class="campo-rodape data">
                        <span class="campo-label">DATA</span>
                        <div class="campo-valor"><?php echo $data; ?></div>
                    </div>

                    <div class="campo-rodape ordem">
                        <span class="campo-label">ORDEM</span>
                        <div class="campo-valor"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php $contador++; ?>
    <?php endforeach; ?>

    <script>
        function handleBarcodeLoad(imgElement) {
            var tipo = imgElement.getAttribute('data-type');
            var valor = imgElement.getAttribute('data-value');
            
            console.log('✓ Código de barras carregado:', {
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
        }

        function handleBarcodeError(imgElement) {
            var tipo = imgElement.getAttribute('data-type');
            
            console.error('✗ Erro ao carregar código de barras:', {
                tipo: tipo,
                src: imgElement.src
            });
            
            imgElement.style.display = 'none';
            
            var container = imgElement.closest('.barcode-container');
            var fallback = container.querySelector('.sem-barcode');
            
            if (fallback) {
                fallback.style.display = 'flex';
                
                var textos = {
                    'etiqueta': 'SEM ETIQUETA',
                    'nota': 'SEM NOTA'
                };
                
                if (textos[tipo]) {
                    fallback.textContent = textos[tipo];
                }
            }
        }

        window.addEventListener('beforeprint', function() {
            console.log('=== PREPARANDO PARA IMPRESSÃO ===');
            console.log('Total de etiquetas: ' + document.querySelectorAll('.etiqueta-container').length);
            console.log('Largura total: 567px');
            console.log('Rodapé - Proporções:');
            console.log('- ORDEM: 15% = ' + Math.round(567 * 0.15) + 'px');
            console.log('- DATA: 20% = ' + Math.round(567 * 0.20) + 'px');
            console.log('- CERTIFICADO: 65% = ' + Math.round(567 * 0.65) + 'px');
            
            document.querySelectorAll('img.barcode').forEach(function(img) {
                img.style.filter = 'contrast(1.2)';
            });
        });

        window.onload = function() {
            console.log('=== PÁGINA DE ETIQUETAS CARREGADA ===');
            console.log('Total de etiquetas: ' + document.querySelectorAll('.etiqueta-container').length);
            console.log('Largura total da etiqueta: 567px');
            console.log('Proporções do rodapé calculadas sobre 567px:');
            console.log('- ORDEM: 15% = ' + Math.round(567 * 0.15) + 'px');
            console.log('- DATA: 20% = ' + Math.round(567 * 0.20) + 'px');
            console.log('- CERTIFICADO: 65% = ' + Math.round(567 * 0.65) + 'px');
            
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
            
            setTimeout(function() {
                console.log('Pronto para impressão. Use o botão "Imprimir Etiquetas".');
            }, 2000);
        };

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            }
        });
    </script>
</body>
</html>