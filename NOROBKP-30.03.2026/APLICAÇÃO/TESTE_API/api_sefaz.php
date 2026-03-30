<?php
// api_sefaz_sp_corrigida.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * ===================== CONFIGURAÇÃO =====================
 */
$certPem = __DIR__ . '/certificado.pem';
if (!file_exists($certPem)) {
    die(json_encode(['erro' => 'Certificado não encontrado: ' . $certPem]));
}

// Verificar se o arquivo tem chave privada
$certContent = file_get_contents($certPem);
if (strpos($certContent, '-----BEGIN PRIVATE KEY-----') === false && 
    strpos($certContent, '-----BEGIN RSA PRIVATE KEY-----') === false) {
    die(json_encode(['erro' => 'Arquivo não contém chave privada. O arquivo .pem deve ter certificado + chave privada.']));
}

/**
 * ===================== PARÂMETROS =====================
 */
$doc = preg_replace('/\D/', '', $_GET['doc'] ?? '45566697828'); // CPF do seu teste
if (!$doc) die(json_encode(['erro' => 'Informe ?doc=CPF_ou_CNPJ']));

$tipo = strlen($doc) === 11 ? 'CPF' : 'CNPJ';
$uf = 'SP'; // SEFAZ São Paulo

/**
 * ===================== XML DA CONSULTA =====================
 */
$xmlConsulta = '<?xml version="1.0" encoding="UTF-8"?>
<ConsCad xmlns="http://www.portalfiscal.inf.br/nfe" versao="2.00">
  <infCons>
    <xServ>CONS-CAD</xServ>
    <UF>' . $uf . '</UF>
    <' . $tipo . '>' . $doc . '</' . $tipo . '>
  </infCons>
</ConsCad>';

// Compactar XML
$xmlConsulta = preg_replace('/>\s+</', '><', $xmlConsulta);
$xmlConsulta = trim($xmlConsulta);

/**
 * ===================== SOAP 1.2 CORRETO =====================
 */
// IMPORTANTE: Usar SOAP 1.2 com namespace correto
$soap = '<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <nfeConsultaCadastro xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/CadConsultaCadastro4">
      <nfeDadosMsg>' . htmlspecialchars($xmlConsulta, ENT_XML1, 'UTF-8') . '</nfeDadosMsg>
    </nfeConsultaCadastro>
  </soap12:Body>
</soap12:Envelope>';

// Versão alternativa com CDATA (às vezes funciona melhor)
$soapAlt = '<?xml version="1.0" encoding="utf-8"?>
<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <nfeConsultaCadastro xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/CadConsultaCadastro4">
      <nfeDadosMsg><![CDATA[' . $xmlConsulta . ']]></nfeDadosMsg>
    </nfeConsultaCadastro>
  </soap12:Body>
</soap12:Envelope>';

/**
 * ===================== CONFIGURAÇÕES CURL =====================
 */
$url = 'https://homologacao.nfe.fazenda.sp.gov.br/ws/cadconsultacadastro4.asmx';

// Testar diferentes combinações
$configuracoes = [
    [
        'nome' => 'SOAP 1.2 com entidades HTML',
        'soap' => $soap,
        'headers' => [
            'Content-Type: application/soap+xml; charset=utf-8',
            'SOAPAction: "http://www.portalfiscal.inf.br/nfe/wsdl/CadConsultaCadastro4/nfeConsultaCadastro"'
        ]
    ],
    [
        'nome' => 'SOAP 1.2 com CDATA',
        'soap' => $soapAlt,
        'headers' => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ""'
        ]
    ],
    [
        'nome' => 'SOAP 1.2 sem SOAPAction',
        'soap' => $soap,
        'headers' => [
            'Content-Type: application/soap+xml; charset=utf-8'
            // Sem SOAPAction
        ]
    ]
];

/**
 * ===================== EXECUTAR CONSULTA =====================
 */
foreach ($configuracoes as $index => $config) {
    echo "Tentativa " . ($index + 1) . ": " . $config['nome'] . "\n";
    
    $ch = curl_init($url);
    
    $headers = $config['headers'];
    $headers[] = 'Content-Length: ' . strlen($config['soap']);
    $headers[] = 'Connection: close';
    $headers[] = 'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; MS Web Services Client Protocol 4.0.30319.42000)';
    
    $opcoesCurl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $config['soap'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSLCERT => $certPem,
        CURLOPT_SSLKEY => $certPem, // Mesmo arquivo para certificado e chave
        CURLOPT_SSLCERTPASSWD => '', // Senha se houver
        CURLOPT_SSL_VERIFYPEER => false, // Desativar temporariamente para testes
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ];
    
    // Se tiver arquivo CA, usar
    $caPem = __DIR__ . '/ca-sefaz.pem';
    if (file_exists($caPem)) {
        $opcoesCurl[CURLOPT_CAINFO] = $caPem;
        $opcoesCurl[CURLOPT_SSL_VERIFYPEER] = true;
        $opcoesCurl[CURLOPT_SSL_VERIFYHOST] = 2;
    }
    
    curl_setopt_array($ch, $opcoesCurl);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    
    curl_close($ch);
    
    // Salvar para análise
    file_put_contents(__DIR__ . "/resposta_tentativa_{$index}.xml", $response);
    
    // Verificar se foi bem-sucedido
    if ($httpCode === 200 && strpos($response, '<retConsCad') !== false) {
        echo "✓ SUCESSO na tentativa " . ($index + 1) . "\n";
        processarResposta($response, $doc, $tipo);
        exit;
    } elseif ($httpCode === 500) {
        // Extrair mensagem de erro
        $erro = 'Erro HTTP 500';
        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/', $response, $match)) {
            $erro = $match[1];
        } elseif (preg_match('/<soap:Text[^>]*>(.*?)<\/soap:Text>/', $response, $match)) {
            $erro = $match[1];
        }
        echo "✗ " . $erro . "\n";
    } else {
        echo "✗ HTTP Code: $httpCode\n";
    }
}

// Se chegou aqui, todas as tentativas falharam
echo json_encode([
    'erro' => 'Todas as tentativas falharam',
    'documento' => $doc,
    'sugestoes' => [
        '1. Verifique se o certificado está válido e contém chave privada',
        '2. Teste com curl manual no terminal',
        '3. Verifique se o CPF/CNPJ existe na SEFAZ SP'
    ]
]);

/**
 * ===================== FUNÇÃO PARA PROCESSAR RESPOSTA =====================
 */
function processarResposta($response, $doc, $tipo) {
    // Extrair conteúdo do retConsCad
    $retConsCad = '';
    
    // Tentar extrair com regex
    if (preg_match('/<retConsCad[^>]*>.*<\/retConsCad>/s', $response, $matches)) {
        $retConsCad = $matches[0];
    } else {
        // Tentar remover namespaces primeiro
        $clean = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $response);
        if (preg_match('/<retConsCad[^>]*>.*<\/retConsCad>/s', $clean, $matches)) {
            $retConsCad = $matches[0];
        } else {
            $retConsCad = $response;
        }
    }
    
    // Carregar XML
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($retConsCad);
    
    if ($xml === false) {
        $errors = libxml_get_errors();
        die(json_encode(['erro' => 'Erro ao parsear XML', 'xml_errors' => $errors]));
    }
    
    $data = json_decode(json_encode($xml), true);
    
    // Verificar estrutura
    if (!isset($data['infCons'])) {
        die(json_encode(['erro' => 'Estrutura de resposta inválida', 'data' => $data]));
    }
    
    $resultado = [
        'success' => true,
        'documento' => $doc,
        'tipo' => $tipo,
        'uf' => 'SP',
        'status' => [
            'codigo' => $data['infCons']['cStat'] ?? '',
            'descricao' => $data['infCons']['xMotivo'] ?? '',
            'ambiente' => 'Homologação'
        ]
    ];
    
    // Adicionar cadastros se existirem
    if (isset($data['infCad'])) {
        $cadastros = is_array($data['infCad']) && isset($data['infCad'][0]) 
            ? $data['infCad'] 
            : [$data['infCad']];
        
        $resultado['cadastros'] = array_map(function($cad) {
            return [
                'ie' => $cad['IE'] ?? null,
                'cnpj' => $cad['CNPJ'] ?? null,
                'cpf' => $cad['CPF'] ?? null,
                'nome' => $cad['xNome'] ?? null,
                'fantasia' => $cad['xFant'] ?? null,
                'situacao' => $cad['cSit'] ?? null,
                'situacao_desc' => $cad['xSit'] ?? null,
                'uf' => $cad['UF'] ?? null,
                'municipio' => $cad['xMun'] ?? null,
                'logradouro' => $cad['xLgr'] ?? null,
                'numero' => $cad['nro'] ?? null,
                'bairro' => $cad['xBairro'] ?? null,
                'cep' => $cad['CEP'] ?? null,
                'telefone' => $cad['fone'] ?? null
            ];
        }, $cadastros);
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}