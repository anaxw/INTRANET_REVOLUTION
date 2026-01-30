<?php
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

// Definir fuso horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

// Conexão com o banco de dados
$pdo = new PDO(
    "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
    "postgres",
    "postgres"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'message' => ''];

// Verificar se é uma requisição POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar campos obrigatórios
    if (!isset($_POST['codigo']) || !isset($_POST['peso_final'])) {
        $response['message'] = 'Dados incompletos';
        echo json_encode($response);
        exit;
    }

    $codigo = $_POST['codigo'];
    $peso_final = (float)$_POST['peso_final'];
    $data_hora_final = date('Y-m-d H:i:s'); // Agora em horário de Brasília

    try {
        // Primeiro, buscar o peso inicial e observação
        $sql = "SELECT peso_inicial, obs FROM pesagem WHERE codigo = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$codigo]);
        $pesagem = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pesagem) {
            $peso_inicial = (float)$pesagem['peso_inicial'];
            
            // CORREÇÃO AQUI: Inverter a fórmula
            // Peso líquido = Peso inicial - Peso final
            // Se peso final > peso inicial → resultado negativo (saída)
            // Se peso final < peso inicial → resultado positivo (entrada)
            $peso_liquido = $peso_inicial - $peso_final;

            // Atualizar com todos os dados
            $sql = "UPDATE pesagem 
                    SET peso_final = ?, 
                        peso_liquido = ?, 
                        data_hora_final = ?, 
                        status = 'F' 
                    WHERE codigo = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$peso_final, $peso_liquido, $data_hora_final, $codigo]);

            $response['success'] = true;
            $response['message'] = 'Pesagem finalizada com sucesso';
            $response['peso_liquido'] = $peso_liquido; // Para informação
            
            // Adicionar detalhes para debug
            $response['detalhes'] = [
                'peso_inicial' => $peso_inicial,
                'peso_final' => $peso_final,
                'formula' => 'peso_inicial - peso_final'
            ];
        } else {
            $response['message'] = 'Registro não encontrado';
        }
    } catch (PDOException $e) {
        $response['message'] = 'Erro ao finalizar: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'Método não permitido';
}

echo json_encode($response);
?>