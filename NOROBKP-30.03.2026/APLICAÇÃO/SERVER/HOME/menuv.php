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

// Consulta para obter TODOS os setores ativos
$sqlSetores = "SELECT setor_id, setor_nome, setor_icon FROM central_setor WHERE setor_situ = 'A' ORDER BY setor_nome ASC";
$stmtSetores = $pdo->query($sqlSetores);
$setores = $stmtSetores->fetchAll(PDO::FETCH_ASSOC);

// Consulta para obter TODOS os submenus ativos
$sqlSubmenus = "SELECT submenu_id, submenu_desc, submenu_icon FROM central_submenu WHERE submenu_status = 'A' ORDER BY submenu_desc ASC";
$stmtSubmenus = $pdo->query($sqlSubmenus);
$submenus = $stmtSubmenus->fetchAll(PDO::FETCH_ASSOC);

// Consulta para obter TODAS as páginas ativas
$sqlPages = "SELECT page_id, page_desc, page_url, page_submenu, page_setor, page_icon 
             FROM central_pages 
             WHERE page_status = 'A' 
             ORDER BY page_desc ASC";
$stmtPages = $pdo->query($sqlPages);
$pages = $stmtPages->fetchAll(PDO::FETCH_ASSOC);

// Organizar as páginas por setor e submenu
$pagesBySetorAndSubmenu = [];
foreach ($pages as $page) {
    $setorId = $page['page_setor'];
    $submenuId = $page['page_submenu'];
    
    if (!isset($pagesBySetorAndSubmenu[$setorId])) {
        $pagesBySetorAndSubmenu[$setorId] = [];
    }
    
    if (!isset($pagesBySetorAndSubmenu[$setorId][$submenuId])) {
        $pagesBySetorAndSubmenu[$setorId][$submenuId] = [];
    }
    
    $pagesBySetorAndSubmenu[$setorId][$submenuId][] = $page;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>NOROAÇO</title>
    <link rel="icon" href="./img/favicon.png" type="image/x-icon">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: 'Serif';
            overflow-x: hidden;
        }

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
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            z-index: 99999 !important;
            min-width: 240px;
            max-width: 280px;
            display: none;
            border: 1px solid #e0e0e0;
            pointer-events: auto;
            overflow: hidden;
            transition: opacity 0.2s ease;
            opacity: 0;
        }

        .pages-dropdown.show {
            display: block;
            opacity: 1;
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
            color: #fdb525;
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
            background: #f9f9f9;
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
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
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

        .sidebar-menu-Vertical > li {
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

        .treeview-Vertical > a {
            font-size: 16px;
            font-weight: bold;
            padding: 8px 0 8px 10px;
            background-color: #1a1a1a;
            border-radius: 4px;
            margin-bottom: 2px;
            cursor: pointer;
        }

        .treeview-Vertical > a:hover {
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
        .container-Vertical > div:last-child i {
            color: #888;
        }
    </style>
</head>
<body>
    <!-- DROPDOWNS DE PÁGINAS - CRIADOS DINAMICAMENTE -->
    <?php foreach ($setores as $setor): 
        $setorId = $setor['setor_id'];
        $setorNome = $setor['setor_nome'];
        
        // Verificar se este setor tem alguma página
        $temPaginaNoSetor = false;
        foreach ($submenus as $submenu) {
            if (!empty($pagesBySetorAndSubmenu[$setorId][$submenu['submenu_id']])) {
                $temPaginaNoSetor = true;
                break;
            }
        }
        
        if (!$temPaginaNoSetor) {
            continue;
        }
    ?>
        <div class="pages-dropdown" id="dropdown-<?php echo $setorId; ?>" data-setor-id="<?php echo $setorId; ?>">
            <div class="pages-list">
                <?php 
                foreach ($submenus as $submenu): 
                    $submenuId = $submenu['submenu_id'];
                    $submenuDesc = $submenu['submenu_desc'];
                    $submenuIcon = !empty($submenu['submenu_icon']) ? $submenu['submenu_icon'] : 'fa-circle';
                    
                    $paginasDoSubmenu = isset($pagesBySetorAndSubmenu[$setorId][$submenuId]) 
                        ? $pagesBySetorAndSubmenu[$setorId][$submenuId] 
                        : [];
                    
                    if (empty($paginasDoSubmenu)) {
                        continue;
                    }
                ?>
                    <!-- Separador de submenu -->
                    <div class="submenu-separator">
                        <i class="fas <?php echo htmlspecialchars($submenuIcon); ?>"></i>
                        <?php echo htmlspecialchars($submenuDesc); ?>
                    </div>
                    
                    <?php foreach ($paginasDoSubmenu as $page): ?>
                        <div class="page-item">
                            <a href="<?php echo htmlspecialchars($page['page_url']); ?>" target="_blank">
                                <?php if (!empty($page['page_icon'])): ?>
                                    <i class="fas <?php echo htmlspecialchars($page['page_icon']); ?>"></i>
                                <?php else: ?>
                                    <i class="fas fa-file-alt"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($page['page_desc']); ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Barrinha de ícones -->
    <div class="menu-icons-bar" id="menuIconsBar">
        <?php 
        $setorCount = 0;
        foreach ($setores as $setor): 
            $setorId = $setor['setor_id'];
            $setorNome = $setor['setor_nome'];
            $setorIcon = !empty($setor['setor_icon']) ? $setor['setor_icon'] : 'fa-building';
            
            // Verificar se este setor tem alguma página
            $temPaginaNoSetor = false;
            foreach ($submenus as $submenu) {
                if (!empty($pagesBySetorAndSubmenu[$setorId][$submenu['submenu_id']])) {
                    $temPaginaNoSetor = true;
                    break;
                }
            }
            
            if (!$temPaginaNoSetor) {
                continue;
            }
            
            $setorCount++;
        ?>
            <div class="icon-dropdown-container">
                <div class="setor-icon-item" 
                     data-setor-id="<?php echo $setorId; ?>" 
                     data-tooltip="<?php echo htmlspecialchars($setorNome); ?>"
                     onmouseenter="showPagesDropdown(<?php echo $setorId; ?>, this)"
                     onmouseleave="hidePagesDropdown(<?php echo $setorId; ?>)"
                     onmousemove="updateDropdownPosition(<?php echo $setorId; ?>, event)">
                    <i class="fas <?php echo htmlspecialchars($setorIcon); ?>"></i>
                    <span><?php echo htmlspecialchars($setorNome); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="container-Vertical" id="menuVertical">
        <ul class="sidebar-menu-Vertical">
            <?php 
            $setorCount = 0;
            foreach ($setores as $setor): 
                $setorId = $setor['setor_id'];
                $setorNome = $setor['setor_nome'];
                $setorIcon = !empty($setor['setor_icon']) ? $setor['setor_icon'] : 'fa-building';
                
                // Verificar se este setor tem alguma página
                $temPaginaNoSetor = false;
                foreach ($submenus as $submenu) {
                    if (!empty($pagesBySetorAndSubmenu[$setorId][$submenu['submenu_id']])) {
                        $temPaginaNoSetor = true;
                        break;
                    }
                }
                
                if (!$temPaginaNoSetor) {
                    continue;
                }
                
                $setorCount++;
            ?>
                <li data-setor-id="<?php echo $setorId; ?>">
                    <div class="setor-titulo">
                        <i class="fas <?php echo htmlspecialchars($setorIcon); ?>"></i>
                        <?php echo htmlspecialchars($setorNome); ?>
                    </div>
                    
                    <ul style="list-style: none; padding-left: 0;">
                        <?php foreach ($submenus as $submenu): 
                            $submenuId = $submenu['submenu_id'];
                            $submenuDesc = $submenu['submenu_desc'];
                            $submenuIcon = !empty($submenu['submenu_icon']) ? $submenu['submenu_icon'] : 'fa-circle';
                            
                            $paginasDoSubmenu = isset($pagesBySetorAndSubmenu[$setorId][$submenuId]) 
                                ? $pagesBySetorAndSubmenu[$setorId][$submenuId] 
                                : [];
                            
                            if (empty($paginasDoSubmenu)) {
                                continue;
                            }
                        ?>
                            <li class="treeview-Vertical">
                                <a href="javascript:void(0);" onclick="toggleSubmenu(this.parentElement)">
                                    <i class="fas <?php echo htmlspecialchars($submenuIcon); ?> submenu-icon"></i>
                                    <span><?php echo htmlspecialchars($submenuDesc); ?></span>
                                    <i class="fas fa-angle-right" style="margin-left:auto;"></i>
                                </a>
                                <ul class="treeview-Vertical-menu">
                                    <?php foreach ($paginasDoSubmenu as $page): ?>
                                        <li>
                                            <a href="<?php echo htmlspecialchars($page['page_url']); ?>" target="_blank">
                                                <?php if (!empty($page['page_icon'])): ?>
                                                    <i class="fas <?php echo htmlspecialchars($page['page_icon']); ?>"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-file-alt"></i>
                                                <?php endif; ?>
                                                <span><?php echo htmlspecialchars($page['page_desc']); ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php 
            endforeach; 
            
            if ($setorCount === 0): 
            ?>
                <li style="text-align: center; color: #888; padding: 20px;">
                    <i class="fas fa-info-circle" style="font-size: 40px; color: #fdb525;"></i>
                    <p style="margin-top: 10px;">Nenhum menu disponível</p>
                    <p style="font-size: 12px;">Total de páginas: <?php echo count($pages); ?></p>
                </li>
            <?php endif; ?>
        </ul>
        
        <div style="margin-top: auto; padding: 20px 0; width: 100%; border-top: 1px solid #333; text-align: center; color: #888; font-size: 12px;">
            <i class="fas fa-copyright"></i> NOROAÇO - v1.0
        </div>
    </div>

    <div class="content-wrapper" id="contentWrapper">
        <!-- Aqui vai o conteúdo principal da sua página -->
        <?php
        // Seu conteúdo principal aqui
        ?>
    </div>

    <script>
        // Controle dos dropdowns
        let activeDropdown = null;
        let hideTimeouts = {};
        let currentMousePosition = { x: 0, y: 0 };

        // Rastrear posição do mouse globalmente
        document.addEventListener('mousemove', function(e) {
            currentMousePosition.x = e.clientX;
            currentMousePosition.y = e.clientY;
        });

        // Função para posicionar o dropdown ao lado do ícone
        function positionDropdownNearIcon(setorId, iconElement) {
            const dropdown = document.getElementById(`dropdown-${setorId}`);
            if (!dropdown || !iconElement) return;
            
            const rect = iconElement.getBoundingClientRect();
            
            // Posicionar à direita do ícone
            let left = rect.right + 5;
            let top = rect.top;
            
            // Ajustar para não sair da tela
            const dropdownWidth = 280; // Largura máxima
            const dropdownHeight = dropdown.offsetHeight;
            
            // Verificar limite direito
            if (left + dropdownWidth > window.innerWidth) {
                left = rect.left - dropdownWidth - 5;
            }
            
            // Verificar limite inferior
            if (top + dropdownHeight > window.innerHeight) {
                top = window.innerHeight - dropdownHeight - 5;
            }
            
            // Verificar limite superior
            if (top < 5) {
                top = 5;
            }
            
            dropdown.style.left = left + 'px';
            dropdown.style.top = top + 'px';
        }

        // Função para atualizar posição do dropdown em tempo real
        window.updateDropdownPosition = function(setorId, event) {
            const dropdown = document.getElementById(`dropdown-${setorId}`);
            if (!dropdown || !dropdown.classList.contains('show')) return;
            
            const iconElement = event.currentTarget;
            positionDropdownNearIcon(setorId, iconElement);
        };

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
                    // Verificar se o mouse está sobre o dropdown
                    const dropdownRect = dropdown.getBoundingClientRect();
                    if (currentMousePosition.x >= dropdownRect.left && 
                        currentMousePosition.x <= dropdownRect.right && 
                        currentMousePosition.y >= dropdownRect.top && 
                        currentMousePosition.y <= dropdownRect.bottom) {
                        // Mouse está sobre o dropdown, não esconder
                        hidePagesDropdown(setorId); // Reiniciar timer
                        return;
                    }
                    
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
            // Para cada dropdown, adicionar eventos de mouseenter/mouseleave
            <?php foreach ($setores as $setor): 
                $setorId = $setor['setor_id'];
                // Verificar se o setor tem páginas
                $temPagina = false;
                foreach ($submenus as $submenu) {
                    if (!empty($pagesBySetorAndSubmenu[$setorId][$submenu['submenu_id']])) {
                        $temPagina = true;
                        break;
                    }
                }
                if (!$temPagina) continue;
            ?>
                (function() {
                    const dropdown = document.getElementById('dropdown-<?php echo $setorId; ?>');
                    if (dropdown) {
                        dropdown.addEventListener('mouseenter', function() {
                            // Cancelar o timeout de hide quando o mouse entra no dropdown
                            if (hideTimeouts[<?php echo $setorId; ?>]) {
                                clearTimeout(hideTimeouts[<?php echo $setorId; ?>]);
                                delete hideTimeouts[<?php echo $setorId; ?>];
                            }
                        });
                        
                        dropdown.addEventListener('mouseleave', function(e) {
                            // Esconder quando o mouse sai do dropdown
                            window.hidePagesDropdown(<?php echo $setorId; ?>);
                        });
                        
                        // Atualizar posição se o mouse se mover dentro do dropdown
                        dropdown.addEventListener('mousemove', function(e) {
                            // Não fazer nada, apenas manter visível
                        });
                    }
                })();
            <?php endforeach; ?>
        });

        // Função para alternar submenus
        window.toggleSubmenu = function(element) {
            const openMenus = document.querySelectorAll('.treeview-Vertical.active');
            openMenus.forEach(menu => {
                if (menu !== element) {
                    menu.classList.remove('active');
                }
            });
            
            element.classList.toggle('active');
        };
        
        document.querySelectorAll('.treeview-Vertical-menu a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        });

        // Função para atualizar o estado do menu
        function updateMenuState(collapsed) {
            const menuVertical = document.getElementById('menuVertical');
            const contentWrapper = document.getElementById('contentWrapper');
            const menuIconsBar = document.getElementById('menuIconsBar');
            
            if (collapsed) {
                menuVertical.classList.add('menu-collapsed');
                contentWrapper.classList.add('menu-collapsed');
                menuIconsBar.classList.add('show');
            } else {
                menuVertical.classList.remove('menu-collapsed');
                contentWrapper.classList.remove('menu-collapsed');
                menuIconsBar.classList.remove('show');
                
                // Esconder todos os dropdowns quando o menu é expandido
                document.querySelectorAll('.pages-dropdown').forEach(d => d.classList.remove('show'));
                activeDropdown = null;
            }
        }

        // Função para rolar até o setor clicado na barrinha
        function scrollToSetor(setorId) {
            const setorElement = document.querySelector(`.sidebar-menu-Vertical li[data-setor-id="${setorId}"]`);
            if (setorElement) {
                const menuVertical = document.getElementById('menuVertical');
                const offsetTop = setorElement.offsetTop;
                menuVertical.scrollTo({
                    top: offsetTop - 20,
                    behavior: 'smooth'
                });
                
                // Destacar o setor clicado na barrinha
                document.querySelectorAll('.setor-icon-item').forEach(item => {
                    item.classList.remove('active');
                });
                document.querySelector(`.setor-icon-item[data-setor-id="${setorId}"]`).classList.add('active');
            }
        }

        // Adicionar evento de clique nos ícones da barrinha
        document.addEventListener('DOMContentLoaded', function() {
            const iconItems = document.querySelectorAll('.setor-icon-item');
            iconItems.forEach(item => {
                item.addEventListener('click', function() {
                    const setorId = this.getAttribute('data-setor-id');
                    scrollToSetor(setorId);
                    
                    // Esconder dropdown quando clicar
                    if (activeDropdown) {
                        const oldDropdown = document.getElementById(`dropdown-${activeDropdown}`);
                        if (oldDropdown) {
                            oldDropdown.classList.remove('show');
                        }
                        activeDropdown = null;
                    }
                });
            });
        });

        // Ouvir o evento personalizado do header
        window.addEventListener('menuToggle', function(e) {
            updateMenuState(e.detail.collapsed);
        });

        // Verificar estado salvo ao carregar
        document.addEventListener('DOMContentLoaded', function() {
            const menuCollapsed = localStorage.getItem('menuCollapsed') === 'true';
            updateMenuState(menuCollapsed);
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