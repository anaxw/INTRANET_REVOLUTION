<?php
/**
 * Conexão PDO com Firebird - Versão Corrigida
 */

function getConexaoPDO() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $host = '10.10.94.15';
            $database = 'C:\SIC\Arq01\ARQSIST.FDB';
            $username = 'SYSDBA';
            $password = 'masterkey';
            
            // DSN com charset já definido - NÃO precisa de SET NAMES separado
            $dsn = "firebird:dbname=$host:$database;charset=WIN1252;dialect=3";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 300
            ];
            
            $pdo = new PDO($dsn, $username, $password, $options);
            
            // REMOVA estas linhas - elas causam o erro no Firebird
            // $pdo->exec("SET NAMES WIN1252");
            // $pdo->exec("SET SQL DIALECT 3");
            
            // Opcional: Configurar dialeto se necessário (geralmente já está no DSN)
            // O dialeto já foi definido na string de conexão como dialect=3
            
        } catch (PDOException $e) {
            error_log("Erro de conexão: " . $e->getMessage());
            throw new Exception("Erro ao conectar ao banco de dados: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

function consulta_pdo($sql) {
    $pdo = getConexaoPDO();
    
    try {
        $stmt = $pdo->query($sql);
        $result = $stmt->fetchAll();
        return $result;
        
    } catch (PDOException $e) {
        error_log("ERRO PDO: " . $e->getMessage());
        error_log("SQL: " . $sql);
        throw new Exception("Erro ao executar consulta: " . $e->getMessage());
    }
}

function executa_pdo($sql) {
    try {
        $pdo = getConexaoPDO();
        return $pdo->exec($sql);
    } catch (PDOException $e) {
        error_log("Erro na execução: " . $e->getMessage());
        throw new Exception("Erro ao executar comando: " . $e->getMessage());
    }
}
?>