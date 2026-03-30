<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('max_execution_time', 120);

// ============================================================================
// FUNÇÃO PARA CARREGAR TCPDF - VERSÃO CORRIGIDA
// ============================================================================
function carregarTCPDF()
{
    // Primeiro tenta carregar via Composer (autoload)
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';

        // Verifica se a classe TCPDF foi carregada
        if (class_exists('TCPDF')) {
            return true;
        }
    }

    // Lista de possíveis caminhos para o TCPDF
    $tcpdf_paths = [
        __DIR__ . '/tcpdf/tcpdf.php',
        __DIR__ . '/TCPDF/tcpdf.php',
        __DIR__ . '/includes/tcpdf/tcpdf.php',
        __DIR__ . '/lib/tcpdf/tcpdf.php',
        __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php'
    ];

    foreach ($tcpdf_paths as $path) {
        if (file_exists($path)) {
            require_once($path);

            // Verifica se a classe foi carregada
            if (class_exists('TCPDF')) {
                return true;
            }
        }
    }

    return false;
}

// ============================================================================
// CARREGA TCPDF ANTES DE TUDO
// ============================================================================
if (!carregarTCPDF()) {
    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro - TCPDF não encontrado</title>
        <style>
            body {
                font-family: Arial;
                margin: 50px;
                background: #f8f9fa;
            }

            .error {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }

            h1 {
                color: #dc3545;
            }

            .message {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }

            code {
                background: #f4f4f4;
                padding: 2px 5px;
                border-radius: 3px;
            }
        </style>
    </head>

    <body>
        <div class="error">
            <h1>TCPDF não encontrado</h1>
            <div class="message">
                <strong>Erro:</strong> A biblioteca TCPDF não foi encontrada no sistema.<br><br>
                <strong>Soluções:</strong>
                <ul>
                    <li>Execute: <code>composer require tecnickcom/tcpdf</code> na pasta do projeto</li>
                    <li>Ou baixe manualmente de: <a href="https://github.com/tecnickcom/tcpdf" target="_blank">https://github.com/tecnickcom/tcpdf</a></li>
                </ul>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn">← Voltar</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}

// ============================================================================
// CLASSE PDF PERSONALIZADA COM RODAPÉ EM TODAS AS PÁGINAS
// ============================================================================
class PDFSimplificadoVertical extends TCPDF
{
    /**
     * Personaliza o rodapé para aparecer em todas as páginas
     * IGUAL AO PDFPersonalizado
     */
    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->SetY($this->GetY() + 2);
        $this->Cell(0, 5, '' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(0, 5, 'Emissão: ' . date('d/m/Y H:i:s'), 0, 0, 'L');
    }
}

// ============================================================================
// CLASSE GERADORA DO PDF SIMPLIFICADO COM CAMPOS VERTICAIS
// ============================================================================
class PDFSimplificadoVerticalGenerator
{
    private static $pdf;
    private static $dados;
    private static $y_position = 0;

    // Altura estimada para cada card (usado para verificar se cabe na página)
    private static $alturasCard = [
        'cabecalho' => 30,
        'resultado' => 70,
        'mensagens' => 30,
        'receita' => 95,
        'spc' => 95,
        'dividas' => 75,
        'processos' => 60,
        'profissionais' => 60,
        'relacionamentos' => 60,
        'protestos' => 150
    ];

    // Para controlar o formato das datas baseado no campo
    private static $campo_atual = '';

    // Constantes para layout
    const LARGURA_ROTULO = 55;
    const LARGURA_VALOR = 125; // 180 - 55
    const MARGEM_ESQUERDA = 15;
    const MARGEM_DIREITA = 15;
    const LARGURA_TOTAL = 180; // 210 - 15 - 15
    const ALTURA_LINHA_PADRAO = 6;

    /**
     * VERIFICA SE O CARD INTEIRO CABE NA PÁGINA ATUAL
     * Se não couber, pula para próxima página
     */
    private static function verificarCardCabecalho()
    {
        $alturaNecessaria = self::$alturasCard['cabecalho'];
        $posicaoAtual = self::$pdf->GetY();
        $alturaPagina = self::$pdf->getPageHeight();
        $margemInferior = 45; // Aumentado para respeitar o rodapé

        if ($posicaoAtual + $alturaNecessaria > $alturaPagina - $margemInferior) {
            self::$pdf->AddPage();
            self::$y_position = 20;
            self::$pdf->SetY(self::$y_position);
        } else {
            self::$y_position = $posicaoAtual;
        }
    }

    /**
     * Função auxiliar para parsear string de endereço
     */
    private static function parsearEnderecoString($enderecoStr)
    {
        if (!$enderecoStr || $enderecoStr === '-') {
            return null;
        }

        // Exemplo: "Al Roberto Sabag , Jardim Nova Bariri - Bariri - Sp | CEP: 17250-000"
        $endereco = [
            'logradouro' => '-',
            'numero' => '-',
            'bairro' => '-',
            'cidade' => '-',
            'estado' => '-',
            'cep' => '-',
            'complemento' => '-'
        ];

        // Extrair CEP
        if (preg_match('/CEP:\s*(\d{5}-?\d{3})/', $enderecoStr, $matches)) {
            $endereco['cep'] = $matches[1];
            $enderecoStr = str_replace($matches[0], '', $enderecoStr);
        }

        // Separar cidade/estado e o resto
        $partes = explode('|', $enderecoStr);
        $enderecoPrincipal = trim($partes[0]);

        // Tentar extrair número
        if (preg_match('/(.+?),\s*(\d+)/', $enderecoPrincipal, $matches)) {
            $endereco['logradouro'] = trim($matches[1]);
            $endereco['numero'] = $matches[2];
            $resto = str_replace($matches[0], '', $enderecoPrincipal);
        } else {
            $endereco['logradouro'] = $enderecoPrincipal;
            $resto = '';
        }

        // Extrair bairro, cidade, estado
        if (preg_match('/-\s*([^-]+)-\s*([^-]+)-\s*([A-Z]{2})/', $resto, $matches)) {
            $endereco['bairro'] = trim($matches[1]);
            $endereco['cidade'] = trim($matches[2]);
            $endereco['estado'] = trim($matches[3]);
        }

        return $endereco;
    }

    /**
     * Função para formatar CEP
     */
    private static function formatarCEP($cep)
    {
        $cep = preg_replace('/[^0-9]/', '', $cep);
        if (strlen($cep) === 8) {
            return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
        }
        return $cep;
    }

    private static function formatarSimNao($value)
    {
        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        // Handle string values like 'true', 'false', '1', '0', etc.
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['true', '1', 'sim', 's', 'yes', 'y'])) {
                return 'Sim';
            }
            if (in_array($value, ['false', '0', 'não', 'nao', 'n', 'no'])) {
                return 'Não';
            }
        }

        // Handle numeric values
        if (is_numeric($value)) {
            return $value > 0 ? 'Sim' : 'Não';
        }

        // Default fallback
        return 'Não';
    }


    private static function verificarCard($tipoCard)
    {
        $alturaNecessaria = isset(self::$alturasCard[$tipoCard]) ? self::$alturasCard[$tipoCard] : 50;
        $posicaoAtual = self::$pdf->GetY();
        $alturaPagina = self::$pdf->getPageHeight();
        $margemInferior = 45; // Aumentado para respeitar o rodapé

        // Se não couber o card inteiro, vai para nova página
        if ($posicaoAtual + $alturaNecessaria > $alturaPagina - $margemInferior) {
            self::$pdf->AddPage();
            self::$y_position = 20;
            self::$pdf->SetY(self::$y_position);
            return false;
        }

        self::$y_position = $posicaoAtual;
        return true;
    }

    /**
     * FUNÇÃO AUXILIAR PARA VERIFICAR ESPAÇO NA PÁGINA
     */
    private static function verificarEspacoPagina($espaco_necessario)
    {
        $espaco_restante = self::$pdf->getPageHeight() - self::$pdf->GetY() - 30;

        if ($espaco_restante < $espaco_necessario) {
            self::$pdf->AddPage();
            self::$y_position = self::$pdf->GetY();
        }
    }

    /**
     * EXTRAI VALOR SEGURO OU RETORNA TRAÇO (-)
     */
    private static function getValueOrDash($data, $keys, $default = '-')
    {
        if (!is_array($data)) {
            return $default;
        }

        if (is_string($keys)) {
            return isset($data[$keys]) && $data[$keys] !== '' && $data[$keys] !== null
                ? $data[$keys]
                : $default;
        }

        $current = $data;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || $current[$key] === '' || $current[$key] === null) {
                return $default;
            }
            $current = $current[$key];
        }

        return $current;
    }

    /**
     * FORMATA DATA - MODIFICADO PARA DD-MM-YYYY EXCETO DATAS ESPECÍFICAS
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function formatarData($dataStr, $campo = null)
    {
        if (!$dataStr || $dataStr === '-' || $dataStr === 'null' || $dataStr === '') {
            return '-';
        }

        try {
            $campos_com_hora = ['Data Criação', 'Data Atualização'];

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dataStr)) {
                $date = new DateTime($dataStr);

                if ($campo && in_array($campo, $campos_com_hora)) {
                    return $date->format('d/m/Y H:i:s');
                }

                return $date->format('d/m/Y');
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $dataStr)) {
                if ($campo && in_array($campo, $campos_com_hora)) {
                    return $dataStr;
                }

                $partes = explode('/', $dataStr);
                if (count($partes) == 3) {
                    return $partes[0] . '/' . $partes[1] . '/' . $partes[2];
                }
            }
        } catch (Exception $e) {
            return '-';
        }

        return $dataStr;
    }

    /**
     * CRIA TABELA DINÂMICA COM QUEBRA DE LINHA AUTOMÁTICA
     * Adaptada do limite_cred_pdf_detalhado.php
     */
    private static function criarTabelaDinamica($cabecalhos, $dados, $larguras = [])
    {
        if (empty($dados)) {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, 'Nenhum registro encontrado', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
            return;
        }

        // Calcular larguras se não fornecidas
        if (empty($larguras)) {
            $numColunas = count($cabecalhos);
            $larguraColuna = self::LARGURA_TOTAL / $numColunas;
            $larguras = array_fill(0, $numColunas, $larguraColuna);
        }

        $x_inicial = self::$pdf->GetX();
        $y_inicial = self::$pdf->GetY();

        // Verificar espaço para o cabeçalho + pelo menos uma linha
        $espaco_necessario = 15; // altura aproximada do cabeçalho + 1 linha
        if (self::$pdf->GetY() + $espaco_necessario > self::$pdf->getPageHeight() - 30) {
            self::$pdf->AddPage();
            $x_inicial = self::$pdf->GetX();
            $y_inicial = self::$pdf->GetY();
        }

        // Cabeçalho
        self::$pdf->SetFont('helvetica', 'B', 8);
        self::$pdf->SetFillColor(220, 220, 220);

        foreach ($cabecalhos as $i => $cabecalho) {
            self::$pdf->Cell($larguras[$i], 7, $cabecalho, 1, 0, 'C', true);
        }
        self::$pdf->Ln();

        $y_atual = self::$pdf->GetY();

        // Processar cada linha de dados
        foreach ($dados as $index => $linhaDados) {
            $fill = ($index % 2 == 0) ? false : true;

            // Calcular altura máxima necessária para esta linha
            $altura_maxima = self::ALTURA_LINHA_PADRAO;
            self::$pdf->SetFont('helvetica', '', 8);

            foreach ($linhaDados as $i => $valor) {
                $valorStr = is_array($valor) ? '-' : (string)$valor;
                if ($valorStr === '' || $valorStr === null) $valorStr = '-';

                // Calcular quantas linhas serão necessárias para este texto
                $largura_disponivel = $larguras[$i] - 2; // desconta padding
                $linhas = self::$pdf->getNumLines($valorStr, $largura_disponivel);

                $altura_necessaria = $linhas * self::ALTURA_LINHA_PADRAO;
                if ($altura_necessaria > $altura_maxima) {
                    $altura_maxima = $altura_necessaria;
                }
            }

            // Verificar se cabe na página
            if ($y_atual + $altura_maxima > self::$pdf->getPageHeight() - 30) {
                self::$pdf->AddPage();
                $y_atual = self::$pdf->GetY();

                // Replicar cabeçalho na nova página
                self::$pdf->SetFont('helvetica', 'B', 8);
                self::$pdf->SetFillColor(220, 220, 220);
                self::$pdf->SetX($x_inicial);

                foreach ($cabecalhos as $i => $cabecalho) {
                    self::$pdf->Cell($larguras[$i], 7, $cabecalho, 1, 0, 'C', true);
                }
                self::$pdf->Ln();
            }

            // Desenhar a linha completa
            self::$pdf->SetXY($x_inicial, $y_atual);

            foreach ($linhaDados as $i => $valor) {
                $valorStr = is_array($valor) ? '-' : (string)$valor;
                if ($valorStr === '' || $valorStr === null) $valorStr = '-';

                $x_coluna = self::$pdf->GetX();
                $y_coluna = self::$pdf->GetY();

                // Desenhar borda da célula
                self::$pdf->Rect($x_coluna, $y_coluna, $larguras[$i], $altura_maxima);

                // Preenchimento se necessário
                if ($fill) {
                    self::$pdf->SetFillColor(245, 245, 245);
                    self::$pdf->Rect($x_coluna, $y_coluna, $larguras[$i], $altura_maxima, 'F');
                }

                // Posicionar para o texto (com padding de 1mm)
                self::$pdf->SetXY($x_coluna + 1, $y_coluna + 1);
                self::$pdf->SetFont('helvetica', '', 8);

                // Escrever o texto com MultiCell para quebra automática
                self::$pdf->MultiCell(
                    $larguras[$i] - 2, // largura com padding
                    self::ALTURA_LINHA_PADRAO,
                    $valorStr,
                    0, // sem borda
                    'L', // alinhamento
                    false, // sem fill
                    0, // sem avanço
                    '', // x
                    '', // y
                    true, // reset height
                    0, // max height
                    false // não manter primeira linha
                );

                // Avançar para próxima coluna
                self::$pdf->SetXY($x_coluna + $larguras[$i], $y_coluna);
            }

            // Avançar para a próxima linha
            $y_atual += $altura_maxima;
            self::$pdf->SetXY($x_inicial, $y_atual);
        }

        self::$y_position = $y_atual + 3;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * FORMATA VALOR MONETÁRIO
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function formatarMoeda($valor)
    {
        if ($valor === null || $valor === '' || $valor === '-') {
            return 'R$ 0,00';
        }

        if ($valor === 0 || $valor === '0' || floatval($valor) == 0) {
            return 'R$ 0,00';
        }

        if (is_string($valor) && strpos($valor, 'R$') !== false) {
            return $valor;
        }

        $valorLimpo = preg_replace('/[^0-9,.-]/', '', $valor);
        $valorLimpo = str_replace(',', '.', $valorLimpo);

        if (is_numeric($valorLimpo)) {
            return 'R$ ' . number_format(floatval($valorLimpo), 2, ',', '.');
        }
        if (is_numeric($valor)) {
            return 'R$ ' . number_format(floatval($valor), 2, ',', '.');
        }

        return 'R$ 0,00';
    }

    /**
     * FORMATA SCORE
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function formatarScore($score)
    {
        if ($score === null || $score === '' || $score === '-') {
            return '-';
        }

        $scoreLimpo = preg_replace('/[^0-9.]/', '', $score);

        if (is_numeric($scoreLimpo)) {
            if (floor($scoreLimpo) == $scoreLimpo) {
                return intval($scoreLimpo) . '/1000';
            }
            return $scoreLimpo . '/1000';
        }

        return $score;
    }

    /**
     * FORMATA CPF/CNPJ
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function formatarDocumento($doc)
    {
        if (!$doc || $doc === '-') {
            return '-';
        }

        $num = preg_replace('/[^0-9]/', '', $doc);

        if (strlen($num) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $num);
        }
        if (strlen($num) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $num);
        }

        return $doc;
    }

    /**
     * FORMATA BOOLEAN
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function formatarBoolean($valor, $trueText = 'Sim', $falseText = 'Não')
    {
        if ($valor === true || $valor === 'true' || $valor === 1 || $valor === '1') {
            return $trueText;
        }
        if ($valor === false || $valor === 'false' || $valor === 0 || $valor === '0') {
            return $falseText;
        }
        return '-';
    }

    /**
     * DECODIFICA JSON COM SEGURANÇA
     */
    private static function safeJsonDecode($jsonString)
    {
        if (!$jsonString || $jsonString === '-' || $jsonString === 'null') {
            return null;
        }

        if (is_array($jsonString)) {
            return $jsonString;
        }

        $decoded = json_decode($jsonString, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * BUSCA RECURSIVA EM ARRAY
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function buscaRecursiva($array, $chave, $default = '-')
    {
        if (!is_array($array)) {
            return $default;
        }

        foreach ($array as $key => $value) {
            if ($key === $chave) {
                return $value;
            }
            if (is_array($value)) {
                $resultado = self::buscaRecursiva($value, $chave, null);
                if ($resultado !== null) {
                    return $resultado;
                }
            }
        }
        return $default;
    }

    /**
     * EXTRAI CAMPO DE JSON DECODIFICADO
     */
    private static function extrairCampoJson($jsonData, $caminho, $default = '-')
    {
        if (!$jsonData || !is_array($jsonData)) {
            return $default;
        }

        $valor = self::getValueOrDash($jsonData, $caminho, null);

        if ($valor !== null) {
            return $valor;
        }

        if (is_array($caminho) && !empty($caminho)) {
            return self::buscaRecursiva($jsonData, end($caminho), $default);
        }

        return $default;
    }

    /**
     * ESCREVE LINHA COM LABEL FIXO
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function escreverLinhaComLabelFixo($rotulo, $valor, $destaque = false, $x_inicial = null)
    {
        self::$campo_atual = $rotulo;

        if ($x_inicial === null) {
            $x_inicial = self::$pdf->GetX();
        }

        $y_inicial = self::$pdf->GetY();

        $espaco_restante = self::$pdf->getPageHeight() - self::$pdf->GetY() - 25;

        if ($espaco_restante < self::ALTURA_LINHA_PADRAO) {
            self::$pdf->AddPage();
            $y_inicial = self::$pdf->GetY();
            self::$y_position = $y_inicial;
        }

        self::$pdf->SetFont('helvetica', $destaque ? 'B' : '', 9);
        $valorStr = is_array($valor) ? '-' : (string)$valor;
        if ($valorStr === '' || $valorStr === null) {
            $valorStr = '-';
        }

        $largura_texto = self::$pdf->GetStringWidth($valorStr);
        $numero_linhas = 1;

        if ($largura_texto > self::LARGURA_VALOR) {
            $palavras = explode(' ', $valorStr);
            $linha_teste = '';
            $numero_linhas = 1;

            foreach ($palavras as $palavra) {
                $teste_linha = $linha_teste ? $linha_teste . ' ' . $palavra : $palavra;
                if (self::$pdf->GetStringWidth($teste_linha) <= self::LARGURA_VALOR) {
                    $linha_teste = $teste_linha;
                } else {
                    $numero_linhas++;
                    $linha_teste = $palavra;
                }
            }
        }

        $altura_total = $numero_linhas * self::ALTURA_LINHA_PADRAO;

        self::$pdf->SetFont('helvetica', 'B', 9);
        self::$pdf->SetXY($x_inicial, $y_inicial);

        self::$pdf->Cell(
            self::LARGURA_ROTULO,
            $altura_total,
            $rotulo . ':',
            0,
            0,
            'L',
            false,
            '',
            0,
            false,
            'T',
            'T'
        );

        self::$pdf->SetFont('helvetica', $destaque ? 'B' : '', 9);
        if ($destaque) {
            self::$pdf->SetTextColor(0, 100, 0);
        }

        self::$pdf->SetXY($x_inicial + self::LARGURA_ROTULO, $y_inicial);

        self::$pdf->MultiCell(
            self::LARGURA_VALOR,
            self::ALTURA_LINHA_PADRAO,
            $valorStr,
            0,
            'L',
            false,
            1,
            '',
            '',
            true,
            0,
            false,
            true,
            0,
            'T'
        );

        self::$pdf->SetTextColor(0, 0, 0);

        self::$y_position = $y_inicial + $altura_total;
        self::$pdf->SetY(self::$y_position);

        self::$campo_atual = '';

        return $altura_total;
    }

    /**
     * FORMATA MENSAGEM DE ERRO
     * (idêntico ao limite_cred_pdf_detalhado.php)
     */
    private static function formatarMensagemErro($mensagem)
    {
        if ($mensagem === '-' || empty($mensagem)) {
            self::$pdf->Cell(0, 6, '-', 0, 1, 'L');
            return;
        }

        self::$pdf->SetFont('helvetica', '', 9);

        if (strpos($mensagem, '|') === false) {
            self::escreverLinhaComLabelFixo('MENSAGEM', $mensagem);
            return;
        }

        $partes = explode('|', $mensagem);
        $x_atual = self::$pdf->GetX();
        $y_inicial = self::$pdf->GetY();

        // Calcular altura total
        $altura_total = 0;
        $alturas_partes = [];

        foreach ($partes as $index => $parte) {
            $parte = trim($parte);
            $largura_texto = self::$pdf->GetStringWidth($parte);

            if ($largura_texto <= self::LARGURA_VALOR) {
                $alturas_partes[$index] = self::ALTURA_LINHA_PADRAO;
            } else {
                $palavras = explode(' ', $parte);
                $linha_teste = '';
                $num_linhas = 1;

                foreach ($palavras as $palavra) {
                    $teste_linha = $linha_teste ? $linha_teste . ' ' . $palavra : $palavra;
                    if (self::$pdf->GetStringWidth($teste_linha) <= self::LARGURA_VALOR) {
                        $linha_teste = $teste_linha;
                    } else {
                        $num_linhas++;
                        $linha_teste = $palavra;
                    }
                }

                $alturas_partes[$index] = $num_linhas * self::ALTURA_LINHA_PADRAO;
            }

            $altura_total += $alturas_partes[$index];
        }

        // Verificar espaço
        $espaco_restante = self::$pdf->getPageHeight() - self::$pdf->GetY() - 25;
        if ($altura_total > $espaco_restante) {
            self::$pdf->AddPage();
            $y_inicial = self::$pdf->GetY();
        }

        // Rótulo "Mensagem" com altura total
        self::$pdf->SetFont('helvetica', 'B', 9);
        self::$pdf->SetXY($x_atual, $y_inicial);
        self::$pdf->Cell(
            self::LARGURA_ROTULO,
            $altura_total,
            'Mensagem:',
            0,
            0,
            'L',
            false,
            '',
            0,
            false,
            'T',
            'T'
        );

        // Desenhar cada parte
        $y_atual = $y_inicial;
        self::$pdf->SetFont('helvetica', '', 9);

        foreach ($partes as $index => $parte) {
            $parte = trim($parte);

            self::$pdf->SetXY($x_atual + self::LARGURA_ROTULO, $y_atual);
            self::$pdf->MultiCell(
                self::LARGURA_VALOR,
                self::ALTURA_LINHA_PADRAO,
                $parte,
                0,
                'L',
                false,
                1
            );

            $y_atual += $alturas_partes[$index];
        }

        self::$y_position = $y_atual;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * CRIA CARD COM TÍTULO - VERIFICA SE CABE INTEIRO NA PÁGINA
     */
    private static function criarCard($titulo, $corFundo = [59, 89, 152], $tipoCard = 'resultado')
    {
        // Verifica se o card inteiro cabe na página atual
        self::verificarCard($tipoCard);

        // Título do card
        self::$pdf->SetFillColor($corFundo[0], $corFundo[1], $corFundo[2]);
        self::$pdf->SetTextColor(255, 255, 255);
        self::$pdf->SetFont('helvetica', 'B', 12);

        self::$pdf->SetY(self::$y_position);
        self::$pdf->Cell(0, 8, '  ' . $titulo, 0, 1, 'L', true);

        self::$y_position = self::$pdf->GetY();


        self::$pdf->SetTextColor(0, 0, 0);
        self::$pdf->SetFont('helvetica', '', 9);

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * ADICIONA LINHA VERTICAL (rótulo em cima, valor embaixo)
     * MODIFICADO: Agora usa escreverLinhaComLabelFixo para formatação consistente
     */
    private static function adicionarLinhaVertical($rotulo, $valor, $destaque = false)
    {
        self::escreverLinhaComLabelFixo($rotulo, $valor, $destaque);
    }

    /**
     * FINALIZA CARD COM ESPAÇAMENTO
     */
    private static function finalizarCard()
    {
        self::$y_position += 5;
        self::$pdf->SetY(self::$y_position);

        // Linha separadora entre cards
        self::$pdf->SetLineWidth(0.2);
        self::$pdf->SetDrawColor(200, 200, 200);
        self::$pdf->Line(15, self::$y_position, 195, self::$y_position);

        self::$y_position += 8;
        self::$pdf->SetY(self::$y_position);
    }

    // ============================================================================
    // CARDS ESPECÍFICOS - TODOS COM CAMPOS VERTICAIS
    // ============================================================================

    private static function adicionarCardCabecalho()
    {
        // Verificação especial para cabeçalho
        self::verificarCardCabecalho();

        $logo_path = __DIR__ . '/imgs/noroaco.png';
        if (file_exists($logo_path)) {
            self::$pdf->Image($logo_path, 15, 14, 12, 0, 'PNG');
        }

        self::$pdf->SetY(15);
        self::$pdf->SetFont('helvetica', 'B', 20);
        self::$pdf->SetTextColor(253, 181, 37);
        self::$pdf->Cell(0, 10, 'RELATÓRIO PESSOA FÍSICA', 0, 1, 'R');

        self::$pdf->SetFont('helvetica', 'B', 14);
        self::$pdf->SetTextColor(100, 100, 100);

        $razao = self::getValueOrDash(self::$dados, ['campos', 'razao']);
        if ($razao === '-') {
            $razao = self::getValueOrDash(self::$dados, 'razao_social');
        }
        self::$pdf->Cell(0, 6, $razao, 0, 1, 'R');

        // Linha separadora
        self::$pdf->SetLineWidth(1);
        self::$pdf->SetDrawColor(253, 181, 37);
        self::$pdf->Line(15, 37, 195, 37);

        self::$y_position = 45;
        self::$pdf->SetY(self::$y_position);
    }

    private static function adicionarCardResultadoAnalise()
    {
        self::criarCard('NEOCREDIT', [253, 181, 37], 'resultado');

        $status = self::getValueOrDash(self::$dados, ['campos', 'status']);
        $classificacao = self::getValueOrDash(self::$dados, ['campos', 'classificacao_risco']);
        $risco = self::getValueOrDash(self::$dados, ['campos', 'risco']);
        $score = self::formatarScore(self::getValueOrDash(self::$dados, ['campos', 'score']));
        $limite = self::formatarMoeda(self::getValueOrDash(self::$dados, ['campos', 'limite_aprovado']));
        $validade = self::formatarData(self::getValueOrDash(self::$dados, ['campos', 'data_validade_limite_credito']), 'Data Validade');

        self::adicionarLinhaVertical('STATUS', $status, in_array(strtolower($status), ['aprovar', 'aprovado']));
        self::adicionarLinhaVertical('CLASSIFICAÇÃO', $classificacao);
        self::adicionarLinhaVertical('RISCO', $risco);
        self::adicionarLinhaVertical('SCORE', $score);
        self::adicionarLinhaVertical('LIMITE SUGERIDO', $limite, $limite !== '-' && $limite !== 'R$ 0,00');
        self::adicionarLinhaVertical('DATA VALIDADE', $validade);

        self::finalizarCard();
    }

    private static function adicionarCardMensagens()
    {
        self::criarCard('MENSAGENS', [52, 73, 94], 'mensagens');

        $msg_erro = self::getValueOrDash(self::$dados, ['campos', 'msg_erro_consulta']);

        if ($msg_erro !== '-') {
            self::formatarMensagemErro($msg_erro);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Sem mensagens -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardDadosReceitaFederal()
    {
        self::criarCard('RECEITA FEDERAL', [41, 128, 185], 'receita');

        $rfJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_rf']);
        $rf = self::safeJsonDecode($rfJson);

        if ($rf) {
            self::adicionarLinhaVertical('NOME', self::getValueOrDash($rf, 'Name', '-'));
            self::adicionarLinhaVertical('IDADE', self::getValueOrDash($rf, 'Age', '-'));

            $sexo = self::getValueOrDash($rf, 'Gender', '-');
            $sexoTexto = $sexo == 'M' ? 'Masculino' : ($sexo == 'F' ? 'Feminino' : $sexo);
            self::adicionarLinhaVertical('SEXO', $sexoTexto);

            self::adicionarLinhaVertical('NACIONALIDADE ', self::getValueOrDash($rf, 'BirthCountry', '-'));
            self::adicionarLinhaVertical('DATA NASCIMENTO', self::formatarData(self::getValueOrDash($rf, 'BirthDate', '-'), 'Data Nascimento'));
            self::adicionarLinhaVertical('CPF', self::formatarDocumento(self::getValueOrDash($rf, 'TaxIdNumber', '-')));
            self::adicionarLinhaVertical('NOME DA MÃE', self::getValueOrDash($rf, 'MotherName', '-'));

            $situacao = self::getValueOrDash($rf, 'TaxIdStatus', '-');
            $obito = self::getValueOrDash($rf, 'HasObitIndication', false);
            $obitoTexto = ($obito === true || $obito === 'true' || $obito === 1) ? 'Sim' : 'Não';

            // Removidos os parâmetros condicionais (terceiro parâmetro)
            self::adicionarLinhaVertical('SITUAÇÃO FISCAL', $situacao);
            self::adicionarLinhaVertical('TITULAR FALECIDO', $obitoTexto);
        } else {
            self::adicionarLinhaVertical('Nome', self::getValueOrDash(self::$dados, ['campos', 'razao']));
            self::adicionarLinhaVertical('CPF', self::formatarDocumento(self::getValueOrDash(self::$dados, ['campos', 'documento'])));
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Demais dados da RF não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }
    private static function adicionarCardDadosSPC()
    {
        self::criarCard('DADOS SPC/SERASA', [231, 76, 60], 'spc');

        $spcJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_spc']);
        $spc = self::safeJsonDecode($spcJson);

        if ($spc) {
            $possuiRestricao = self::extrairCampoJson($spc, ['restricao']);
            $qtdeConsultas = self::extrairCampoJson($spc, ['consulta_realizada', 'resumo', 'quantidade_total']);
            $qtdeSPC = self::extrairCampoJson($spc, ['spc', 'resumo', 'quantidade_total']);
            $qtdeCCF = self::extrairCampoJson($spc, ['ccf', 'resumo', 'quantidade_total']);
            $qtdeCheque = self::extrairCampoJson($spc, ['cheque_consulta_online_srs', 'resumo', 'quantidade_total']);
            $valorRestricoes = self::extrairCampoJson($spc, ['protesto', 'resumo', 'valor_total']);
            $priprotestodata = self::extrairCampoJson($spc, ['protesto', 'resumo', 'data_primeira_ocorrencia']);
            $ultprotestodata = self::extrairCampoJson($spc, ['protesto', 'resumo', 'data_ultima_ocorrencia']);

            // Linhas de resumo
            self::adicionarLinhaVertical('POSSUI PROTESTO', $possuiRestricao === 'true' ? 'Sim' : 'Não');
            self::adicionarLinhaVertical('QTD CONSULTAS', $qtdeConsultas);
            self::adicionarLinhaVertical('QTD SPC', $qtdeSPC);
            self::adicionarLinhaVertical('QTD CCF', $qtdeCCF);
            self::adicionarLinhaVertical('QTD CHEQUE', $qtdeCheque);
            self::adicionarLinhaVertical('VALOR PROTESTO', self::formatarMoeda($valorRestricoes));
            self::adicionarLinhaVertical('PRIMEIRO PROTESTO DATA', self::formatarData($priprotestodata));
            self::adicionarLinhaVertical('ÚLTIMO PROTESTO DATA:', self::formatarData($ultprotestodata));

            // ============================================================================
            // TABELA DE ENDEREÇOS
            // ============================================================================
            self::$y_position += 3;
            self::$pdf->SetY(self::$y_position);

            self::$pdf->SetFont('helvetica', 'B', 10);
            self::$pdf->SetTextColor(231, 76, 60);
            self::$pdf->Cell(0, 6, 'ENDEREÇOS:', 0, 1, 'L');
            self::$pdf->SetTextColor(0, 0, 0);
            self::$y_position = self::$pdf->GetY();
            self::$y_position += 2;
            self::$pdf->SetY(self::$y_position);

            // Coletar todos os endereços disponíveis
            $enderecos = [];

            // 1. Endereço principal do consumidor
            $enderecoPrincipal = self::extrairCampoJson($spc, ['consumidor', 'consumidor_pessoa_fisica', 'endereco']);
            if (is_array($enderecoPrincipal) && !empty($enderecoPrincipal)) {
                $enderecos[] = [
                    'logradouro' => self::getValueOrDash($enderecoPrincipal, 'logradouro', '-'),
                    'numero' => self::getValueOrDash($enderecoPrincipal, 'numero', '-'),
                    'bairro' => self::getValueOrDash($enderecoPrincipal, 'bairro', '-'),
                    'cidade' => self::getValueOrDash($enderecoPrincipal, ['cidade', 'nome'], '-'),
                    'estado' => self::getValueOrDash($enderecoPrincipal, ['cidade', 'estado', 'sigla_uf'], '-'),
                    'cep' => self::getValueOrDash($enderecoPrincipal, 'cep', '-'),
                    'complemento' => self::getValueOrDash($enderecoPrincipal, 'complemento', '-')
                ];
            }

            // 2. Último endereço informado
            $ultimoEndereco = self::extrairCampoJson($spc, ['ultimo_endereco_informado', 'detalhe_ultimo_endereco_informado', 'endereco']);
            if (is_array($ultimoEndereco) && !empty($ultimoEndereco)) {
                $enderecos[] = [
                    'logradouro' => self::getValueOrDash($ultimoEndereco, 'logradouro', '-'),
                    'numero' => self::getValueOrDash($ultimoEndereco, 'numero', '-'),
                    'bairro' => self::getValueOrDash($ultimoEndereco, 'bairro', '-'),
                    'cidade' => self::getValueOrDash($ultimoEndereco, ['cidade', 'nome'], '-'),
                    'estado' => self::getValueOrDash($ultimoEndereco, ['cidade', 'estado', 'sigla_uf'], '-'),
                    'cep' => self::getValueOrDash($ultimoEndereco, 'cep', '-'),
                    'complemento' => '-'
                ];
            }

            // 3. Endereços dos dados adicionais de contato
            $dadosContato = self::extrairCampoJson($spc, ['dados_adicionais_de_contato', 'detalhe_dados_adicionais_de_contato', 0, 'enderecosPF']);
            if (is_array($dadosContato)) {
                foreach ($dadosContato as $enderecoStr) {
                    if ($enderecoStr && $enderecoStr !== '-') {
                        // Tentar parsear o endereço string
                        $enderecoParseado = self::parsearEnderecoString($enderecoStr);
                        if ($enderecoParseado) {
                            $enderecos[] = $enderecoParseado;
                        }
                    }
                }
            }

            // Remover endereços duplicados (baseado no CEP + logradouro + número)
            $enderecosUnicos = [];
            $vistos = [];

            foreach ($enderecos as $end) {
                $chave = $end['cep'] . '|' . $end['logradouro'] . '|' . $end['numero'];
                if (!in_array($chave, $vistos) && $end['logradouro'] !== '-') {
                    $vistos[] = $chave;
                    $enderecosUnicos[] = $end;
                }
            }

            if (!empty($enderecosUnicos)) {
                // Preparar dados da tabela
                $dadosEnderecos = [];

                foreach ($enderecosUnicos as $end) {
                    // Formatar endereço completo
                    $logradouro = $end['logradouro'] !== '-' ? $end['logradouro'] : '';
                    $numero = $end['numero'] !== '-' ? ', ' . $end['numero'] : '';
                    $complemento = ($end['complemento'] ?? '-') !== '-' ? ' - ' . $end['complemento'] : '';
                    $bairro = $end['bairro'] !== '-' ? ' - ' . $end['bairro'] : '';

                    $enderecoCompleto = trim($logradouro . $numero . $complemento . $bairro);
                    if (empty($enderecoCompleto)) $enderecoCompleto = '-';

                    $cidade = $end['cidade'] !== '-' ? $end['cidade'] : '';
                    $estado = $end['estado'] !== '-' ? '/' . $end['estado'] : '';
                    $cidadeUf = trim($cidade . $estado);
                    if (empty($cidadeUf)) $cidadeUf = '-';

                    $cep = $end['cep'] !== '-' ? self::formatarCEP($end['cep']) : '-';

                    $dadosEnderecos[] = [
                        $enderecoCompleto,
                        $cidadeUf,
                        $cep
                    ];
                }

                // Definir larguras das colunas
                $larguras = [
                    'endereco' => 90,
                    'cidade' => 50,
                    'cep' => 30
                ];

                // Ajustar para caber na página
                $total_largura = array_sum($larguras);
                if ($total_largura > self::LARGURA_TOTAL) {
                    $fator = self::LARGURA_TOTAL / $total_largura;
                    foreach ($larguras as $key => $value) {
                        $larguras[$key] = round($value * $fator);
                    }
                }

                // Criar a tabela de endereços
                self::criarTabelaDinamica(
                    ['ENDEREÇO COMPLETO', 'CIDADE/UF', 'CEP'],
                    $dadosEnderecos,
                    array_values($larguras)
                );

                self::$y_position += 3;
                self::$pdf->SetY(self::$y_position);
            } else {
                self::$pdf->SetFont('helvetica', 'I', 8);
                self::$pdf->Cell(0, 5, '- Nenhum endereço disponível -', 0, 1, 'C');
                self::$y_position = self::$pdf->GetY();
            }
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados SPC não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }
    private static function adicionarCardDividasCobranca()
    {
        self::criarCard('DÍVIDAS EM COBRANÇA', [192, 57, 43], 'dividas');

        $indicadorJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_indicador']);
        $indicador = self::safeJsonDecode($indicadorJson);

        if ($indicador) {
            $statusDividas = self::extrairCampoJson($indicador, ['divida', 'statusDividas']);
            $existeCobranca = self::extrairCampoJson($indicador, ['divida', 'existeCobranca']) === true ? 'Sim' : 'Não';
            $quantidade = self::extrairCampoJson($indicador, ['divida', 'quantidadeDividas']);
            $valorTotal = self::formatarMoeda(self::extrairCampoJson($indicador, ['divida', 'valorTotal']));
            $primeiraOcorrencia = self::formatarData(self::extrairCampoJson($indicador, ['divida', 'dataPrimeiraOcorrencia']), 'Primeira Ocorrência');
            $ultimaOcorrencia = self::formatarData(self::extrairCampoJson($indicador, ['divida', 'dataUltimaOcorrencia']), 'Última Ocorrência');

            self::adicionarLinhaVertical('STATUS COBRANÇA', $statusDividas ?: 'SEM COBRANÇA');
            self::adicionarLinhaVertical('EXITES COBRANÇA', $existeCobranca, $existeCobranca === 'Sim');
            self::adicionarLinhaVertical('QTD DÍVIDAS', $quantidade, intval($quantidade) > 0);
            self::adicionarLinhaVertical('VALOR TOTAL', $valorTotal, $valorTotal !== '-' && $valorTotal !== 'R$ 0,00');
            self::adicionarLinhaVertical('PRIMEIRO PROTESTO', $primeiraOcorrencia);
            self::adicionarLinhaVertical('ÚLTIMO PROTESTO', $ultimaOcorrencia);
        } else {
            self::adicionarLinhaVertical('STATUS COBRANÇA', 'SEM COBRANÇA');
            self::adicionarLinhaVertical('VALOR TOTAL', self::formatarMoeda('0'));
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados de cobrança não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardProcessosJudiciais()
    {
        self::criarCard('PROCESSOS JUDICIAIS', [52, 152, 219], 'processos');

        $judicialJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_judicial']);
        $judicial = self::safeJsonDecode($judicialJson);

        if ($judicial) {
            $total = self::extrairCampoJson($judicial, ['TotalLawsuits'], 0);
            $comoAutor = self::extrairCampoJson($judicial, ['TotalLawsuitsAsAuthor'], 0);
            $comoReu = self::extrairCampoJson($judicial, ['TotalLawsuitsAsDefendant'], 0);
            $outras = self::extrairCampoJson($judicial, ['TotalLawsuitsAsOther'], 0);

            self::adicionarLinhaVertical('TOTAL DE PROCESSOS', $total, intval($total) > 0);
            self::adicionarLinhaVertical('COMO AUTOR', $comoAutor);
            self::adicionarLinhaVertical('COMO RÉU', $comoReu, intval($comoReu) > 0);
            self::adicionarLinhaVertical('OUTRAS PARTICIPAÇÕES', $outras);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados judiciais não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardDadosProfissionais()
    {
        self::criarCard('DADOS PROFISSIONAIS', [39, 174, 96], 'profissionais');

        $dpJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_dp']);
        $dp = self::safeJsonDecode($dpJson);

        if ($dp) {
            $totalEmpregos = self::getValueOrDash($dp, 'TotalProfessions', '0');
            $empregosAtivos = self::getValueOrDash($dp, 'TotalActiveProfessions', '0');
            $isEmployed = self::getValueOrDash($dp, 'IsEmployed', false);
            $renda = self::getValueOrDash($dp, 'TotalIncome', '0');

            $possuiEmprego = ($isEmployed === true || $isEmployed === 'true' || $isEmployed === 1) ? 'Sim' : 'Não';

            self::adicionarLinhaVertical('TOTAL EMPREGOS', $totalEmpregos);
            self::adicionarLinhaVertical('EMPREGOS ATIVOS', $empregosAtivos, intval($empregosAtivos) > 0);
            self::adicionarLinhaVertical('POSSUI EMPREGO ATIVO', $possuiEmprego, $possuiEmprego === 'Sim');
            self::adicionarLinhaVertical('RENDA PRESUMIDA', self::formatarMoeda($renda), floatval($renda) > 0);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados profissionais não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardRelacionamentos()
    {
        self::criarCard('RELACIONAMENTOS ECONÔMICOS', [142, 68, 173], 'relacionamentos');

        $relJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_relacionamentos']);
        $rel = self::safeJsonDecode($relJson);

        if ($rel && isset($rel['BusinessRelationships']) && is_array($rel['BusinessRelationships'])) {
            $totalRelacionamentos = count($rel['BusinessRelationships']);

            // Contar sócios (RelationshipType = "OWNERSHIP" ou RelationshipName = "SOCIO")
            $totalSocios = 0;
            $totalFuncionarios = 0;

            foreach ($rel['BusinessRelationships'] as $relacionamento) {
                $tipo = $relacionamento['RelationshipType'] ?? '';
                $nome = $relacionamento['RelationshipName'] ?? '';

                // Considera como sócio se for OWNERSHIP ou se o nome for SOCIO/OWNER
                if ($tipo === 'OWNERSHIP' || $nome === 'SOCIO' || $nome === 'OWNER' || strpos($nome, 'SOCIO') !== false) {
                    $totalSocios++;
                }
                // Considera como funcionário se for EMPLOYMENT
                elseif ($tipo === 'EMPLOYMENT' || $nome === 'FUNCIONARIO' || $nome === 'EMPLOYEE') {
                    $totalFuncionarios++;
                }
            }

            // Totais
            self::adicionarLinhaVertical('TOTAL RELACIONAMENTOS', $totalRelacionamentos);
            self::adicionarLinhaVertical('TOTAL SÓCIOS', $totalSocios);
            self::adicionarLinhaVertical('TOTAL FUNCIONÁRIOS', $totalFuncionarios);

            // Linha em branco antes da tabela
            self::$y_position += 3;
            self::$pdf->SetY(self::$y_position);

            // Título da seção de sócios/administradores
            self::$pdf->SetFont('helvetica', 'B', 10);
            self::$pdf->SetTextColor(0, 0, 0);
            self::$pdf->Cell(0, 6, 'SOCIOS / ADMINISTRADORES:', 0, 1, 'L');
            self::$y_position = self::$pdf->GetY();

            // Preparar dados da tabela - MOSTRAR TODOS OS RELACIONAMENTOS
            $dadosTabela = [];

            foreach ($rel['BusinessRelationships'] as $relacionamento) {
                $documento = self::formatarDocumento($relacionamento['RelatedEntityTaxIdNumber'] ?? '-');
                $nomeRel = $relacionamento['RelatedEntityName'] ?? '-';
                $tipoDoc = $relacionamento['RelatedEntityTaxIdType'] ?? '-';
                $cargo = $relacionamento['RelationshipName'] ?? '-';

                // Formatar Ativo
                $ativo = $relacionamento['IsCurrentlyActive'] ?? false;
                $ativoTexto = self::formatarBoolean($ativo, 'sim', 'não');

                // Formatar Data de Início
                $dataInicio = $relacionamento['RelationshipStartDate'] ?? '-';
                $dataInicioFormatada = self::formatarData($dataInicio, 'Data Início');

                $dadosTabela[] = [
                    $documento,
                    $nomeRel,
                    $tipoDoc,
                    $cargo,
                    $ativoTexto,
                    $dataInicioFormatada
                ];
            }

            // Definir larguras das colunas
            $larguras = [
                'documento' => 35,
                'nome' => 50,
                'tipo_doc' => 20,
                'cargo' => 30,
                'ativo' => 15,
                'data_inicio' => 20
            ];

            // Ajustar para caber na página (total 180 - margens)
            $total_largura = array_sum($larguras);
            if ($total_largura > self::LARGURA_TOTAL) {
                $fator = self::LARGURA_TOTAL / $total_largura;
                foreach ($larguras as $key => $value) {
                    $larguras[$key] = round($value * $fator);
                }
            }

            // Criar a tabela com a nova função dinâmica
            self::criarTabelaDinamica(
                ['DOCUMENTO', 'NOME', 'TIPO', 'CARGO', 'ATIVO', 'INICIO'],
                $dadosTabela,
                array_values($larguras)
            );
        } else {
            // Dados de relacionamentos não disponíveis
            self::adicionarLinhaVertical('TOTAL RELACIONAMENTOS', '0');
            self::adicionarLinhaVertical('TOTAL SÓCIOS', '0');
            self::adicionarLinhaVertical('TOTAL FUNCIONÁRIOS', '0');

            self::$y_position += 3;
            self::$pdf->SetY(self::$y_position);

            self::$pdf->SetFont('helvetica', 'B', 10);
            self::$pdf->Cell(0, 6, 'SOCIOS / ADMINISTRADORES:', 0, 1, 'L');
            self::$y_position = self::$pdf->GetY();

            self::$pdf->SetFont('helvetica', 'I', 8);
            self::$pdf->Cell(0, 5, '- Dados de relacionamentos não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    /**
     * NOVO CARD DE PROTESTOS COM TABELA COMPLETA (CORRIGIDO)
     */
    private static function adicionarCardProtestos()
    {
        self::criarCard('PROTESTO NACIONAL ', [230, 126, 34], 'protestos');

        $protestoJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_protesto']);
        $protesto = self::safeJsonDecode($protestoJson);

        if ($protesto) {
            // Dados principais do protesto
            $totalProtests = self::getValueOrDash($protesto, 'TotalProtests', '0');
            $baseStatus = self::getValueOrDash($protesto, 'BaseStatus', '-');

            // Resumo da lista_protestos
            $listaProtestos = self::getValueOrDash($protesto, 'lista_protestos', []);
            $quantidadeTotal = self::getValueOrDash($listaProtestos, 'quantidade_total_protestos', $totalProtests);
            $valorTotal = self::formatarMoeda(self::getValueOrDash($listaProtestos, 'valor_total', '0'));


            // Linhas de resumo na ordem especificada - TODOS SEM CONDIÇÃO (COR NORMAL)
            self::adicionarLinhaVertical('QUANTIDADE TOTAL', $quantidadeTotal);
            self::adicionarLinhaVertical('VALOR TOTAL', $valorTotal);
            self::adicionarLinhaVertical('MENSAGEM', $baseStatus);

            // Processar os dados dos cartórios (ProtestsByStates)
            $protestsByStates = self::getValueOrDash($protesto, 'ProtestsByStates', []);

            if (!empty($protestsByStates) && is_array($protestsByStates)) {

                // ============================================================================
                // TABELA 1: CARTÓRIOS
                // ============================================================================
                self::$y_position += 5;
                self::$pdf->SetY(self::$y_position);

                self::$pdf->SetFont('helvetica', 'B', 11);
                self::$pdf->SetTextColor(230, 126, 34);
                self::$pdf->Cell(0, 6, 'CARTÓRIOS COM PROTESTOS:', 0, 1, 'L');
                self::$pdf->SetTextColor(0, 0, 0);
                self::$y_position = self::$pdf->GetY();
                self::$y_position += 2;
                self::$pdf->SetY(self::$y_position);

                // Preparar dados da tabela de cartórios
                $dadosCartorios = [];

                foreach ($protestsByStates as $state) {
                    $uf = self::getValueOrDash($state, 'State', '-');
                    $protestsByNotaryOffices = self::getValueOrDash($state, 'ProtestsByNotaryOffices', []);

                    foreach ($protestsByNotaryOffices as $cartorio) {
                        // Extrair campos do cartório
                        $descricao = self::getValueOrDash($cartorio, 'Description', '-');
                        $cidade = self::getValueOrDash($cartorio, 'City', '-');
                        $telefone = self::getValueOrDash($cartorio, 'Phone', '-');
                        $endereco = self::getValueOrDash($cartorio, 'Address', '-');
                        $qtdRegistros = self::getValueOrDash($cartorio, 'TotalProtests', '0');

                        // Data do protesto (buscar do primeiro protesto do cartório)
                        $protests = self::getValueOrDash($cartorio, 'Protests', []);
                        if (!empty($protests) && is_array($protests)) {
                            $primeiroProtesto = $protests[0];
                            $protestDate = self::getValueOrDash($primeiroProtesto, 'ProtestDate', '-');

                            // Formatar data especial para o formato "undefined-undefined-NAO DIVULGADO"
                            if ($protestDate !== '-' && strpos($protestDate, 'undefined') === false) {
                                $dataProtesto = self::formatarData($protestDate, 'Data Protesto');
                            } else {
                                $dataProtesto = '-';
                            }
                        }

                        $dadosCartorios[] = [
                            $descricao,
                            $cidade . '/' . $uf,
                            $telefone,
                            $endereco,
                            $qtdRegistros
                        ];
                    }
                }

                if (!empty($dadosCartorios)) {
                    // Definir larguras das colunas para cartórios
                    $largurasCartorios = [
                        'cartorio' => 55,  // Descrição do cartório
                        'cidade' => 28,      // Cidade/UF
                        'telefone' => 25,    // Telefone
                        'endereco' => 55,    // Endereço
                        'qtd' => 15           // Quantidade de registros
                    ];

                    // Ajustar para caber na página
                    $total_largura = array_sum($largurasCartorios);
                    if ($total_largura > self::LARGURA_TOTAL) {
                        $fator = self::LARGURA_TOTAL / $total_largura;
                        foreach ($largurasCartorios as $key => $value) {
                            $largurasCartorios[$key] = round($value * $fator);
                        }
                    }

                    // Criar a tabela de cartórios
                    self::criarTabelaDinamica(
                        ['CARTÓRIO', 'CIDADE', 'TELEFONE', 'ENDEREÇO', 'QTD'],
                        $dadosCartorios,
                        array_values($largurasCartorios)
                    );
                } else {
                    self::$pdf->SetFont('helvetica', 'I', 8);
                    self::$pdf->Cell(0, 5, '- Nenhum cartório encontrado -', 0, 1, 'C');
                    self::$y_position = self::$pdf->GetY();
                }

                // ============================================================================
                // TABELA 2: REGISTROS (Protestos Detalhados)
                // ============================================================================
                self::$y_position += 8;
                self::$pdf->SetY(self::$y_position);

                self::$pdf->SetFont('helvetica', 'B', 11);
                self::$pdf->SetTextColor(230, 126, 34);
                self::$pdf->Cell(0, 6, 'REGISTROS DE PROTESTO:', 0, 1, 'L');
                self::$pdf->SetTextColor(0, 0, 0);
                self::$y_position = self::$pdf->GetY();
                self::$y_position += 2;
                self::$pdf->SetY(self::$y_position);

                // Preparar dados da tabela de registros
                $dadosRegistros = [];

                foreach ($protestsByStates as $state) {
                    $protestsByNotaryOffices = self::getValueOrDash($state, 'ProtestsByNotaryOffices', []);

                    foreach ($protestsByNotaryOffices as $cartorio) {
                        $protests = self::getValueOrDash($cartorio, 'Protests', []);

                        foreach ($protests as $protestoDetalhe) {
                            // Extrair campos do registro
                            $valor = self::formatarMoeda(self::getValueOrDash($protestoDetalhe, 'ProtestValue', '0'));
                            $temAnuencia = self::formatarSimNao(self::getValueOrDash($protestoDetalhe, 'TemAnuencia', false));
                            $custas = self::formatarMoeda(self::getValueOrDash($protestoDetalhe, 'Custas', '0'));
                            $protestDate = self::getValueOrDash($protestoDetalhe, 'ProtestDate', '-');

                            // Verificar se a data contém "NAO DIVULGADO" ou "undefined"
                            if ($protestDate !== '-' && strpos($protestDate, 'undefined') === false && strpos($protestDate, 'NAO DIVULGADO') === false) {
                                $protestDate = self::formatarData($protestDate, 'Data Protesto');
                            } else {
                                $protestDate = 'NÃO DIVULGADO';
                            }

                            $dadosRegistros[] = [
                                $protestDate,
                                $valor,
                                $temAnuencia,
                                $custas
                            ];
                        }
                    }
                }

                if (!empty($dadosRegistros)) {
                    // Definir larguras das colunas para registros
                    $largurasRegistros = [
                        'data_protesto' => 38,  // Data do protesto
                        'valor' => 38,            // Valor
                        'anuencia' => 32,         // Tem anuência
                        'custas' => 35
                    ];

                    // Ajustar para caber na página
                    $total_largura = array_sum($largurasRegistros);
                    if ($total_largura > self::LARGURA_TOTAL) {
                        $fator = self::LARGURA_TOTAL / $total_largura;
                        foreach ($largurasRegistros as $key => $value) {
                            $largurasRegistros[$key] = round($value * $fator);
                        }
                    }

                    // Criar a tabela de registros
                    self::criarTabelaDinamica(
                        ['DATA PROTESTO', 'VALOR', 'ANUÊNCIA', 'CUSTAS'],
                        $dadosRegistros,
                        array_values($largurasRegistros)
                    );
                } else {
                    self::$pdf->SetFont('helvetica', 'I', 8);
                    self::$pdf->Cell(0, 5, '- Nenhum registro de protesto encontrado -', 0, 1, 'C');
                    self::$y_position = self::$pdf->GetY();
                }
            } else {
                self::$pdf->SetFont('helvetica', 'I', 9);
                self::$pdf->Cell(0, 6, '- Dados detalhados de protestos não disponíveis -', 0, 1, 'C');
                self::$y_position = self::$pdf->GetY();
            }
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados de protestos não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }
    /**
     * MÉTODO PRINCIPAL - GERA O PDF SIMPLIFICADO COM CAMPOS VERTICAIS
     */
    public static function gerarPDFSimplificado($dados)
    {
        try {
            self::$dados = $dados;

            // Usar a classe personalizada com rodapé automático
            self::$pdf = new PDFSimplificadoVertical('P', 'mm', 'A4', true, 'UTF-8', false);

            self::$pdf->SetCreator('NOROAÇO');
            self::$pdf->SetAuthor('NOROAÇO - Sistema de Crédito');
            self::$pdf->SetTitle('Relatório Simplificado de Análise de Crédito');
            self::$pdf->SetSubject('Relatório Resumido - Formato Vertical');

            // IMPORTANTE: Habilitar o rodapé
            self::$pdf->setPrintHeader(false);
            self::$pdf->setPrintFooter(true); // Agora o rodapé será gerado automaticamente

            self::$pdf->SetMargins(15, 15, 15);
            self::$pdf->SetAutoPageBreak(true, 30);

            self::$pdf->AddPage();

            // Gerar todos os cards (cada um verifica se cabe inteiro na página)
            self::adicionarCardCabecalho();
            self::adicionarCardResultadoAnalise();
            self::adicionarCardMensagens();
            self::adicionarCardDadosReceitaFederal();
            self::adicionarCardDadosSPC();
            self::adicionarCardProtestos();
            self::adicionarCardDividasCobranca();
            self::adicionarCardProcessosJudiciais();
            self::adicionarCardDadosProfissionais();
            self::adicionarCardRelacionamentos();

            return self::$pdf->Output('', 'S');
        } catch (Exception $e) {
            throw new Exception("Erro na geração do PDF simplificado: " . $e->getMessage());
        }
    }
}

// ============================================================================
// EXECUÇÃO PRINCIPAL
// ============================================================================

try {
    // Obter dados
    $dados_json = '';

    if (isset($_POST['dados']) && !empty($_POST['dados'])) {
        $dados_json = $_POST['dados'];
    } elseif (isset($_GET['dados']) && !empty($_GET['dados'])) {
        $dados_json = $_GET['dados'];
    } else {
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $dados_json = $input;
        }
    }

    if (empty($dados_json)) {
        throw new Exception("Nenhum dado fornecido para gerar o PDF simplificado");
    }

    // Decodificar
    if (strpos($dados_json, '%') !== false) {
        $dados_json = urldecode($dados_json);
    }

    $dados = json_decode($dados_json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar JSON: " . json_last_error_msg());
    }

    // Gerar PDF
    $pdf_content = PDFSimplificadoVerticalGenerator::gerarPDFSimplificado($dados);

    // Nome do arquivo
    $razao = isset($dados['campos']['razao']) ?
        preg_replace('/[^a-zA-Z0-9]/', '_', substr($dados['campos']['razao'], 0, 30)) : 'ANALISE';
    $filename = 'Análise PF - ' . $razao . '.pdf';

    // Headers
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf_content;
    exit;
} catch (Exception $e) {
    error_log("ERRO PDF SIMPLIFICADO: " . $e->getMessage());

    header('Content-Type: text/html; charset=utf-8');
?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Erro no PDF Simplificado</title>
        <style>
            body {
                font-family: Arial;
                margin: 50px;
                background: #f8f9fa;
            }

            .error {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            }

            h1 {
                color: #dc3545;
            }

            .message {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }

            .btn {
                display: inline-block;
                padding: 10px 20px;
                background: #007bff;
                color: white;
                text-decoration: none;
                border-radius: 5px;
                margin: 5px;
            }

            .btn:hover {
                background: #0056b3;
            }
        </style>
    </head>

    <body>
        <div class="error">
            <h1>Erro ao Gerar PDF Simplificado</h1>
            <div class="message">
                <strong>Erro:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <div>
                <a href="javascript:history.back()" class="btn">← Voltar</a>
                <a href="limite_cred.php" class="btn">Nova Consulta</a>
            </div>
        </div>
    </body>

    </html>
<?php
    exit;
}
?>