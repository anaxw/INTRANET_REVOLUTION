<?php
/**
 * Arquivo para obter e retornar o hostname da máquina
 * Pode ser chamado via AJAX ou incluído em outras páginas
 */

// Configuração de erro para desenvolvimento
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Verifica se a classe já existe para evitar conflitos
if (!class_exists('HostnameHelper')) {
    class HostnameHelper {
        /**
         * Obtém o hostname da máquina
         * @return array Retorna array com ip e hostname
         */
        public static function getHostname() {
            // Obtém o IP do cliente
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            // Tenta obter o hostname pelo IP
            $hostname = gethostbyaddr($ip);
            
            // Se não conseguiu ou retornou o próprio IP
            if (empty($hostname) || $hostname === $ip) {
                // Tenta obter o nome do sistema
                $hostname = php_uname('n');
                
                // Se falhou, tenta gethostname()
                if (empty($hostname) || $hostname === false) {
                    $hostname = gethostname();
                }
                
                // Último recurso: usa o SERVER_NAME
                if (empty($hostname)) {
                    $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
                }
            }
            
            // Remove o domínio, mantém apenas o nome do computador
            $hostname = preg_replace('/\..*$/', '', $hostname);
            
            return [
                'ip' => $ip,
                'hostname' => $hostname,
                'full_hostname' => $hostname,
                'data_hora' => date('Y-m-d H:i:s')
            ];
        }
        
        /**
         * Obtém informações detalhadas do sistema
         * @return array
         */
        public static function getSystemInfo() {
            $hostInfo = self::getHostname();
            return [
                'hostname' => $hostInfo['hostname'],
                'ip' => $hostInfo['ip'],
                'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'uname' => php_uname(),
                'current_time' => date('Y-m-d H:i:s'),
                'timezone' => date_default_timezone_get()
            ];
        }
    }
}

// Função de conveniência para manter compatibilidade
if (!function_exists('getHostname')) {
    function getHostname() {
        return HostnameHelper::getHostname();
    }
}

if (!function_exists('getSystemInfo')) {
    function getSystemInfo() {
        return HostnameHelper::getSystemInfo();
    }
}

// ==================== EXECUÇÃO DIRETA ====================
// Verifica se o arquivo está sendo executado diretamente
if (basename($_SERVER['PHP_SELF']) === 'get_hostname.php') {
    
    // Verifica se já não foi executado
    if (!defined('GET_HOSTNAME_EXECUTED')) {
        define('GET_HOSTNAME_EXECUTED', true);
        
        // Verificar se é requisição AJAX
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if ($isAjax || isset($_GET['ajax']) || isset($_POST['ajax'])) {
            // Retorna JSON
            header('Content-Type: application/json');
            header('Cache-Control: no-cache, must-revalidate');
            echo json_encode([
                'success' => true,
                'data' => HostnameHelper::getHostname()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // Retorna página HTML
            $hostInfo = HostnameHelper::getHostname();
            $systemInfo = HostnameHelper::getSystemInfo();
            ?>
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Informações do Hostname</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
                <style>
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }
                    body {
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        margin: 0;
                        padding: 20px;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        min-height: 100vh;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                    }
                    .container {
                        background: white;
                        border-radius: 16px;
                        padding: 30px;
                        box-shadow: 0 20px 40px rgba(0,0,0,0.2);
                        max-width: 700px;
                        width: 100%;
                        animation: fadeIn 0.5s ease;
                    }
                    @keyframes fadeIn {
                        from { opacity: 0; transform: translateY(-20px); }
                        to { opacity: 1; transform: translateY(0); }
                    }
                    h1 {
                        color: #333;
                        border-left: 4px solid #fdb525;
                        padding-left: 15px;
                        margin-bottom: 25px;
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        flex-wrap: wrap;
                        gap: 10px;
                    }
                    .badge {
                        display: inline-block;
                        background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
                        color: #333;
                        padding: 6px 16px;
                        border-radius: 20px;
                        font-size: 14px;
                        font-weight: 600;
                    }
                    .info-card {
                        background: #f8f9fa;
                        border-radius: 12px;
                        padding: 20px;
                        margin-bottom: 20px;
                        border: 1px solid #e9ecef;
                    }
                    .info-card h3 {
                        margin-bottom: 15px;
                        color: #495057;
                        font-size: 16px;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .info-card h3 i {
                        color: #fdb525;
                    }
                    .info-item {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 12px 0;
                        border-bottom: 1px solid #e0e0e0;
                        flex-wrap: wrap;
                        gap: 10px;
                    }
                    .info-item:last-child {
                        border-bottom: none;
                    }
                    .info-label {
                        font-weight: 600;
                        color: #555;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .info-value {
                        color: #333;
                        font-family: 'Courier New', monospace;
                        background: white;
                        padding: 6px 12px;
                        border-radius: 6px;
                        border: 1px solid #dee2e6;
                        word-break: break-all;
                        max-width: 60%;
                        text-align: right;
                    }
                    .footer {
                        text-align: center;
                        margin-top: 20px;
                        padding-top: 20px;
                        border-top: 1px solid #e0e0e0;
                        font-size: 12px;
                        color: #888;
                    }
                    button {
                        background: linear-gradient(135deg, #fdb525 0%, #ffc64d 100%);
                        border: none;
                        padding: 12px 24px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-weight: 600;
                        margin-top: 10px;
                        width: 100%;
                        font-size: 14px;
                        transition: all 0.3s;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 8px;
                    }
                    button:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    }
                    @media (max-width: 600px) {
                        .container {
                            padding: 20px;
                        }
                        .info-item {
                            flex-direction: column;
                            align-items: flex-start;
                        }
                        .info-value {
                            max-width: 100%;
                            text-align: left;
                            width: 100%;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>
                        <span><i class="fas fa-server"></i> Informações do Hostname</span>
                        <span class="badge"><i class="fas fa-desktop"></i> <?php echo htmlspecialchars($hostInfo['hostname']); ?></span>
                    </h1>
                    
                    <div class="info-card">
                        <h3><i class="fas fa-info-circle"></i> Informações Principais</h3>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-tag"></i> Hostname:</span>
                            <span class="info-value"><?php echo htmlspecialchars($hostInfo['hostname']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label"><i class="fas fa-network-wired"></i> IP do Cliente:</span>
                            <span class="info-value"><?php echo htmlspecialchars($hostInfo['ip']); ?></span>
                        </div>
                    </div>
                    <div class="footer">
                        <small><i class="fas fa-code-branch"></i> Para usar via AJAX, chame: <code>get_hostname.php?ajax=true</code></small>
                    </div>
                </div>
                
                <script>
                    function copiarHostname() {
                        const hostname = '<?php echo addslashes($hostInfo['hostname']); ?>';
                        navigator.clipboard.writeText(hostname).then(() => {
                            // Feedback visual
                            const btn = event.target.closest('button');
                            const originalText = btn.innerHTML;
                            btn.innerHTML = '<i class="fas fa-check"></i> Copiado!';
                            setTimeout(() => {
                                btn.innerHTML = originalText;
                            }, 2000);
                        }).catch(() => {
                            prompt('Copie manualmente (Ctrl+C):', hostname);
                        });
                    }
                </script>
            </body>
            </html>
            <?php
            exit;
        }
    }
}
?>