<?php
// Conexão com o banco de dados PostgreSQL
$dsn = "pgsql:host=192.168.1.209;port=5432;dbname=NetworkAdmin";
$user = "postgres";
$pass = "postgres";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
} catch (PDOException $e) {
    die("Erro na conexão: " . $e->getMessage());
}

// Buscar dados dos computadores
function buscarComputadores($pdo)
{
    $sql = "SELECT 
                id,
                ip,
                patrimonio,
                usuario,
                setor,
                dispositivo,
                so,
                empresa,
                situacao,
                descricao
            FROM computadores 
            ORDER BY usuario asc";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        throw new Exception("Erro ao buscar computadores: " . $e->getMessage());
    }
}

// Processar busca
$computadores = [];
$mensagem = '';
$tipoMensagem = 'info';

try {
    $computadores = buscarComputadores($pdo);
    if (empty($computadores)) {
        $mensagem = "Nenhum computador encontrado na base de dados!";
        $tipoMensagem = 'warning';
    }
} catch (Exception $e) {
    $mensagem = $e->getMessage();
    $tipoMensagem = 'error';
}

// Contador de registros
$totalRegistros = count($computadores);
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <!-- META TAGS PARA EVITAR CACHE -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Intranet - Gerenciamento de Computadores</title>

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

        .logo {
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

        .btn-atualizar {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
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
            box-shadow: 0 4px 6px rgba(52, 152, 219, 0.2);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .btn-atualizar:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.3);
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

        .contador-registros {
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

        /* TABLE STYLES */
        .tabela-container {
            width: 100%;
            height: 520px;
            /* Altura fixa para a tabela */
            overflow: auto;
            /* Habilita scroll vertical e horizontal */
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
            -webkit-overflow-scrolling: touch;
        }

        /* Para a tabela dentro do container */
        .tabela-container table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 0;
            /* Remove margens */
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            /* Fixa o layout para respeitar as larguras */
        }

        th {
            background: #333;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
            border-right: 1px solid #e9ecef;
            position: sticky;
            top: 0;
            z-index: 10;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        th:last-child {
            border-right: none;
        }

        td {
            padding: 10px 8px;
            border-bottom: 1px solid #e9ecef;
            color: #495057;
            border-right: 1px solid #e9ecef;
            vertical-align: middle;
            height: 45px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-family: 'Courier New', monospace;
            font-size: 12px;
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

        /* NOVAS LARGURAS DAS COLUNAS */
        .col-id {
            width: 40px;
            min-width: 50px;
            max-width: 50px;
        }

        .col-ip {
            width: 110px;
            min-width: 110px;
            max-width: 110px;
        }

        .col-patrimonio {
            width: 50px;
            min-width: 95px;
            max-width: 95px;
        }

        .col-usuario {
            width: 210px;
            min-width: 130px;
            max-width: 130px;
        }

        .col-setor {
            width: 70px;
            min-width: 110px;
            max-width: 110px;
        }

        .col-dispositivo {
            width: 60px;
            min-width: 140px;
            max-width: 140px;
        }

        .col-so {
            width: 60px;
            min-width: 100px;
            max-width: 100px;
        }

        .col-empresa {
            width: 70px;
            min-width: 95px;
            max-width: 95px;
        }

        .col-situacao {
            width: 50px;
            min-width: 95px;
            max-width: 95px;
        }

        .col-descricao {
            width: 120px;
            min-width: 180px;
            max-width: 180px;
        }

        .col-acoes {
            width: 70px;
            min-width: 100px;
            max-width: 100px;
        }

        /* ESTILOS ESPECIAIS PARA STATUS */
        .ativo {
            color: #27ae60 !important;
            font-weight: 600;
        }

        .inativo {
            color: #e74c3c !important;
            font-weight: 600;
        }

        .manutencao {
            color: #f39c12 !important;
            font-weight: 600;
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
            width: 28px;
            height: 28px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            font-size: 13px;
        }

        .btn-editar {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
        }

        .btn-editar:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(52, 152, 219, 0.3);
        }

        .btn-excluir {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
        }

        .btn-excluir:hover {
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

        /* FORMULÁRIO DE BUSCA */
        .formulario-centralizado {
            display: flex;
            justify-content: center;
            margin: 3px 15px;
            padding: -3px 3px;
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
            max-width: 1200px;
        }

        .campo-busca {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            transition: all 0.3s;
            height: 46px;
            width: 300px;
            flex-shrink: 0;
        }

        .campo-busca:focus {
            outline: none;
            border-color: #fdb525;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        .btn-buscar {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
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
            background: linear-gradient(135deg, #ffc64d 0%, #fdb525 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(52, 152, 219, 0.3);
        }

        /* MELHORIAS PARA CELULAR */
        @media (max-width: 1200px) {
            .tabela-container {
                min-width: 1100px;
            }

            .col-id {
                width: 45px;
                min-width: 45px;
                max-width: 45px;
            }

            .col-ip {
                width: 100px;
                min-width: 100px;
                max-width: 100px;
            }

            .col-patrimonio {
                width: 85px;
                min-width: 85px;
                max-width: 85px;
            }

            .col-usuario {
                width: 120px;
                min-width: 120px;
                max-width: 120px;
            }

            .col-setor {
                width: 100px;
                min-width: 100px;
                max-width: 100px;
            }

            .col-dispositivo {
                width: 130px;
                min-width: 130px;
                max-width: 130px;
            }

            .col-so {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }

            .col-empresa {
                width: 85px;
                min-width: 85px;
                max-width: 85px;
            }

            .col-situacao {
                width: 85px;
                min-width: 85px;
                max-width: 85px;
            }

            .col-descricao {
                width: 160px;
                min-width: 160px;
                max-width: 160px;
            }

            .col-acoes {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }
        }

        @media (max-width: 992px) {
            .tabela-container {
                min-width: 1000px;
            }

            .col-id {
                width: 40px;
                min-width: 40px;
                max-width: 40px;
            }

            .col-ip {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }

            .col-patrimonio {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }

            .col-usuario {
                width: 110px;
                min-width: 110px;
                max-width: 110px;
            }

            .col-setor {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }

            .col-dispositivo {
                width: 120px;
                min-width: 120px;
                max-width: 120px;
            }

            .col-so {
                width: 85px;
                min-width: 85px;
                max-width: 85px;
            }

            .col-empresa {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }

            .col-situacao {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }

            .col-descricao {
                width: 150px;
                min-width: 150px;
                max-width: 150px;
            }

            .col-acoes {
                width: 85px;
                min-width: 85px;
                max-width: 85px;
            }
        }

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

            .campo-busca,
            .btn-buscar,
            .btn-atualizar,
            .btn-novo-registro {
                width: 100%;
                min-width: auto;
            }

            .btn-buscar,
            .btn-atualizar,
            .btn-novo-registro {
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
                min-width: 900px;
            }

            th,
            td {
                padding: 8px 6px;
                font-size: 11px;
            }

            .btn-acao {
                padding: 5px 10px;
                font-size: 11px;
            }

            .col-id {
                width: 35px;
                min-width: 35px;
                max-width: 35px;
            }

            .col-ip {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }

            .col-patrimonio {
                width: 75px;
                min-width: 75px;
                max-width: 75px;
            }

            .col-usuario {
                width: 100px;
                min-width: 100px;
                max-width: 100px;
            }

            .col-setor {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }

            .col-dispositivo {
                width: 110px;
                min-width: 110px;
                max-width: 110px;
            }

            .col-so {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }

            .col-empresa {
                width: 75px;
                min-width: 75px;
                max-width: 75px;
            }

            .col-situacao {
                width: 75px;
                min-width: 75px;
                max-width: 75px;
            }

            .col-descricao {
                width: 140px;
                min-width: 140px;
                max-width: 140px;
            }

            .col-acoes {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
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

            .logo {
                height: 35px;
            }

            .titulo-tabela {
                font-size: 14px;
                padding: 10px;
            }

            .tabela-container {
                font-size: 11px;
                min-width: 850px;
            }

            th,
            td {
                padding: 6px 4px;
                font-size: 10px;
            }

            .btn-acao {
                width: 26px;
                height: 26px;
                padding: 0;
                font-size: 10px;
            }

            .col-id {
                width: 30px;
                min-width: 30px;
                max-width: 30px;
            }

            .col-ip {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }

            .col-patrimonio {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }

            .col-usuario {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }

            .col-setor {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }

            .col-dispositivo {
                width: 100px;
                min-width: 100px;
                max-width: 100px;
            }

            .col-so {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }

            .col-empresa {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }

            .col-situacao {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }

            .col-descricao {
                width: 130px;
                min-width: 130px;
                max-width: 130px;
            }

            .col-acoes {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }
        }

        /* TOOLTIP PARA CONTEÚDO TRUNCADO */
        td {
            position: relative;
        }

        td:hover::after {
            content: attr(data-fulltext);
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            bottom: 100%;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 100;
            margin-bottom: 5px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            display: none;
        }

        td:hover::after {
            display: block;
        }

        /* SCROLLBAR PERSONALIZADA */
        .tabela-container::-webkit-scrollbar {
            height: 10px;
            width: 10px;
        }

        .tabela-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .tabela-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 5px;
        }

        .tabela-container::-webkit-scrollbar-thumb:hover {
            background: #a1a1a1;
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <!-- CABEÇALHO -->
        <div class="header-principal">
            <!-- LOGO (substitua pelo caminho correto da sua logo) -->
            <img src="imgs/noroaco.png" alt="Logo" class="logo">

            <!-- MENSAGENS -->
            <?php if (!empty($mensagem)): ?>
                <div class="mensagem-flutuante mensagem-<?php echo $tipoMensagem; ?>">
                    <?php echo $mensagem; ?>
                </div>
            <?php endif; ?>

            <div class="botoes-direita">
                <button class="btn-atualizar" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt"></i> Atualizar
                </button>
                <button class="btn-novo-registro" onclick="alert('Funcionalidade de adicionar novo computador será implementada aqui')">
                    <i class="fas fa-plus"></i> Novo Computador
                </button>
                <button class="btn-novo-registro" onclick="window.location.href='/adm_ti/home.php'">
                    <i class="fas fa-home"></i> Home
                </button>
            </div>
        </div>

        <!-- ÁREA DA TABELA -->
        <div class="container-tabela">
            <div class="titulo-tabela">
                <i class="fas fa-desktop" style="color: #fdb525; margin-right: 10px;"></i>
                Computadores Cadastrados
            </div>

            <!-- TABELA -->
            <div class="tabela-container">
                <table id="tabelaComputadores">
                    <thead>
                        <tr>
                            <th class="col-id">ID</th>
                            <!-- <th class="col-ip">IP</th> -->
                            <th class="col-patrimonio">Patrimônio</th>
                            <th class="col-usuario">Usuário</th>
                            <th class="col-setor">Setor</th>
                            <th class="col-dispositivo">Dispositivo</th>
                            <th class="col-so">S.O.</th>
                            <th class="col-empresa">Empresa</th>
                            <th class="col-situacao">Situ</th>
                            <th class="col-descricao">Descrição</th>
                            <th class="col-acoes">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totalRegistros === 0): ?>
                            <tr>
                                <td colspan="11" style="border: none;">
                                    <div class="lista-vazia">
                                        <div class="lista-vazia-icon">💻</div>
                                        <h3>Nenhum computador encontrado</h3>
                                        <p>Clique em "Novo Computador" para adicionar um novo registro</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($computadores as $computador): ?>
                                <?php
                                // Define classe CSS para situação
                                $situacaoClass = '';
                                switch (strtolower($computador['situacao'])) {
                                    case 'ativo':
                                    case 'ativado':
                                        $situacaoClass = 'ativo';
                                        break;
                                    case 'inativo':
                                    case 'desativado':
                                        $situacaoClass = 'inativo';
                                        break;
                                    case 'manutenção':
                                    case 'manutencao':
                                        $situacaoClass = 'manutencao';
                                        break;
                                }
                                ?>
                                <tr>
                                    <td class="col-id" data-fulltext="<?php echo htmlspecialchars(str_pad($computador['id'], 4, '0', STR_PAD_LEFT)); ?>">
                                        <?php echo str_pad($computador['id'], 4, '0', STR_PAD_LEFT); ?>
                                    </td>
                                    <!-- <td class="col-ip" data-fulltext="<?php echo htmlspecialchars($computador['ip']); ?>">
                                        <strong><?php echo htmlspecialchars($computador['ip']); ?></strong>
                                    </td> -->
                                    <td style="text-align: center;" class="col-patrimonio" data-fulltext="<?php echo htmlspecialchars($computador['patrimonio']); ?>">
                                        <?php echo htmlspecialchars($computador['patrimonio']); ?>
                                    </td>
                                    <td style="text-align: left;" class="col-usuario" data-fulltext="<?php echo htmlspecialchars($computador['usuario']); ?>">
                                        <?php echo htmlspecialchars($computador['usuario']); ?>
                                    </td>
                                    <td style="text-align: center   ;" class="col-setor" data-fulltext="<?php echo htmlspecialchars($computador['setor']); ?>">
                                        <?php echo htmlspecialchars($computador['setor']); ?>
                                    </td>
                                    <td style="text-align: center;" class="col-dispositivo" data-fulltext="<?php echo htmlspecialchars($computador['dispositivo']); ?>">
                                        <?php echo htmlspecialchars($computador['dispositivo']); ?>
                                    </td>
                                    <td style="text-align: center;" class="col-so" data-fulltext="<?php echo htmlspecialchars($computador['so']); ?>">
                                        <?php echo htmlspecialchars($computador['so']); ?>
                                    </td>
                                    <td style="text-align: center;" class="col-empresa" data-fulltext="<?php echo htmlspecialchars($computador['empresa']); ?>">
                                        <?php echo htmlspecialchars($computador['empresa']); ?>
                                    </td>
                                    <td style="text-align: center;" class="col-situacao <?php echo $situacaoClass; ?>" data-fulltext="<?php echo htmlspecialchars($computador['situacao']); ?>">
                                        <?php echo htmlspecialchars($computador['situacao']); ?>
                                    </td>
                                    <td style="text-align: justify;" class="col-descricao" data-fulltext="<?php echo htmlspecialchars($computador['descricao']); ?>">
                                        <?php echo htmlspecialchars(substr($computador['descricao'], 0, 30)) .
                                            (strlen($computador['descricao']) > 30 ? '...' : ''); ?>
                                    </td>
                                    <td class="col-acoes">
                                        <div class="acoes-container">
                                            <button class="btn-acao btn-editar"
                                                onclick="editarComputador(<?php echo $computador['id']; ?>)"
                                                title="Editar computador">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-acao btn-excluir"
                                                onclick="excluirComputador(<?php echo $computador['id']; ?>, '<?php echo htmlspecialchars($computador['ip']); ?>')"
                                                title="Excluir computador">
                                                <i class="fas fa-trash"></i>
                                            </button>
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
        // Função para filtrar a tabela
        function filtrarTabela() {
            const input = document.getElementById('campoBusca');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('tabelaComputadores');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName('td');
                let found = false;

                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        if (td[j].innerHTML.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }

                tr[i].style.display = found ? '' : 'none';
            }
        }

        // Função para editar computador
        function editarComputador(id) {
            alert(`Editar computador ID: ${id}\n\nEsta funcionalidade será implementada aqui.`);
            // window.location.href = `editar_computador.php?id=${id}`;
        }

        // Função para excluir computador
        function excluirComputador(id, ip) {
            if (confirm(`Tem certeza que deseja excluir o computador?\n\nID: ${id}\nIP: ${ip}`)) {
                alert(`Computador ID: ${id} excluído!\n\nEsta é apenas uma simulação.`);
                // Implementar chamada AJAX para excluir do banco de dados
                // window.location.href = `excluir_computador.php?id=${id}`;
            }
        }

        // Filtro em tempo real (opcional)
        document.getElementById('campoBusca').addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                filtrarTabela();
            }
        });

        // Inicialização
        document.addEventListener('DOMContentLoaded', function() {
            // Foco no campo de busca
            const campoBusca = document.getElementById('campoBusca');
            if (campoBusca) {
                campoBusca.focus();
            }

            // Adiciona tooltips para todas as células
            const cells = document.querySelectorAll('td');
            cells.forEach(cell => {
                if (cell.scrollWidth > cell.clientWidth) {
                    const originalText = cell.textContent.trim();
                    if (originalText.length > 0) {
                        cell.setAttribute('title', originalText);
                    }
                }
            });
        });

        // Auto-filtro enquanto digita (opcional)
        document.getElementById('campoBusca').addEventListener('input', function() {
            filtrarTabela();
        });
    </script>
</body>

</html>