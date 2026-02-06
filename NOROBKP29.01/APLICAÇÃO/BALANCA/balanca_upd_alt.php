<?php
header('Content-Type: application/json; charset=UTF-8');
mb_internal_encoding('UTF-8');

$pdo = new PDO(
    "pgsql:host=192.168.1.209;port=5432;dbname=Intranet",
    "postgres",
    "postgres"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['codigo']) || !isset($_POST['obs'])) {
        $response['message'] = 'Dados incompletos';
        echo json_encode($response);
        exit;
    }

    $codigo = $_POST['codigo'];
    $obs = trim($_POST['obs']);

    try {
        $sql = "UPDATE pesagem SET obs = ? WHERE codigo = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$obs, $codigo]);

        $response['success'] = true;
        $response['message'] = 'Observação atualizada com sucesso';
    } catch (PDOException $e) {
        $response['message'] = 'Erro ao atualizar: ' . $e->getMessage();
    }
} else {
    $response['message'] = 'Método não permitido';
}

echo json_encode($response);
?>