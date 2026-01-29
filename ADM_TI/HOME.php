<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <!-- META TAGS PARA EVITAR CACHE -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
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

        /* Na seção de estilos da tabela, adicione: */
        th {
            text-align: center !important;
            /* Centraliza todo o conteúdo do cabeçalho */
        }

        /* Ou, se preferir ser mais específico, adicione esta classe: */
        .cabecalho-centralizado th {
            text-align: center !important;
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

        /* SEÇÃO DE QUADROS COM ÍCONES */
        .quadros-ti-container {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }

        .quadros-titulo {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 600;
            text-align: center;
        }

        .quadros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .quadro-item {
            background: white;
            border-radius: 10px;
            padding: 25px 20px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }

        .quadro-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            border-color: #fdb525;
        }

        .quadro-icone {
            font-size: 36px;
            margin-bottom: 15px;
            color: #f39c12;
        }

        .quadro-item.computadores .quadro-icone {
            color: #f39c12;
        }

        .quadro-item.usuarios .quadro-icone {
            color: #f39c12;
        }

        .quadro-item.permissoes .quadro-icone {
            color: #f39c12;
        }

        .quadro-item.ips .quadro-icone {
            color: #f39c12;
        }

        .quadro-titulo {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
        }

        .quadro-descricao {
            font-size: 13px;
            color: #7f8c8d;
            line-height: 1.4;
        }

        .quadro-contador {
            display: block;
            font-size: 24px;
            font-weight: 700;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
        }

        .quadro-item.computadores .quadro-contador {
            color: #f39c12;
        }

        .quadro-item.usuarios .quadro-contador {
            color: #f39c12;
        }

        .quadro-item.permissoes .quadro-contador {
            color: #f39c12;
        }

        .quadro-item.ips .quadro-contador {
            color: #f39c12;
        }

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
            max-width: 1200px;
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

        /* BOTÃO CANCELAR */
        .btn-cancelar {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
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

        .btn-cancelar:hover {
            background: linear-gradient(135deg, #7f8c8d 0%, #95a5a6 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(127, 140, 141, 0.3);
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

            .quadros-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }

            .quadro-item {
                padding: 20px 15px;
            }

            .quadro-icone {
                font-size: 28px;
            }

            .quadro-contador {
                font-size: 20px;
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
            .btn-limpar-tudo,
            .btn-cancelar {
                width: 100%;
                min-width: auto;
            }

            .btn-adicionar,
            .btn-buscar,
            .btn-imprimir,
            .btn-novo-registro,
            .btn-limpar-tudo,
            .btn-cancelar {
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

            .quadros-grid {
                grid-template-columns: 1fr;
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

        .botao-limpar-sempre {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
    </style>
</head>

<body>
    <div class="container-principal">
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo Noroaco" class="logo-noroaco">
        </div>

        <div class="container-tabela">
            <div class="titulo-tabela">
                Controle de Ativos de TI
            </div>
            <div class="quadros-ti-container">
                <div class="quadros-grid">
                    <div class="quadro-item computadores">
                        <div class="quadro-icone">
                            <i class="fas fa-desktop"></i>
                        </div>
                        <h3 class="quadro-titulo">Computadores</h3>
                    </div>
                    
                    <div class="quadro-item computadores">
                        <div class="quadro-icone">
                            <i class="fas fa-print"></i>
                        </div>
                        <h3 class="quadro-titulo">Impressora</h3>
                    </div>

                    <!-- <div class="quadro-item usuarios">
                        <div class="quadro-icone">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="quadro-titulo">Usuários</h3>
                    </div> -->

                    <div class="quadro-item permissoes">
                        <div class="quadro-icone">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3 class="quadro-titulo">Permissões SIC</h3>
                    </div>
<!-- 
                    <div class="quadro-item ips">
                        <div class="quadro-icone">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <h3 class="quadro-titulo">Endereços IP</h3>
                    </div> -->
<!-- 
                    <div class="quadro-item ips">
                        <div class="quadro-icone">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <h3 class="quadro-titulo">Sistemas</h3>
                    </div> -->
                </div>
            </div>

        </div>
    </div>

    <script>
        document.querySelectorAll('.quadro-item').forEach(quadro => {
            quadro.addEventListener('click', function() {

                if (this.classList.contains('computadores')) {
                    window.location.href = 'computadores.php';
                    return;
                }

                if (this.classList.contains('usuarios')) {
                    window.location.href = 'usuarios.html';
                    return;
                }

                if (this.classList.contains('permissoes')) {
                    window.location.href = 'sic_perm.php';
                    return;
                }

                if (this.classList.contains('ips')) {
                    window.location.href = 'ips.html';
                    return;
                }
            });
        });
    </script>

</body>

</html>