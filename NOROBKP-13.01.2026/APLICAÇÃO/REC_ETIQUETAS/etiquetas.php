<?php
session_start();

// Configuração da conexão Firebird
$dsn = 'firebird:dbname=192.168.1.209:c:/BD/ARQSIST.FDB;charset=UTF8';
$user = 'SYSDBA';
$pass = 'masterkey';

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// Função para buscar fornecedores de uma nota - CORRIGIDA
function buscarFornecedoresNota($pdo, $numeroNota)
{
    $sql = "SELECT DISTINCT 
                f.codic as cod_forn,
                f.nome as fornecedor
            FROM arqes04 c
            INNER JOIN arqcad f ON f.codic = c.codif AND f.tipoc = 'F'
            WHERE c.nota = ?
            ORDER BY f.nome";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$numeroNota]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        throw new Exception("Erro ao buscar fornecedores: " . $e->getMessage());
    }
}

// Função para buscar dados da nota usando o SQL fornecido - VERSÃO SIMPLIFICADA
function buscarDadosNota($pdo, $numeroNota, $codigoFornecedor)
{
    $sql = "SELECT
    CAST(F.CODIC AS INTEGER) AS COD_FORN,
    F.NOME AS FORNECEDOR,

    CAST(P.CODIGO AS INTEGER) AS COD_PROD,
    P.NOME AS PRODUTO,

    G.grupo AS GRUPO_PRODUTO,

    CAST((
        SELECT COALESCE(SUM(CAST(CP2.QTDE_CARREGADO AS NUMERIC(12,4))), 0)
        FROM CARGA_PROD_CARREGADOS CP2
        WHERE CP2.SEQCARREGA = CG.SEQCARREG
          AND CP2.USUARIO IS NOT NULL
    ) AS INTEGER) AS QTDE_CARREGADO,

    CAST((
        SELECT COALESCE(SUM(CAST(CP2.QTDE_CARREGADO_PC AS NUMERIC(12,4))), 0)
        FROM CARGA_PROD_CARREGADOS CP2
        WHERE CP2.SEQCARREGA = CG.SEQCARREG
          AND CP2.USUARIO IS NOT NULL
    ) AS INTEGER) AS QTDE_CARREGADO_PC,

    COALESCE(LC.RUA, '') || COALESCE(LC.VAO, '') AS LOCALIZACAO,

    CAST((
        SELECT V.SEQLANC
        FROM VD_ETIQUETA V
        WHERE V.COD_LINK = CD.SEQPRODCARREG
          AND V.ORIGEM IN ('NFC', 'NCR')
    ) AS INTEGER) AS SEQETIQUETA

FROM ARQES04 C
INNER JOIN ARQCAD F 
    ON F.CODIC = C.CODIF 
   AND F.TIPOC = 'F'

INNER JOIN ARQES05 CP 
    ON CP.SEQNOTA = C.SEQNOTA

INNER JOIN ARQES01 P 
    ON P.CODIGO = CP.PRODUTO

INNER JOIN ARQES02 G 
    ON G.codigo = P.grupo

INNER JOIN ARQ_LOCAL_ESTOQUE L 
    ON L.CODLOCAL = 2

LEFT JOIN ARQ_PROD_LOCALIZACAO LC 
    ON LC.SEQPRODUTO = P.SEQPRODUTO 
   AND LC.CODLOCAL = L.CODLOCAL

INNER JOIN CARGA_CARREGAMENTO CG 
    ON CG.NRO_DOCU = CP.SEQLANC

INNER JOIN carga_prod_carregados CD 
    ON CD.seqcarrega = CG.seqcarreg
        WHERE C.nota = ? AND f.codic = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$numeroNota, $codigoFornecedor]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Converter para inteiros no PHP (garantia extra)
        foreach ($dados as &$item) {
            $item['qtde_carregado'] = intval($item['qtde_carregado']);
            $item['qtde_carregado_pc'] = intval($item['qtde_carregado_pc']);
        }

        return $dados;
    } catch (PDOException $e) {
        throw new Exception("Erro na consulta: " . $e->getMessage());
    }
}

// Processamento do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Inicializa o array de notas se não existir
    if (!isset($_SESSION['notas'])) {
        $_SESSION['notas'] = [];
    }

    // Buscar fornecedores da nota (primeiro passo)
    if (isset($_POST['buscar_fornecedores']) && !empty(trim($_POST['nota']))) {
        $numeroNota = trim($_POST['nota']);

        try {
            $fornecedores = buscarFornecedoresNota($pdo, $numeroNota);

            if ($fornecedores) {
                $_SESSION['nota_temp'] = $numeroNota;
                $_SESSION['fornecedores_temp'] = $fornecedores;
                $_SESSION['mensagem'] = "Nota {$numeroNota} encontrada! Selecione o fornecedor.";
                $_SESSION['tipo_mensagem'] = 'success';
            } else {
                $_SESSION['mensagem'] = "Nota {$numeroNota} não encontrada ou sem fornecedores!";
                $_SESSION['tipo_mensagem'] = 'error';
                unset($_SESSION['nota_temp']);
                unset($_SESSION['fornecedores_temp']);
            }
        } catch (Exception $e) {
            $_SESSION['mensagem'] = $e->getMessage();
            $_SESSION['tipo_mensagem'] = 'error';
        }
    }

    // Adicionar nota com fornecedor selecionado (segundo passo)
    if (isset($_POST['adicionar_nota']) && isset($_POST['fornecedor'])) {
        $codigoFornecedor = $_POST['fornecedor'];

        if (isset($_SESSION['nota_temp'])) {
            $numeroNota = $_SESSION['nota_temp'];

            try {
                $dadosNota = buscarDadosNota($pdo, $numeroNota, $codigoFornecedor);

                if ($dadosNota) {
                    $itensAdicionados = 0;
                    $itensDuplicados = 0;

                    foreach ($dadosNota as $dados) {
                        // Cria um ID único para combinação nota+fornecedor+produto
                        $idUnico = md5($numeroNota . '_' . $codigoFornecedor . '_' . $dados['cod_prod']);

                        // Verifica se já existe na sessão
                        $itemExistente = false;
                        foreach ($_SESSION['notas'] as $itemExist) {
                            if ($itemExist['id_unico'] === $idUnico) {
                                $itemExistente = true;
                                break;
                            }
                        }

                        if (!$itemExistente) {
                            $novoItem = [
                                'id_unico' => $idUnico,
                                'nota' => $numeroNota,
                                'cod_forn' => $dados['cod_forn'],
                                'fornecedor' => $dados['fornecedor'],
                                'cod_prod' => $dados['cod_prod'],
                                'produto' => $dados['produto'],
                                'grupo_produto' => $dados['grupo_produto'], // Adicionado
                                'qtde_carregado' => $dados['qtde_carregado'],
                                'qtde_carregado_pc' => $dados['qtde_carregado_pc'],
                                'localizacao' => $dados['localizacao'],
                                'seqetiqueta' => $dados['seqetiqueta'],
                                'id' => uniqid('nota_', true),
                                'data' => date('d/m/Y H:i:s')
                            ];

                            $_SESSION['notas'][] = $novoItem;
                            $itensAdicionados++;
                        } else {
                            $itensDuplicados++;
                        }
                    }

                    if ($itensAdicionados > 0) {
                        $fornecedorNome = $dadosNota[0]['fornecedor'];
                        $_SESSION['mensagem'] = "Nota {$numeroNota} - {$fornecedorNome}: {$itensAdicionados} item(s) adicionado(s)" .
                            ($itensDuplicados > 0 ? ", {$itensDuplicados} duplicado(s) ignorado(s)" : "");
                        $_SESSION['tipo_mensagem'] = 'success';
                    } else {
                        $_SESSION['mensagem'] = "Todos os itens desta nota já estão na lista!";
                        $_SESSION['tipo_mensagem'] = 'warning';
                    }

                    // Limpa dados temporários
                    unset($_SESSION['nota_temp']);
                    unset($_SESSION['fornecedores_temp']);
                } else {
                    $_SESSION['mensagem'] = "Nenhum dado encontrado para esta nota e fornecedor!";
                    $_SESSION['tipo_mensagem'] = 'error';
                }
            } catch (Exception $e) {
                $_SESSION['mensagem'] = $e->getMessage();
                $_SESSION['tipo_mensagem'] = 'error';
            }
        }
    }

    // Remove item específico
    if (isset($_POST['remover']) && isset($_POST['item_id'])) {
        $itemId = $_POST['item_id'];
        foreach ($_SESSION['notas'] as $index => $item) {
            if ($item['id'] === $itemId) {
                $nota = $item['nota'];
                $produto = $item['cod_prod'];
                unset($_SESSION['notas'][$index]);
                $_SESSION['notas'] = array_values($_SESSION['notas']);
                $_SESSION['mensagem'] = "Nota {$nota} - Produto {$produto} removido!";
                $_SESSION['tipo_mensagem'] = 'info';
                break;
            }
        }
    }

    // Limpa todas as notas
    if (isset($_POST['limpar_tudo'])) {
        $totalRemovidas = count($_SESSION['notas']);
        $_SESSION['notas'] = [];
        $_SESSION['mensagem'] = "Todas as {$totalRemovidas} notas foram removidas!";
        $_SESSION['tipo_mensagem'] = 'info';
    }

    // Redireciona para evitar reenvio do formulário
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Contador de itens
$totalItens = isset($_SESSION['notas']) ? count($_SESSION['notas']) : 0;
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>NOROAÇO - Sistema de Etiquetas por Nota</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 10px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-size: 14px;
            min-height: 100vh;
            overflow: hidden;
        }

        .container-principal {
            display: flex;
            flex-direction: column;
            min-height: calc(100vh - 20px);
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header-principal {
            background: #333;
            height: 70px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            position: relative;
            border-bottom: 3px solid #fdb525;
        }

        .logo-noroaco {
            height: 45px;
            width: auto;
        }

        /* MENSAGENS */
        .mensagem-flutuante {
            position: absolute;
            top: 10px;
            right: 50%;
            transform: translateX(50%);
            padding: 12px 20px;
            border-radius: 6px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            animation: slideIn 0.3s ease;
        }

        .mensagem-success {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
        }

        .mensagem-error {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
        }

        .mensagem-warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
        }

        .mensagem-info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(50%) translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(50%) translateY(0);
            }
        }

        .botoes-direita {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-novo-registro {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            box-shadow: 0 4px 6px rgba(253, 181, 37, 0.2);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-novo-registro:hover {
            background: linear-gradient(135deg, #ffc64d 0%, #fdb525 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(253, 181, 37, 0.3);
        }

        /* FORMULÁRIO CENTRALIZADO - LADO A LADO */
        .formulario-centralizado {
            display: flex;
            justify-content: center;
            margin: 20px 0;
            padding: 0 15px;
        }

        .formulario-lado-a-lado {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            width: 100%;
            max-width: 900px;
        }

        /* ESTILO PARA OS CAMPOS */
        .campo-nota {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s;
            height: 46px;
            width: 180px;
            flex-shrink: 0;
        }

        .campo-nota:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        .campo-fornecedor {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s;
            height: 46px;
            width: 250px;
            flex-shrink: 0;
        }

        .campo-fornecedor:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        /* BOTÕES DO FORMULÁRIO - COMPACTOS */
        .btn-buscar {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            height: 46px;
            flex-shrink: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-buscar:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.3);
        }

        .btn-adicionar {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            height: 46px;
            flex-shrink: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-adicionar:hover {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(46, 204, 113, 0.3);
        }

        /* BOTÃO LIMPAR TUDO */
        .btn-limpar-tudo {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            height: 46px;
            flex-shrink: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-limpar-tudo:hover {
            background: linear-gradient(135deg, #c0392b 0%, #e74c3c 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(231, 76, 60, 0.3);
        }

        /* BOTÃO IMPRIMIR */
        .btn-imprimir {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            white-space: nowrap;
            box-shadow: 0 4px 6px rgba(253, 181, 37, 0.2);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-imprimir:hover {
            background: linear-gradient(135deg, #ffc64d 0%, #fdb525 100%);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(253, 181, 37, 0.3);
        }

        /* CONTROLES SUPERIORES */
        .controles-superiores {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .contador-ops {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* CONTÊINER DA TABELA */
        .container-tabela {
            flex: 1;
            padding: 15px;
            overflow: auto;
            background: #f8f9fa;
        }

        .titulo-tabela {
            color: #2c3e50;
            padding: 12px 15px;
            margin-bottom: 15px;
            background: white;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            border-left: 4px solid #fdb525;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* TABLE STYLES - ALINHADO COM O SISTEMA DE BALANÇA */
        .tabela-container {
            width: 100%;
            overflow-x: auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1300px;
        }

        th {
            background: #333;
            color: white;
            padding: 10px 8px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            border-right: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 10;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            white-space: nowrap;
        }

        th:last-child {
            border-right: none;
        }

        td {
            padding: 8px;
            border-bottom: 1px solid #e9ecef;
            color: #495057;
            border-right: 1px solid #e9ecef;
            vertical-align: middle;
            height: 40px;
            white-space: nowrap;
            min-width: 50px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
        }

        td:last-child {
            border-right: none;
        }

        tr:hover {
            background: #f8f9fa;
        }

        tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        tr:nth-child(even):hover {
            background-color: #e9ecef;
        }

        /* COLUNAS ESPECÍFICAS */
        .col-id,
        .col-data,
        .col-acoes {
            text-align: center !important;
        }

        .col-id {
            width: 60px;
            min-width: 60px;
            max-width: 60px;
        }

        .col-nota {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
            font-weight: 600 !important;
        }

        .col-fornecedor {
            width: 150px;
            min-width: 150px;
            max-width: 150px;
        }

        .col-cod-produto {
            width: 80px;
            min-width: 80px;
            max-width: 80px;
        }

        .col-produto {
            width: 200px;
            min-width: 200px;
            max-width: 200px;
        }

        .col-grupo {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
        }

        .col-quantidade {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
            text-align: right;
        }

        .col-localizacao {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
        }

        .col-etiqueta {
            width: 100px;
            min-width: 100px;
            max-width: 100px;
        }

        .col-data {
            width: 120px;
            min-width: 120px;
            max-width: 120px;
        }

        .col-acoes {
            width: 120px;
            min-width: 120px;
            max-width: 120px;
        }

        /* ESTILOS ESPECIAIS PARA DADOS NULOS */
        .dado-nulo {
            color: #e74c3c !important;
            font-style: italic;
            font-weight: 600;
        }

        .dado-aviso {
            color: #f39c12 !important;
            font-style: italic;
        }

        /* BOTÕES DE AÇÃO */
        .acoes-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-acao {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            gap: 5px;
        }

        .btn-remover {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
        }

        .btn-remover:hover {
            background: linear-gradient(135deg, #c0392b 0%, #e74c3c 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(231, 76, 60, 0.3);
        }

        /* LISTA VAZIA */
        .lista-vazia {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        .lista-vazia-icon {
            font-size: 60px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .lista-vazia h3 {
            font-size: 18px;
            margin-bottom: 10px;
            color: #2c3e50;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .lista-vazia p {
            font-size: 14px;
            max-width: 400px;
            margin: 0 auto;
            line-height: 1.5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* RODAPÉ */
        .rodape {
            padding: 15px 20px;
            background: #2c3e50;
            color: white;
            font-size: 13px;
            border-top: 3px solid #fdb525;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .contador-registros {
            background: rgba(255, 255, 255, 0.1);
            padding: 6px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* RESPONSIVIDADE */
        @media (max-width: 768px) {
            .header-principal {
                flex-direction: column;
                height: auto;
                padding: 15px;
                gap: 15px;
            }

            .formulario-lado-a-lado {
                flex-direction: column;
            }

            .campo-nota,
            .campo-fornecedor,
            .btn-adicionar,
            .btn-buscar,
            .btn-imprimir,
            .btn-novo-registro,
            .btn-limpar-tudo {
                width: 100%;
                min-width: auto;
            }

            .btn-adicionar,
            .btn-buscar,
            .btn-imprimir,
            .btn-novo-registro,
            .btn-limpar-tudo {
                justify-content: center;
            }

            .controles-superiores {
                flex-direction: column;
                align-items: stretch;
            }

            .botoes-direita {
                flex-direction: column;
                width: 100%;
            }

            .tabela-container {
                font-size: 12px;
            }

            th,
            td {
                padding: 6px 4px;
                font-size: 11px;
            }

            .btn-acao {
                padding: 4px 8px;
                font-size: 11px;
            }
        }

        @media (max-width: 480px) {
            body {
                margin: 5px;
            }

            .container-principal {
                border-radius: 6px;
            }

            .header-principal {
                padding: 10px;
            }

            .logo-noroaco {
                height: 35px;
            }

            .titulo-tabela {
                font-size: 14px;
                padding: 10px;
            }

            .tabela-container {
                font-size: 11px;
            }

            th,
            td {
                padding: 4px 2px;
                font-size: 10px;
            }

            .btn-acao {
                width: 22px;
                height: 22px;
                padding: 0;
                font-size: 10px;
            }
        }

        /* ESTILOS ADICIONAIS DO SISTEMA DE BALANÇA */
        .search-container {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            padding: 6px 15px;
            border-radius: 6px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            flex: 1;
            max-width: 1000px;
        }

        .texto-ellipsis {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
            display: block;
        }

        .celula-vazia {
            color: #95a5a6;
            font-style: italic;
        }

        .status-finalizado {
            color: #28a745;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            background: rgba(40, 167, 69, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid rgba(40, 167, 69, 0.2);
            white-space: nowrap;
            font-size: 11px;
        }

        .status-aberto {
            color: #ffc107;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            background: rgba(255, 193, 7, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid rgba(255, 193, 7, 0.2);
            white-space: nowrap;
        }

        .campo-numerico {
            font-family: 'Courier New', monospace;
            font-weight: 500;
            text-align: right;
        }

        .campo-inteiro {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: center;
        }

        .registro-encontrado {
            background-color: #fff9e6 !important;
            border-left: 4px solid #fdb525;
        }

        .destaque-busca {
            background-color: #fff3cd;
            color: #856404;
            padding: 1px 4px;
            border-radius: 3px;
            font-weight: 600;
        }

        .mensagem-sem-registros {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
            font-style: italic;
        }

        .mensagem-sem-registros i {
            font-size: 32px;
            margin-bottom: 15px;
            display: block;
            color: #bdc3c7;
        }

        /* INFO FORNECEDORES */
        .info-fornecedores {
            background: linear-gradient(135deg, #fff9e6 0%, #fff3cd 100%);
            padding: 8px 12px;
            border-radius: 6px;
            border-left: 4px solid #fdb525;
            margin: 8px 0;
            font-size: 13px;
            text-align: center;
            width: 100%;
            max-width: 900px;
            margin: 8px auto 15px auto;
        }

        .info-fornecedores strong {
            color: #333;
        }

        .quantidade-info {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #2c3e50;
            text-align: right;
        }

        /* ANIMAÇÃO PARA APARECIMENTO DOS CAMPOS ADICIONAIS */
        .campos-adicionais {
            display: flex;
            gap: 8px;
            align-items: center;
            opacity: 0;
            max-width: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .campos-adicionais.ativo {
            opacity: 1;
            max-width: 500px;
        }

        /* SEPARADOR VISUAL */
        .separador {
            width: 1px;
            height: 30px;
            background: #dee2e6;
            margin: 0 5px;
        }

        .separador.ativo {
            opacity: 1;
        }

        .separador:not(.ativo) {
            display: none;
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <!-- CABEÇALHO -->
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">

            <!-- MENSAGENS -->
            <?php if (isset($_SESSION['mensagem'])): ?>
                <div class="mensagem-flutuante mensagem-<?php echo $_SESSION['tipo_mensagem'] ?? 'info'; ?>">
                    <?php
                    echo $_SESSION['mensagem'];
                    unset($_SESSION['mensagem']);
                    unset($_SESSION['tipo_mensagem']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="botoes-direita">
                <?php if ($totalItens > 0): ?>
                    <a href="etiquetas_imp.php" class="btn-imprimir" target="_blank">
                        <i class="fa fa-print" aria-hidden="true"></i>
                        Imprimir
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- CONTÊINER DA TABELA -->
        <div class="container-tabela">
            <div class="titulo-tabela">
                Sistema de Etiquetas - Notas Fiscais
            </div>

            <!-- FORMULÁRIO DE ADIÇÃO - LADO A LADO -->
            <div class="formulario-centralizado">
                <form method="POST" class="formulario-lado-a-lado">
                    <!-- PRIMEIRO: CAMPO DE DIGITAR NOTA -->
                    <input type="text"
                        name="nota"
                        class="campo-nota"
                        placeholder="Digite o número da nota..."
                        required
                        autocomplete="off"
                        autofocus
                        value="<?php echo isset($_SESSION['nota_temp']) ? htmlspecialchars($_SESSION['nota_temp']) : ''; ?>">
                    
                    <!-- SEGUNDO: BOTÃO BUSCAR FORNECEDORES -->
                    <button type="submit" name="buscar_fornecedores" class="btn-buscar">
                        <i class="fas fa-search"></i>
                        Buscar Fornecedores
                    </button>
                    
                    <!-- SEPARADOR VISUAL -->
                    <?php if (isset($_SESSION['nota_temp']) && isset($_SESSION['fornecedores_temp'])): ?>
                        <div class="separador ativo"></div>
                    <?php endif; ?>
                    
                    <!-- TERCEIRO: CAMPO SELECIONAR FORNECEDOR (APARECE APÓS BUSCA) -->
                    <?php if (isset($_SESSION['nota_temp']) && isset($_SESSION['fornecedores_temp'])): ?>
                        <select name="fornecedor" class="campo-fornecedor" required>
                            <option value="">-- Selecione um fornecedor --</option>
                            <?php foreach ($_SESSION['fornecedores_temp'] as $fornecedor): ?>
                                <option value="<?php echo htmlspecialchars($fornecedor['cod_forn']); ?>">
                                    <?php echo htmlspecialchars($fornecedor['fornecedor']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    
                    <!-- QUARTO: BOTÃO ADICIONAR NOTA E LIMPAR TUDO (APARECE APÓS BUSCA) -->
                    <?php if (isset($_SESSION['nota_temp']) && isset($_SESSION['fornecedores_temp'])): ?>
                        <button type="submit" name="adicionar_nota" class="btn-adicionar">
                            <i class="fas fa-plus-circle"></i>
                            Adicionar Nota
                        </button>
                        
                        <!-- BOTÃO LIMPAR TUDO -->
                        <button type="submit" name="limpar_tudo" class="btn-limpar-tudo"
                                onclick="return confirm('Tem certeza que deseja limpar TODOS os produtos da tabela?\n\nIsso removerá <?php echo $totalItens; ?> item(ns).')">
                            <i class="fas fa-trash-alt"></i>
                            Limpar Tudo
                        </button>
                    <?php endif; ?>
                </form>
            </div> 

            <!-- TABELA -->
            <div class="tabela-container">
                <table>
                    <thead>
                        <tr>
                            <th class="col-id">#</th>
                            <th class="col-nota">Nota</th>
                            <th class="col-fornecedor">Fornecedor</th>
                            <th class="col-cod-produto">Código</th>
                            <th class="col-produto">Produto</th>
                            <th class="col-grupo">Grupo</th>
                            <th class="col-quantidade">Qtd Carregado</th>
                            <th class="col-quantidade">Qtd PC</th>
                            <th class="col-localizacao">Localização</th>
                            <th class="col-etiqueta">Seq. Etiqueta</th>
                            <th class="col-data">Data</th>
                            <th class="col-acoes">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totalItens === 0): ?>
                            <tr>
                                <td colspan="12" style="border: none;">
                                    <div class="lista-vazia">
                                        <div class="lista-vazia-icon">📋</div>
                                        <h3>Nenhuma nota cadastrada</h3>
                                        <p>Digite o número da nota e clique em "Buscar Fornecedores".</p>
                                        <p style="color: #f39c12; margin-top: 10px;">
                                            <strong>Nota:</strong> Após buscar, selecione o fornecedor e clique em "Adicionar Nota".
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($_SESSION['notas'] as $index => $item): ?>
                                <?php
                                // Define classes CSS para dados vazios
                                $localizacaoClass = empty(trim($item['localizacao'])) ? 'dado-nulo' : '';
                                $etiquetaClass = empty($item['seqetiqueta']) ? 'dado-nulo' : '';
                                ?>
                                <tr>
                                    <td class="col-id"><?php echo str_pad($index + 1, 3, '0', STR_PAD_LEFT); ?></td>
                                    <td class="col-nota"><strong><?php echo htmlspecialchars($item['nota']); ?></strong></td>
                                    <td class="col-fornecedor">
                                        <span class="texto-ellipsis" title="<?php echo htmlspecialchars($item['fornecedor']); ?>">
                                            <?php echo htmlspecialchars(substr($item['fornecedor'], 0, 20)); ?>
                                        </span>
                                    </td>
                                    <td class="col-cod-produto"><?php echo htmlspecialchars($item['cod_prod']); ?></td>
                                    <td class="col-produto">
                                        <span class="texto-ellipsis" title="<?php echo htmlspecialchars($item['produto']); ?>">
                                            <?php echo htmlspecialchars(substr($item['produto'], 0, 30)) . (strlen($item['produto']) > 30 ? '...' : ''); ?>
                                        </span>
                                    </td>
                                    <td class="col-grupo"><?php echo htmlspecialchars($item['grupo_produto'] ?? ''); ?></td>
                                    <td class="col-quantidade">
                                        <span class="quantidade-info">
                                            <?php echo number_format($item['qtde_carregado'], 3, ',', '.'); ?>
                                        </span>
                                    </td>
                                    <td class="col-quantidade">
                                        <span class="quantidade-info">
                                            <?php echo number_format($item['qtde_carregado_pc'], 3, ',', '.'); ?>
                                        </span>
                                    </td>
                                    <td class="col-localizacao <?php echo $localizacaoClass; ?>">
                                        <?php echo !empty(trim($item['localizacao'])) ? htmlspecialchars($item['localizacao']) : '<span class="dado-nulo">-</span>'; ?>
                                    </td>
                                    <td class="col-etiqueta <?php echo $etiquetaClass; ?>">
                                        <?php echo !empty($item['seqetiqueta']) ? htmlspecialchars($item['seqetiqueta']) : '<span class="dado-nulo">-</span>'; ?>
                                    </td>
                                    <td class="col-data"><?php echo $item['data']; ?></td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" name="remover" class="btn-acao btn-remover"
                                                    onclick="return confirm('Remover este item?\n\nNota: <?php echo htmlspecialchars($item['nota']); ?>\nFornecedor: <?php echo htmlspecialchars($item['fornecedor']); ?>\nProduto: <?php echo htmlspecialchars($item['cod_prod']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const campoNota = document.querySelector('[name="nota"]');
            const campoFornecedor = document.querySelector('[name="fornecedor"]');
            const camposAdicionais = document.querySelectorAll('.campos-adicionais');
            const separador = document.querySelector('.separador');
            
            // Focar no campo correto
            if (campoFornecedor) {
                campoFornecedor.focus();
            } else if (campoNota) {
                campoNota.focus();
            }

            // Animar aparecimento dos campos adicionais
            if (separador && separador.classList.contains('ativo')) {
                setTimeout(() => {
                    separador.style.opacity = '1';
                    camposAdicionais.forEach(campo => {
                        if (campo.classList.contains('ativo')) {
                            campo.style.opacity = '1';
                            campo.style.maxWidth = '500px';
                        }
                    });
                }, 100);
            }

            // Auto-fechar mensagens
            setTimeout(function() {
                const mensagens = document.querySelectorAll('.mensagem-flutuante');
                mensagens.forEach(msg => {
                    msg.style.opacity = '0';
                    setTimeout(() => msg.remove(), 500);
                });
            }, 3000);

            // Atualizar placeholder dinamicamente
            let placeholders = [
                "Ex: 123456",
                "Ex: 789012",
                "Ex: 345678",
                "Ex: 901234"
            ];
            let placeholderIndex = 0;
            let charIndex = 0;
            let isDeleting = false;
            let typingSpeed = 50;

            function typePlaceholder() {
                const input = document.querySelector('[name="nota"]');
                if (!input || document.activeElement === input || document.activeElement === campoFornecedor) return;

                const currentPlaceholder = placeholders[placeholderIndex];

                if (isDeleting) {
                    input.placeholder = currentPlaceholder.substring(0, charIndex - 1);
                    charIndex--;
                    typingSpeed = 30;
                } else {
                    input.placeholder = currentPlaceholder.substring(0, charIndex + 1);
                    charIndex++;
                    typingSpeed = 50;
                }

                if (!isDeleting && charIndex === currentPlaceholder.length) {
                    isDeleting = true;
                    typingSpeed = 1500;
                } else if (isDeleting && charIndex === 0) {
                    isDeleting = false;
                    placeholderIndex = (placeholderIndex + 1) % placeholders.length;
                    typingSpeed = 500;
                }

                setTimeout(typePlaceholder, typingSpeed);
            }

            setTimeout(typePlaceholder, 2000);

            // Limpar campo após adicionar nota
            document.querySelector('form').addEventListener('submit', function(e) {
                if (e.submitter && e.submitter.name === 'adicionar_nota') {
                    setTimeout(function() {
                        if (campoNota) {
                            campoNota.value = '';
                            campoNota.focus();
                        }
                    }, 100);
                }
            });
        });
    </script>
</body>

</html>