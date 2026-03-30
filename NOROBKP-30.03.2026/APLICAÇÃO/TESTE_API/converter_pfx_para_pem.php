<?php
declare(strict_types=1);

$pfxFile = __DIR__ . '/61112511105f1e34.pfx';
$pfxPassword = 'Noroaco123*';

$pfxContent = file_get_contents($pfxFile);
if ($pfxContent === false) {
    die('Não foi possível ler o arquivo PFX');
}

$certs = [];
if (!openssl_pkcs12_read($pfxContent, $certs, $pfxPassword)) {
    die('Falha ao ler o PFX (senha incorreta ou arquivo inválido)');
}

// Junta certificado + chave privada (formato esperado pelo cURL)
$pem = $certs['cert'] . PHP_EOL . $certs['pkey'];

file_put_contents(__DIR__ . '/certificado.pem', $pem);

echo "certificado.pem gerado com sucesso\n";
