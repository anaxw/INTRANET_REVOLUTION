<?php
// conexao_banco.php
try {
    $pdo = new PDO(
        "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
        "postgres",
        "postgres"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Consulta para obter o nome do usuário com ID fixo = 1
$query = "SELECT user_nome FROM central_user WHERE user_id = 2";
$stmt = $pdo->query($query);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userNome = $user['user_nome'] ?? 'Usuário';
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <link rel="stylesheet" type="text/css" href="menuh.css">
    <title>NOROAÇO</title>
    <link rel="icon" href="./imgs/favicon.png" type="image/x-icon">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">

    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Serif';
            transition: margin-left 0.3s ease;
        }

        body.menu-collapsed {
            margin-left: 0;
        }

        .container-horizontal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 50px;
            background-color: #000;
            color: white;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 1;
        }

        .left-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .menu-toggle {
            background: transparent;
            border: none;
            color: #fdb525;
            font-size: 20px;
            cursor: pointer;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: background-color 0.3s;
        }

        .menu-toggle:hover {
            background-color: #333;
        }

        .logo-horizontal img {
            max-height: 40px;
            margin-top: 10px;
        }

        .user-info-horizontal {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-info-horizontal span {
            color: white;
            font-weight: bold;
        }

        .logout-button-horizontal i {
            color: #fff;
        }

        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            background-color: #fff;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0, 0, 0, 0.2);
            z-index: 10000;
            right: 0;
            border-radius: 5px;
            overflow: hidden;
        }

        .dropdown-content a {
            color: black;
            padding: 10px 16px;
            text-decoration: none;
            display: block;
            transition: background-color 0.2s ease-in-out;
        }

        .dropdown-content a:hover {
            background-color: #f1f1f1;
        }

        .dropdown:hover .dropdown-content {
            display: block;
            cursor: pointer;
        }

        .user-name {
            cursor: pointer;
        }

        .dropdown-content a i {
            margin-right: 8px;
            width: 20px;
            text-align: center;
        }
    </style>

</head>

<body>
    <div class="container-horizontal">
        <div class="left-section">
            <button class="menu-toggle" id="menuToggle" onclick="toggleMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <a href="home.php" class="logo-horizontal">
                <img src="imgs/logo_b.png" alt="Logo">
            </a>
        </div>
        <div class="user-info-horizontal">
            <i class="fas fa-user horizontal-icon"></i>
            <div class="dropdown">
                <span class="user-name"><?php echo htmlspecialchars($userNome); ?></span>
                <div class="dropdown-content">
                    <a href="conta.php"><i class="fas fa-user-circle"></i> Conta</a>
                    <a href="conta_alterar_senha.php"><i class="fas fa-key"></i> Alterar Senha</a>
                </div>
            </div>
            <a href="sair.php" class="logout-button-horizontal">
                <i class="fa fa-power-off" aria-hidden="true"></i>
            </a>
        </div>
    </div>

    <script>
        window.toggleMenu = function() {
            const body = document.body;
            const toggleIcon = document.querySelector('#menuToggle i');
            
            body.classList.toggle('menu-collapsed');
            
            const event = new CustomEvent('menuToggle', { 
                detail: { collapsed: body.classList.contains('menu-collapsed') } 
            });
            window.dispatchEvent(event);
            
            if (toggleIcon) {
                if (body.classList.contains('menu-collapsed')) {
                    toggleIcon.classList.remove('fa-bars');
                    toggleIcon.classList.add('fa-chevron-right');
                    
                    localStorage.setItem('menuCollapsed', 'true');
                } else {
                    toggleIcon.classList.remove('fa-chevron-right');
                    toggleIcon.classList.add('fa-bars');
                    
                    localStorage.setItem('menuCollapsed', 'false');
                }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const menuCollapsed = localStorage.getItem('menuCollapsed') === 'true';
            const body = document.body;
            const toggleIcon = document.querySelector('#menuToggle i');
            
            if (menuCollapsed) {
                body.classList.add('menu-collapsed');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-bars');
                    toggleIcon.classList.add('fa-chevron-right');
                }
            }
        });
    </script>
</body>

</html>