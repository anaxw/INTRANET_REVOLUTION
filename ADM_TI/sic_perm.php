<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

/*
====================================================
 CONFIGURAÇÃO DO BANCO
====================================================
*/

function conectarBanco()
{
    $host  = '10.10.94.15';
    $base  = 'C:\\SIC\\Arq01\\ARQSIST.FDB';
    $user  = 'sysdba';
    $senha = 'masterkey';

    $dsn = "firebird:dbname=$host:$base;charset=WIN1252";

    return new PDO($dsn, $user, $senha, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_AUTOCOMMIT => true
    ]);
}

/*
====================================================
 USUÁRIOS FIXOS
====================================================
*/

$usuarios_fixos = [
    'TECNICO',
    'TECNICO7',
    'TECNICO8',
    'TECNICO10',
    'TECNICO11',
    'ANDREA',
    'EFANECO',
    'ALINELIO',
    'MARCELLY',
    'MARINHO',
    'VALERIANEC',
    'ISABELLY',
    'GABRIELAF',
    'MARCUS',
    'EMANOELE',
    'KARINA.SANTOS',
    'ELCIO',
    'LARISSAB',
    'JESSICA',
    'NSIQUEIRAF',
    'ELLEN',
    'MARIAI',
    'BIANCA.FAT',
    'GRAZIELLE_FAT',
    'WILDNER',
    'LEANDROLO',
    'DAIANE',
    'GUSTAVOLUPI',
    'ADRIANO.HERRERO',
    'NAYANE',
    'MARIANA',
    'MATHEUSR',
    'JOSE.HERBERT',
    'CEZAR',
    'CEZARF',
    'FRANKS',
    'ALINE',
    'JULLYENEC',
    'MARCELA',
    'KARINAC',
    'PAULO',
    'BARBARAC',
    'ANACLARA',
    'NATALIA',
    'SIRLENE',
    'SONIA',
    'VINICIOS',
    'SIDNEI',
    'JULIANA',
    'SUELLENG',
    'FERNANDA',
    'EVANDRO',
    'BRUNOC',
    'LENARA',
    'STELA',
    'ULISSES',
    'JEFFERSON',
    'VENANCIO',
    'CAIO',
    'KEMILY',
    'ROVERE',
    'ELLENP',
    'MEDUARDA',
    'ALVES',
    'NOBRE',
    'JULIO',
    'CLAUDIA'
];

/*
====================================================
 BUSCA VENDEDORES
====================================================
*/

$pdo = conectarBanco();

$sqlVendedores = "
    SELECT
        c.codic,
        c.nome
    FROM arqcad c
    WHERE c.tipoc = 'V'
      AND c.situ  = 'A'
    ORDER BY c.nome
";

$stmt = $pdo->prepare($sqlVendedores);
$stmt->execute();

$vendedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
====================================================
 PROCESSAMENTO POST
====================================================
*/

$mensagem = '';
$tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['processar'])) {

    $codic = $_POST['vendedor'] ?? '';

    if ($codic == '') {

        $mensagem = 'Selecione um vendedor.';
        $tipo = 'erro';
        $showMessage = true;
    } else {

        try {

            $sqlInsert = "
                INSERT INTO permissao_usuario
                (
                    aplicacao,
                    cod_aplicacao,
                    cod_aplicacao1,
                    usuario,
                    incluir,
                    alterar,
                    excluir,
                    adm,
                    cod_empresa
                )
                VALUES
                (
                    'MCADASTRO',
                    ?,
                    'V',
                    ?,
                    'S',
                    'S',
                    'S',
                    'N',
                    '1'
                )
            ";

            $sqlUpdate = "
                UPDATE permissao_usuario
                SET
                    incluir = 'S',
                    alterar = 'S',
                    excluir = 'S',
                    adm = 'N'
                WHERE aplicacao = 'MCADASTRO'
                  AND cod_aplicacao = ?
                  AND cod_aplicacao1 = 'V'
                  AND usuario = ?
                  AND cod_empresa = '1'
            ";

            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtUpdate = $pdo->prepare($sqlUpdate);

            $insert = 0;
            $update = 0;

            foreach ($usuarios_fixos as $usuario) {

                try {
                    $stmtInsert->execute([$codic, $usuario]);
                    $insert++;
                } catch (PDOException $e) {
                    $stmtUpdate->execute([$codic, $usuario]);
                    $update++;
                }
            }

            $mensagem = "Processo concluído com sucesso!<br><br>Inserções: <strong>{$insert}</strong><br>Atualizações: <strong>{$update}</strong>";
            $tipo = 'ok';
            $showMessage = true;
        } catch (Exception $e) {

            $mensagem = 'Erro: ' . $e->getMessage();
            $tipo = 'erro';
            $showMessage = true;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permissionamento SIC</title>
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
            background: linear-gradient(135deg, #333 100%);
            height: 70px;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            position: relative;
            border-bottom: 3px solid #fdb525;
        }


        .botoes-direita {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-resumo {
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
        }

        .btn-resumo.enabled:hover {
            background: linear-gradient(135deg, #ffc64d 0%, #fdb525 100%);
            color: white;
            text-decoration: none;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(253, 181, 37, 0.3);
        }

        .logo-noroaco {
            height: 45px;
            width: auto;
        }

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
        }

        .titulo-tabela i {
            color: #fdb525 !important;
            margin-right: 8px;
        }

        /* ====================================================
           ESTILOS PARA O FORMULÁRIO DE PERMISSIONAMENTO
           ==================================================== */

        .form-permissionamento {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .form-row {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
        }

        .form-row .form-group {
            margin-bottom: 0;
        }

        .form-row .form-group:first-child {
            flex: 1;
        }

        .form-permissionamento select.form-control {
            width: 100%;
            padding: 12px 15px;
            font-size: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            background: #f8f9fa;
            transition: all 0.3s;
        }

        .form-permissionamento select.form-control:focus {
            outline: none;
            border-color: #fdb525;
            background: white;
            box-shadow: 0 0 0 3px rgba(253, 181, 37, 0.1);
        }

        .btn-salvar-compacto {
            background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            transition: all 0.3s;
            height: 45px;
            min-width: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0 20px;
            box-shadow: 0 4px 6px rgba(40, 167, 69, 0.2);
        }

        .btn-salvar-compacto:hover {
            background: linear-gradient(135deg, #ffc64d 0%, #fdb525 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-salvar-compacto:active {
            transform: translateY(0);
        }

        .form-permissionamento .text-muted {
            color: #6c757d !important;
            font-size: 13px;
            line-height: 1.5;
            padding: 12px 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 3px solid #fdb525;
        }

        .form-permissionamento .text-muted i {
            color: #fdb525;
            margin-right: 8px;
        }

        /* ====================================================
           ESTILOS PARA MENSAGEM FLUTUANTE
           ==================================================== */

        .mensagem-flutuante {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 400px;
            animation: slideInRight 0.5s ease;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            border: none;
        }

        .mensagem-flutuante.fade-out {
            animation: fadeOutRight 0.5s ease forwards;
        }

        .mensagem-conteudo {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .mensagem-ok {
            background: linear-gradient(135deg, #28a745 0%, #218838 100%);
            color: white;
            border-left: 4px solid #155724;
        }

        .mensagem-erro {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border-left: 4px solid #721c24;
        }

        .mensagem-icon {
            font-size: 18px;
            flex-shrink: 0;
        }

        .mensagem-texto {
            flex: 1;
            line-height: 1.4;
        }

        .mensagem-fechar {
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 16px;
            opacity: 0.8;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
        }

        .mensagem-fechar:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.2);
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }

        /* ====================================================
           RESPONSIVIDADE
           ==================================================== */

        @media (max-width: 768px) {
            body {
                margin: 5px;
            }

            .container-principal {
                min-height: calc(100vh - 10px);
            }

            .header-principal {
                padding: 0 15px;
                height: 60px;
            }

            .logo-noroaco {
                height: 40px;
            }

            .container-tabela {
                padding: 10px;
            }

            .titulo-tabela {
                padding: 10px;
                font-size: 14px;
                margin-bottom: 10px;
            }

            .form-permissionamento {
                padding: 20px;
                margin: 0 10px;
            }

            .form-row {
                flex-direction: column;
                gap: 10px;
            }

            .form-row .form-group:first-child {
                width: 100%;
            }

            .btn-salvar-compacto {
                width: 100%;
                height: 40px;
            }

            .form-permissionamento .text-muted {
                font-size: 12px;
                padding: 10px;
            }

            .mensagem-flutuante {
                min-width: 250px;
                max-width: 300px;
                top: 10px;
                right: 10px;
                left: 10px;
                margin: 0 auto;
            }
        }

        @media (max-width: 480px) {
            body {
                margin: 2px;
            }

            .header-principal {
                height: 50px;
                padding: 0 10px;
            }

            .logo-noroaco {
                height: 35px;
            }

            .titulo-tabela {
                font-size: 13px;
                padding: 8px;
            }

            .form-permissionamento {
                padding: 15px;
            }

            .form-permissionamento select.form-control {
                font-size: 13px;
                padding: 10px 12px;
            }

            .btn-salvar-compacto {
                height: 40px;
                font-size: 14px;
            }

            .mensagem-flutuante {
                width: 90%;
                max-width: none;
                right: 5%;
                left: 5%;
            }
        }
    </style>
</head>

<body>

    <div class="container-principal">
        <div class="header-principal">
            <img src="imgs/noroaco.png" alt="Logo NOROAÇO" class="logo-noroaco">
            <div class="botoes-direita">
                <a href="HOME.php" class="btn-resumo" id="pdf-resumo-btn">
                    <i class="fa-solid fa-home"></i> Home
                </a>
            </div>
        </div>

        <div class="container-tabela">
            <div class="titulo-tabela">
                <i class="fas fa-user-shield"></i> Permissionamento de Vendedores
            </div>

            <div class="form-permissionamento">
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <select name="vendedor" class="form-control" required>
                                <option value="">-- selecione o vendedor --</option>
                                <?php foreach ($vendedores as $v): ?>
                                    <option value="<?= htmlspecialchars($v['CODIC']) ?>">
                                        <?= htmlspecialchars($v['NOME']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <button type="submit" name="processar" class="btn-salvar-compacto">
                                <i class="fa-solid fa-floppy-disk"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <p class="text-muted">
                            <i class="fas fa-info-circle"></i> Esta ação irá inserir ou atualizar as permissões de <?= count($usuarios_fixos) ?> usuários fixos para visualizar o vendedor selecionado.
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Função para mostrar mensagem flutuante
        function mostrarMensagemFlutuante(mensagem, tipo) {
            // Remove mensagens anteriores
            const mensagensAnteriores = document.querySelectorAll('.mensagem-flutuante');
            mensagensAnteriores.forEach(msg => msg.remove());

            // Cria a nova mensagem
            const mensagemDiv = document.createElement('div');
            mensagemDiv.className = `mensagem-flutuante mensagem-${tipo}`;

            // Determina o ícone baseado no tipo
            const iconClass = tipo === 'ok' ? 'fa-check-circle' : 'fa-exclamation-circle';

            mensagemDiv.innerHTML = `
                <div class="mensagem-conteudo">
                    <i class="fas ${iconClass} mensagem-icon"></i>
                    <div class="mensagem-texto">${mensagem}</div>
                    <button class="mensagem-fechar" onclick="fecharMensagem(this)">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;

            // Adiciona ao body
            document.body.appendChild(mensagemDiv);

            // Remove automaticamente após 5 segundos
            setTimeout(() => {
                fecharMensagem(mensagemDiv);
            }, 5000);
        }

        // Função para fechar mensagem
        function fecharMensagem(elemento) {
            const mensagem = elemento.closest ? elemento.closest('.mensagem-flutuante') : elemento;
            mensagem.classList.add('fade-out');

            setTimeout(() => {
                if (mensagem.parentNode) {
                    mensagem.parentNode.removeChild(mensagem);
                }
            }, 500);
        }

        // Mostrar mensagem se houver uma para mostrar (após envio do formulário)
        <?php if (isset($showMessage) && $showMessage && !empty($mensagem)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    mostrarMensagemFlutuante(`<?= addslashes($mensagem) ?>`, '<?= $tipo ?>');
                }, 300); // Pequeno delay para garantir que a página carregou
            });
        <?php endif; ?>

        // Opcional: Melhorar a experiência do usuário com validação
        document.querySelector('form').addEventListener('submit', function(e) {
            const select = document.querySelector('select[name="vendedor"]');
            if (select.value === '') {
                e.preventDefault();
                mostrarMensagemFlutuante('Selecione um vendedor antes de continuar.', 'erro');
                select.focus();
                select.style.borderColor = '#dc3545';
                setTimeout(() => {
                    select.style.borderColor = '';
                }, 2000);
            }
        });
    </script>

</body>

</html>