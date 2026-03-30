<?php
// debug.php - Teste direto
$soap = ''; // coloque o SOAP envelope aqui

$ch = curl_init('https://homologacao.nfe.fazenda.sp.gov.br/ws/cadconsultacadastro4.asmx');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $soap,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/soap+xml; charset=utf-8',
        'SOAPAction: "http://www.portalfiscal.inf.br/nfe/wsdl/CadConsultaCadastro4/nfeConsultaCadastro"'
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_VERBOSE => true,
]);

$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

$response = curl_exec($ch);
rewind($verbose);
$verboseLog = stream_get_contents($verbose);

echo "<h2>Verbose Output:</h2>";
echo "<pre>" . htmlspecialchars($verboseLog) . "</pre>";
echo "<h2>Response:</h2>";
echo "<pre>" . htmlspecialchars($response) . "</pre>";