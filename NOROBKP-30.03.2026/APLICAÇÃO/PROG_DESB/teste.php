<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Teste Direto - Sem procedures</h2>";

try {
    $host = '10.10.94.15';
    $database = 'C:\SIC\Arq01\ARQSIST.FDB';
    $username = 'SYSDBA';
    $password = 'masterkey';
    
    $dsn = "firebird:dbname=$host:$database;charset=WIN1252;dialect=3";
    
    echo "Conectando...<br>";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conectado!<br><br>";
    
    // Teste 1: Query simples
    echo "<h3>Teste 1: Query simples</h3>";
    $sql1 = "SELECT FIRST 5 codacessog, nome FROM arqes01 WHERE situ = 'A'";
    $result1 = $pdo->query($sql1)->fetchAll();
    echo "✅ Registros: " . count($result1) . "<br>";
    
    // Teste 2: Procedure com SELECT
    echo "<h3>Teste 2: Procedure PROC_DESB_CHAPAS</h3>";
    $sql2 = "SELECT * FROM PROC_DESB_CHAPAS(215, 2, '01.02.2026', '28.02.2026')";
    echo "SQL: " . htmlspecialchars($sql2) . "<br>";
    
    try {
        $result2 = $pdo->query($sql2)->fetchAll();
        echo "✅ Sucesso! Registros: " . count($result2) . "<br>";
        if (count($result2) > 0) {
            echo "<pre>";
            print_r(array_slice($result2, 0, 2));
            echo "</pre>";
        }
    } catch (PDOException $e) {
        echo "❌ Erro: " . $e->getMessage() . "<br>";
        
        // Teste 3: Tentar com EXECUTE PROCEDURE
        echo "<h3>Teste 3: Tentativa com EXECUTE PROCEDURE</h3>";
        $sql3 = "EXECUTE PROCEDURE PROC_DESB_CHAPAS(215, 2, '01.02.2026', '28.02.2026')";
        echo "SQL: " . htmlspecialchars($sql3) . "<br>";
        
        try {
            $result3 = $pdo->query($sql3)->fetchAll();
            echo "✅ Sucesso! Registros: " . count($result3) . "<br>";
        } catch (PDOException $e2) {
            echo "❌ Erro: " . $e2->getMessage() . "<br>";
        }
    }
    
    // Teste 4: Verificar se a procedure sp_arq_pedido_compra_prod existe
    echo "<h3>Teste 4: Verificando procedure sp_arq_pedido_compra_prod</h3>";
    $sql4 = "SELECT RDB\$PROCEDURE_NAME FROM RDB\$PROCEDURES WHERE RDB\$PROCEDURE_NAME LIKE '%PEDIDO_COMPRA_PROD%'";
    $result4 = $pdo->query($sql4)->fetchAll();
    echo "Procedures encontradas:<br>";
    foreach ($result4 as $row) {
        echo "- " . $row['RDB$PROCEDURE_NAME'] . "<br>";
    }
    
} catch (Exception $e) {
    echo "<h3 style='color:red'>❌ ERRO GERAL:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>