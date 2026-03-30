<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <link rel="stylesheet" type="text/css" href="menuh.css">
    <title>NOROAÇO - FINANCEIRO</title>
    <link rel="icon" href="../imgs/favicon.png" type="image/x-icon">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- Font Awesome 6 para ícones adicionais -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        /* ESTILOS GLOBAIS */
        html,
        body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Serif';
            overflow-x: hidden;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Serif';
            transition: margin-left 0.3s ease;
        }

        body.menu-collapsed {
            margin-left: 0;
        }

        /* ===== HEADER HORIZONTAL ===== */
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
            z-index: 10000;
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

        /* ===== MENU VERTICAL ===== */
        .container-Vertical {
            position: fixed;
            top: 50px;
            left: 0;
            width: 230px;
            height: calc(100vh - 50px);
            background-color: #000;
            color: white;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding: 20px;
            z-index: 9998;
            overflow-y: auto;
            transition: transform 0.3s ease;
            transform: translateX(0);
        }

        .container-Vertical.menu-collapsed {
            transform: translateX(-100%);
        }

        /* Barrinha de ícones quando menu está oculto */
        .menu-icons-bar {
            position: fixed;
            top: 50px;
            left: 0;
            width: 60px;
            height: calc(100vh - 50px);
            background-color: #000;
            color: white;
            z-index: 9997;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px 0;
            overflow-y: auto;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            border-right: 1px solid #333;
        }

        .menu-icons-bar.show {
            transform: translateX(0);
        }

        /* Container para cada ícone com dropdown */
        .icon-dropdown-container {
            position: relative;
            width: 100%;
        }

        .menu-icons-bar .setor-icon-item {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 12px 0;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            border-bottom: 1px solid #222;
        }

        .menu-icons-bar .setor-icon-item:hover {
            background-color: #1a1a1a;
        }

        .menu-icons-bar .setor-icon-item i {
            font-size: 22px;
            color: #fdb525;
            margin-bottom: 4px;
        }

        .menu-icons-bar .setor-icon-item span {
            font-size: 10px;
            text-align: center;
            color: #ccc;
            max-width: 50px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .menu-icons-bar .setor-icon-item.active {
            background-color: #fdb525;
        }

        .menu-icons-bar .setor-icon-item.active i,
        .menu-icons-bar .setor-icon-item.active span {
            color: #000;
        }

        /* DROPDOWN SUSPENSO - APENAS PÁGINAS */
        .pages-dropdown {
            position: fixed;
            background: white;
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            z-index: 99999 !important;
            min-width: 240px;
            max-width: 280px;
            display: none;
            border: 1px solid #e0e0e0;
            pointer-events: auto;
            overflow: hidden;
        }

        .pages-dropdown.show {
            display: block;
        }

        /* Lista de páginas */
        .pages-dropdown .pages-list {
            list-style: none;
            margin: 0;
            padding: 5px 0;
            max-height: 350px;
            overflow-y: auto;
        }

        .pages-dropdown .page-item {
            padding: 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .pages-dropdown .page-item:last-child {
            border-bottom: none;
        }

        .pages-dropdown .page-item a {
            color: #333;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            font-size: 13px;
            transition: all 0.2s;
        }

        .pages-dropdown .page-item a:hover {
            background: #fdb525;
            color: #000;
            padding-left: 20px;
        }

        .pages-dropdown .page-item i {
            width: 18px;
            color: #000;
            font-size: 14px;
            text-align: center;
        }

        .pages-dropdown .page-item a:hover i {
            color: #000;
        }

        /* Separador de submenu */
        .pages-dropdown .submenu-separator {
            padding: 5px 15px 2px 15px;
            font-size: 11px;
            font-weight: bold;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
            margin-top: 5px;
        }

        .pages-dropdown .submenu-separator:first-of-type {
            margin-top: 0;
            border-top: none;
        }

        .pages-dropdown .submenu-separator i {
            margin-right: 5px;
            color: #fdb525;
            font-size: 12px;
        }

        /* Quando não há páginas */
        .pages-dropdown .no-pages {
            padding: 20px;
            text-align: center;
            color: #999;
            font-style: italic;
        }

        /* Tooltip para os ícones */
        .menu-icons-bar .setor-icon-item {
            position: relative;
        }

        .menu-icons-bar .setor-icon-item:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            left: 70px;
            top: 50%;
            transform: translateY(-50%);
            background-color: #fdb525;
            color: #000;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 9999;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
            pointer-events: none;
        }

        .menu-icons-bar .setor-icon-item:hover::before {
            content: '';
            position: absolute;
            left: 60px;
            top: 50%;
            transform: translateY(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: transparent #fdb525 transparent transparent;
            z-index: 9999;
            pointer-events: none;
        }

        .content-wrapper {
            margin-left: 230px;
            margin-top: 50px;
            padding: 20px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 50px);
            background-color: #f4f4f4;
            position: relative;
            z-index: 1;
        }

        .content-wrapper.menu-collapsed {
            margin-left: 60px;
        }

        .user-panel-Vertical {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 0px;
            padding: 18px 0;
            width: 100%;
            border-bottom: 1px solid #333;
        }

        .user-panel-Vertical .image-Vertical i {
            font-size: 25px;
            color: #fdb525;
        }

        .user-panel-Vertical .info-Vertical p {
            margin: 0;
            line-height: 1.2;
            color: white;
        }

        .user-panel-Vertical .info-Vertical p:first-child {
            font-size: 18px;
            font-weight: bold;
        }

        .user-panel-Vertical .info-Vertical .designation-Vertical {
            font-size: 16px;
            color: #ccc;
        }

        .sidebar-menu-Vertical {
            list-style: none;
            padding: 0;
            width: 100%;
            margin-top: 10px;
        }

        .sidebar-menu-Vertical>li {
            margin-bottom: 15px;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
            position: relative;
        }

        .setor-titulo {
            color: #fdb525;
            font-size: 16px;
            font-weight: bold;
            margin: 10px 0 5px 0;
            padding: 8px 5px 8px 10px;
            border-left: 3px solid #fdb525;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .setor-titulo i {
            color: #fdb525;
            font-size: 16px;
            width: 20px;
            text-align: center;
        }

        .sidebar-menu-Vertical a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 0 5px 10px;
            font-size: 15px;
            transition: all 0.3s;
        }

        .sidebar-menu-Vertical a:hover {
            color: #fdb525;
            padding-left: 15px;
        }

        .sidebar-menu-Vertical a:hover i {
            color: #fdb525;
        }

        .treeview-Vertical {
            list-style: none;
            padding-left: 0;
        }

        .treeview-Vertical>a {
            font-size: 16px;
            font-weight: bold;
            padding: 8px 0 8px 10px;
            border-radius: 4px;
            margin-bottom: 2px;
            cursor: pointer;
        }

        .treeview-Vertical>a:hover {
            background-color: #2a2a2a;
        }

        .treeview-Vertical-menu {
            display: none;
            padding-left: 20px;
            margin-top: 5px;
            margin-bottom: 5px;
            list-style: none;
        }

        .treeview-Vertical.active .treeview-Vertical-menu {
            display: block;
        }

        .treeview-Vertical-menu a {
            font-size: 14px;
            padding: 6px 0;
            border-bottom: 1px dotted #333;
        }

        .treeview-Vertical-menu a:last-child {
            border-bottom: none;
        }

        .treeview-Vertical-menu a i {
            font-size: 14px;
            width: 20px;
            color: white;
            transition: color 0.3s;
        }

        .fa-angle-right {
            transition: transform 0.3s;
            color: white;
        }

        .treeview-Vertical.active .fa-angle-right {
            transform: rotate(90deg);
        }

        .submenu-icon {
            width: 20px;
            text-align: center;
            color: white;
        }

        .no-pages {
            color: #888;
            font-style: italic;
            padding: 10px 0 10px 30px;
            font-size: 13px;
        }

        /* Scrollbar personalizada */
        .container-Vertical::-webkit-scrollbar,
        .menu-icons-bar::-webkit-scrollbar,
        .pages-dropdown .pages-list::-webkit-scrollbar {
            width: 8px;
        }

        .container-Vertical::-webkit-scrollbar-track,
        .menu-icons-bar::-webkit-scrollbar-track,
        .pages-dropdown .pages-list::-webkit-scrollbar-track {
            background: #fff;
        }

        .container-Vertical::-webkit-scrollbar-thumb,
        .menu-icons-bar::-webkit-scrollbar-thumb,
        .pages-dropdown .pages-list::-webkit-scrollbar-thumb {
            background: #fdb525;
            border-radius: 4px;
        }

        .container-Vertical::-webkit-scrollbar-thumb:hover,
        .menu-icons-bar::-webkit-scrollbar-thumb:hover,
        .pages-dropdown .pages-list::-webkit-scrollbar-thumb:hover {
            background: #e6a420;
        }

        /* Garantir que todos os ícones dentro do menu sejam brancos por padrão */
        .sidebar-menu-Vertical i {
            color: white;
        }

        /* Exceção para o ícone do usuário que deve ser amarelo */
        .user-panel-Vertical i {
            color: #fdb525 !important;
        }

        /* Exceção para o ícone do setor que deve ser amarelo */
        .setor-titulo i {
            color: #fdb525 !important;
        }

        /* Ajuste para o rodapé */
        .container-Vertical>div:last-child i {
            color: #888;
        }
    </style>
</head>

<body>
    <?php
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    ?>

    <!-- DROPDOWN DE PÁGINAS - FINANCEIRO -->
    <div class="pages-dropdown" id="dropdown-financeiro" data-setor-id="financeiro">
        <div class="pages-list">
            <div class="submenu-separator">
                <i class="fas fa-dollar"></i>
                LIMITE DE CRÉDITO
            </div>
            <div class="page-item">
                <a href="../LIMITE_CRED/limite_cred.php">
                    <i class="fas fa-search"></i>
                    CONSULTA
                </a>
            </div>
            <div class="page-item">
                <a href="../LIMITE_CRED/limite_cred_fin.php">
                    <i class="fas fa-check-circle"></i>
                    APROVAÇÃO
                </a>
            </div>

            <div class="submenu-separator">
                <i class="fas fa-address-card"></i>
                DADOS CADASTRAIS
            </div>
            <div class="page-item">
                <a href="DADOS_CAD/dados_cadastrais.php">
                    <i class="fas fa-search"></i>
                    CONSULTA
                </a>
            </div>
            <!-- <div class="page-item">
                <a href="DADOS_CAD/dados_cadastrais_fin.php">
                    <i class="fas fa-check-circle"></i>
                    APROVAÇÃO
                </a>
            </div> -->
        </div>
    </div>

    <!-- ===== HEADER HORIZONTAL ===== -->
    <div class="container-horizontal">
        <div class="left-section">
            <button class="menu-toggle" id="menuToggle" onclick="toggleMenu()">
                <i class="fas fa-bars"></i>
            </button>
            <a href="home.php" class="logo-horizontal">
                <img src="../imgs/logo_b.png" alt="Logo">
            </a>
        </div>
    </div>

    <!-- ===== BARRINHA DE ÍCONES (MENU COLAPSADO) ===== -->
    <div class="menu-icons-bar" id="menuIconsBar">
        <!-- Setor Financeiro -->
        <div class="icon-dropdown-container">
            <div class="setor-icon-item"
                data-setor-id="financeiro"
                data-tooltip="Financeiro"
                onmouseenter="showPagesDropdown('financeiro', this)"
                onmouseleave="hidePagesDropdown('financeiro')">
                <i class="fas fa-folder"></i>
                <span>Financeiro</span>
            </div>
        </div>
    </div>

    <!-- ===== MENU VERTICAL ===== -->
    <div class="container-Vertical" id="menuVertical">
        <ul class="sidebar-menu-Vertical">
            <!-- SETOR: FINANCEIRO -->
            <li data-setor-id="financeiro">
                <div class="setor-titulo">
                    <i class="fas fa-folder"></i>
                    FINANCEIRO
                </div>

                <ul style="list-style: none; padding-left: 0;">



                    <!-- DADOS_CAD -->
                    <li class="treeview-Vertical">
                        <a href="javascript:void(0);" onclick="toggleSubmenu(this.parentElement)">
                            <i class="fas fa-users"></i>
                            <span>DADOS CADASTRAIS</span>
                            <i class="fas fa-angle-right" style="margin-left: auto;"></i>
                        </a>
                        <ul class="treeview-Vertical-menu">
                            <li>
                                <a href="../DADOS_CAD/dados_cadastrais.php">
                                    <i class="fas fa-search"></i>
                                    CONSULTA
                                </a>
                            </li>
                            <!-- <li>
                                <a href="../DADOS_CAD/dados_cadastrais_fin.php">
                                    <i class="fas fa-check-circle"></i>
                                    APROVAÇÃO
                                </a>
                            </li> -->
                        </ul>
                    </li>

                    <!-- LIMITE_CRED -->
                    <li class="treeview-Vertical">
                        <a href="javascript:void(0);" onclick="toggleSubmenu(this.parentElement)">
                            <i class="fas fa-dollar-sign"></i>
                            <span>LIMITE DE CRÉDITO</span>
                            <i class="fas fa-angle-right" style="margin-left: auto;"></i>
                        </a>
                        <ul class="treeview-Vertical-menu">
                            <li>
                                <a href="../LIMITE_CRED/limite_cred.php">
                                    <i class="fas fa-search"></i>
                                    CONSULTA
                                </a>
                            </li>
                            <li>
                                <a href="../LIMITE_CRED/limite_cred_fin.php">
                                    <i class="fas fa-check-circle"></i>
                                    APROVAÇÃO
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>
        </ul>

        <div style="margin-top: auto; padding: 20px 0; width: 100%; border-top: 1px solid #333; text-align: center; color: #888; font-size: 12px;">
            <i class="fas fa-copyright"></i> NOROAÇO - v1.0
        </div>
    </div>

    <div class="content-wrapper" id="contentWrapper">
        <!-- Conteúdo da página será carregado aqui -->
    </div>

    <script>
        // Controle dos dropdowns
        let activeDropdown = null;
        let hideTimeouts = {};

        // Função para posicionar o dropdown ao lado do ícone
        function positionDropdownNearIcon(setorId, iconElement) {
            const dropdown = document.getElementById(`dropdown-${setorId}`);
            if (!dropdown || !iconElement) return;

            const rect = iconElement.getBoundingClientRect();

            // Posicionar à direita do ícone
            let left = rect.right + 5;
            let top = rect.top;

            // Ajustar para não sair da tela
            const dropdownWidth = 280;

            // Verificar limite direito
            if (left + dropdownWidth > window.innerWidth) {
                left = rect.left - dropdownWidth - 5;
            }

            // Verificar limite superior/inferior
            if (top < 5) {
                top = 5;
            }

            dropdown.style.left = left + 'px';
            dropdown.style.top = top + 'px';
        }

        // Função para mostrar o dropdown
        window.showPagesDropdown = function(setorId, element) {
            const dropdown = document.getElementById(`dropdown-${setorId}`);
            if (!dropdown) return;

            // Cancelar qualquer timeout de hide para este dropdown
            if (hideTimeouts[setorId]) {
                clearTimeout(hideTimeouts[setorId]);
                delete hideTimeouts[setorId];
            }

            // Posicionar o dropdown
            positionDropdownNearIcon(setorId, element);

            // Esconder qualquer outro dropdown ativo
            if (activeDropdown && activeDropdown !== setorId) {
                const oldDropdown = document.getElementById(`dropdown-${activeDropdown}`);
                if (oldDropdown) {
                    oldDropdown.classList.remove('show');
                }
            }

            // Mostrar este dropdown
            dropdown.classList.add('show');
            activeDropdown = setorId;
        };

        // Função para esconder o dropdown com delay
        window.hidePagesDropdown = function(setorId) {
            hideTimeouts[setorId] = setTimeout(() => {
                const dropdown = document.getElementById(`dropdown-${setorId}`);
                if (dropdown) {
                    dropdown.classList.remove('show');
                    if (activeDropdown === setorId) {
                        activeDropdown = null;
                    }
                }
                delete hideTimeouts[setorId];
            }, 300);
        };

        // Adicionar eventos de mouse para cada dropdown
        document.addEventListener('DOMContentLoaded', function() {
            const dropdownFinanceiro = document.getElementById('dropdown-financeiro');
            if (dropdownFinanceiro) {
                dropdownFinanceiro.addEventListener('mouseenter', function() {
                    if (hideTimeouts['financeiro']) {
                        clearTimeout(hideTimeouts['financeiro']);
                        delete hideTimeouts['financeiro'];
                    }
                });

                dropdownFinanceiro.addEventListener('mouseleave', function(e) {
                    window.hidePagesDropdown('financeiro');
                });
            }
        });

        // Função para alternar submenus
        window.toggleSubmenu = function(element) {
            element.classList.toggle('active');
        };

        // Função para atualizar o estado do menu
        function updateMenuState(collapsed) {
            const menuVertical = document.getElementById('menuVertical');
            const contentWrapper = document.getElementById('contentWrapper');
            const menuIconsBar = document.getElementById('menuIconsBar');
            const body = document.body;
            const toggleIcon = document.querySelector('#menuToggle i');

            if (collapsed) {
                menuVertical.classList.add('menu-collapsed');
                contentWrapper.classList.add('menu-collapsed');
                menuIconsBar.classList.add('show');
                body.classList.add('menu-collapsed');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-bars');
                    toggleIcon.classList.add('fa-chevron-right');
                }
            } else {
                menuVertical.classList.remove('menu-collapsed');
                contentWrapper.classList.remove('menu-collapsed');
                menuIconsBar.classList.remove('show');
                body.classList.remove('menu-collapsed');
                if (toggleIcon) {
                    toggleIcon.classList.remove('fa-chevron-right');
                    toggleIcon.classList.add('fa-bars');
                }

                // Esconder todos os dropdowns quando o menu é expandido
                document.querySelectorAll('.pages-dropdown').forEach(d => d.classList.remove('show'));
                activeDropdown = null;
            }
        }

        // Função toggleMenu para o header
        window.toggleMenu = function() {
            const menuVertical = document.getElementById('menuVertical');
            const isCollapsed = !menuVertical.classList.contains('menu-collapsed');

            updateMenuState(isCollapsed);
            localStorage.setItem('menuCollapsed', isCollapsed ? 'true' : 'false');
        };

        // Verificar estado salvo ao carregar
        document.addEventListener('DOMContentLoaded', function() {
            const menuCollapsed = localStorage.getItem('menuCollapsed') === 'true';
            updateMenuState(menuCollapsed);

            // Adicionar evento de clique para todos os links do menu
            document.querySelectorAll('.sidebar-menu-Vertical a[href], .pages-dropdown a[href]').forEach(link => {
                // Não adicionar evento para links que são apenas para abrir submenu
                if (link.getAttribute('href') !== 'javascript:void(0);') {
                    link.addEventListener('click', function(e) {
                        // Permitir o redirecionamento normal
                        return true;
                    });
                }
            });
        });

        // Fechar dropdowns ao clicar fora
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.setor-icon-item') && !e.target.closest('.pages-dropdown')) {
                document.querySelectorAll('.pages-dropdown').forEach(d => d.classList.remove('show'));
                activeDropdown = null;
            }
        });

        // Recalcular posição dos dropdowns quando a janela for redimensionada
        window.addEventListener('resize', function() {
            if (activeDropdown) {
                const iconItem = document.querySelector(`.setor-icon-item[data-setor-id="${activeDropdown}"]`);
                if (iconItem) {
                    positionDropdownNearIcon(activeDropdown, iconItem);
                }
            }
        });
    </script>
</body>

</html>