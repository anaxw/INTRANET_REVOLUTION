<?php
// limite_cred_pdf_simplificado_vertical.php - VERSÃO COM CAMPOS VERTICAIS E CARDS INTEIROS
// ============================================================================
// PDF SIMPLIFICADO - RELATÓRIO RESUMIDO DE ANÁLISE
// REGRA: TODOS os campos DEVEM aparecer com TRAÇO (-) se não existirem
// LAYOUT: Todos os campos um embaixo do outro (formato vertical)
// CARDS NÃO QUEBRAM ENTRE PÁGINAS - Se não couber, vai para próxima página
// CORREÇÃO: Rodapé aparece em todas as páginas e TCPDF carregado corretamente
// ============================================================================

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
            body { font-family: Arial; margin: 50px; background: #f8f9fa; }
            .error { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            h1 { color: #dc3545; }
            .message { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0; }
            code { background: #f4f4f4; padding: 2px 5px; border-radius: 3px; }
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
     */
    public function Footer()
    {
        // Posiciona o rodapé
        $this->SetY(-25);
        
        // Linha separadora
        $this->SetLineWidth(0.5);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        $this->Ln(4);
        
        // Texto do rodapé
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(100, 100, 100);
        
        // Data de emissão
        $this->Cell(0, 4, 'Emissão: ' . date('d/m/Y H:i:s'), 0, 1, 'C');
        
        // Número da página (já inclui o total automaticamente)
        $this->Cell(0, 4, 'Página ' . $this->getAliasNumPage() . ' de ' . $this->getAliasNbPages(), 0, 1, 'C');
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
        'protestos' => 60
    ];

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
     * FORMATA DATA OU RETORNA TRAÇO
     */
    private static function formatarData($dataStr)
    {
        if (!$dataStr || $dataStr === '-' || $dataStr === 'null' || $dataStr === '') {
            return '-';
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dataStr)) {
                $date = new DateTime($dataStr);
                return $date->format('d/m/Y H:i:s');
            }
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}/', $dataStr)) {
                return $dataStr;
            }
        } catch (Exception $e) {
            return '-';
        }

        return $dataStr;
    }

    /**
     * FORMATA VALOR MONETÁRIO
     */
    private static function formatarMoeda($valor)
    {
        if ($valor === null || $valor === '' || $valor === '-' || $valor === 0) {
            return '-';
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

        return $valor;
    }

    /**
     * FORMATA CPF/CNPJ
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
     * EXTRAI CAMPO DE JSON DECODIFICADO
     */
    private static function extrairCampoJson($jsonData, $caminho, $default = '-')
    {
        if (!$jsonData || !is_array($jsonData)) {
            return $default;
        }

        return self::getValueOrDash($jsonData, $caminho, $default);
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

        // Fundo do card (apenas uma pequena marcação)
        self::$pdf->SetFillColor(248, 249, 250);
        self::$pdf->Rect(10, self::$y_position, 190, 5, 'F');

        self::$pdf->SetTextColor(0, 0, 0);
        self::$pdf->SetFont('helvetica', '', 9);

        self::$y_position += 3;
        self::$pdf->SetY(self::$y_position);
    }

    /**
     * ADICIONA LINHA VERTICAL (rótulo em cima, valor embaixo)
     */
    private static function adicionarLinhaVertical($rotulo, $valor, $destaque = false)
    {
        // Rótulo
        self::$pdf->SetFont('helvetica', 'B', 9);
        self::$pdf->SetTextColor(80, 80, 80);
        self::$pdf->Cell(55, 5, $rotulo . ':', 0, 1, 'L');
        
        // Valor
        self::$pdf->SetFont('helvetica', $destaque ? 'B' : '', 10);
        if ($destaque) {
            self::$pdf->SetTextColor(0, 100, 0);
        } else {
            self::$pdf->SetTextColor(0, 0, 0);
        }

        $valorFormatado = is_array($valor) ? '-' : $valor;
        if (strlen($valorFormatado) > 80) {
            $valorFormatado = substr($valorFormatado, 0, 77) . '...';
        }

        self::$pdf->MultiCell(0, 5, $valorFormatado, 0, 'L');
        self::$pdf->SetTextColor(0, 0, 0);
        
        // Espaço entre linhas
        self::$pdf->Ln(2);
        
        self::$y_position = self::$pdf->GetY();
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
        self::criarCard('RESULTADO DA ANÁLISE', [253, 181, 37], 'resultado');

        $status = self::getValueOrDash(self::$dados, ['campos', 'status']);
        $classificacao = self::getValueOrDash(self::$dados, ['campos', 'classificacao_risco']);
        $risco = self::getValueOrDash(self::$dados, ['campos', 'risco']);
        $score = self::getValueOrDash(self::$dados, ['campos', 'score']);
        $limite = self::formatarMoeda(self::getValueOrDash(self::$dados, ['campos', 'limite_aprovado']));
        $validade = self::formatarData(self::getValueOrDash(self::$dados, ['campos', 'data_validade_limite_credito']));

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
            self::adicionarLinhaVertical('Mensagem', $msg_erro);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Sem mensagens -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardDadosReceitaFederal()
    {
        self::criarCard('RECEITA FEDERAL (PF)', [41, 128, 185], 'receita');

        $rfJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_rf']);
        $rf = self::safeJsonDecode($rfJson);

        if ($rf) {
            self::adicionarLinhaVertical('Nome', self::getValueOrDash($rf, 'Name', '-'));
            self::adicionarLinhaVertical('Idade', self::getValueOrDash($rf, 'Age', '-'));
            
            $sexo = self::getValueOrDash($rf, 'Gender', '-');
            $sexoTexto = $sexo == 'M' ? 'Masculino' : ($sexo == 'F' ? 'Feminino' : $sexo);
            self::adicionarLinhaVertical('Sexo', $sexoTexto);
            
            self::adicionarLinhaVertical('Nacionalidade', self::getValueOrDash($rf, 'BirthCountry', '-'));
            self::adicionarLinhaVertical('Data Nascimento', self::formatarData(self::getValueOrDash($rf, 'BirthDate', '-')));
            self::adicionarLinhaVertical('CPF', self::formatarDocumento(self::getValueOrDash($rf, 'TaxIdNumber', '-')));
            self::adicionarLinhaVertical('Nome da Mãe', self::getValueOrDash($rf, 'MotherName', '-'));
            
            $situacao = self::getValueOrDash($rf, 'TaxIdStatus', '-');
            $obito = self::getValueOrDash($rf, 'HasObitIndication', false);
            $obitoTexto = ($obito === true || $obito === 'true' || $obito === 1) ? 'Sim' : 'Não';
            
            self::adicionarLinhaVertical('Situação Fiscal', $situacao, strtoupper($situacao) == 'REGULAR');
            self::adicionarLinhaVertical('Titular Falecido', $obitoTexto, $obitoTexto == 'Sim');
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
            $score = self::extrairCampoJson($spc, ['score_pj', 'detalhe_score_pj', 'score']);
            $classeScore = self::extrairCampoJson($spc, ['score_pj', 'detalhe_score_pj', 'classe']);
            $possuiRestricao = self::extrairCampoJson($spc, ['restricao']);
            $qtdeConsultas = self::extrairCampoJson($spc, ['consulta_realizada', 'resumo', 'quantidade_total']);
            $qtdeSPC = self::extrairCampoJson($spc, ['spc', 'resumo', 'quantidade_total']);
            $qtdeCCF = self::extrairCampoJson($spc, ['ccf', 'resumo', 'quantidade_total']);
            $qtdeCheque = self::extrairCampoJson($spc, ['cheque_consulta_online_srs', 'resumo', 'quantidade_total']);
            $valorRestricoes = self::extrairCampoJson($spc, ['spc', 'resumo', 'valor_total']);

            self::adicionarLinhaVertical('Score', $score);
            self::adicionarLinhaVertical('Classe do Score', $classeScore);
            self::adicionarLinhaVertical('Possui Restrição', $possuiRestricao === 'true' ? 'Sim' : 'Não', $possuiRestricao === 'true');
            self::adicionarLinhaVertical('Quantidade de Consultas', $qtdeConsultas);
            self::adicionarLinhaVertical('Quantidade no SPC', $qtdeSPC, intval($qtdeSPC) > 0);
            self::adicionarLinhaVertical('Quantidade CCF', $qtdeCCF, intval($qtdeCCF) > 0);
            self::adicionarLinhaVertical('Quantidade Cheque', $qtdeCheque, intval($qtdeCheque) > 0);
            self::adicionarLinhaVertical('Valor das Restrições', self::formatarMoeda($valorRestricoes), floatval($valorRestricoes) > 0);
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
            $primeiraOcorrencia = self::formatarData(self::extrairCampoJson($indicador, ['divida', 'dataPrimeiraOcorrencia']));
            $ultimaOcorrencia = self::formatarData(self::extrairCampoJson($indicador, ['divida', 'dataUltimaOcorrencia']));

            self::adicionarLinhaVertical('Status da Cobrança', $statusDividas ?: 'SEM COBRANÇA');
            self::adicionarLinhaVertical('Existe Cobrança', $existeCobranca, $existeCobranca === 'Sim');
            self::adicionarLinhaVertical('Quantidade de Dívidas', $quantidade, intval($quantidade) > 0);
            self::adicionarLinhaVertical('Valor Total', $valorTotal, $valorTotal !== '-' && $valorTotal !== 'R$ 0,00');
            self::adicionarLinhaVertical('Primeira Ocorrência', $primeiraOcorrencia);
            self::adicionarLinhaVertical('Última Ocorrência', $ultimaOcorrencia);
        } else {
            self::adicionarLinhaVertical('Status da Cobrança', 'SEM COBRANÇA');
            self::adicionarLinhaVertical('Valor Total', 'R$ 0,00');
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

            self::adicionarLinhaVertical('Total de Processos', $total, intval($total) > 0);
            self::adicionarLinhaVertical('Como Autor', $comoAutor);
            self::adicionarLinhaVertical('Como Réu', $comoReu, intval($comoReu) > 0);
            self::adicionarLinhaVertical('Outras Participações', $outras);
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

            self::adicionarLinhaVertical('Total de Empregos', $totalEmpregos);
            self::adicionarLinhaVertical('Empregos Ativos', $empregosAtivos, intval($empregosAtivos) > 0);
            self::adicionarLinhaVertical('Possui Emprego Ativo', $possuiEmprego, $possuiEmprego === 'Sim');
            self::adicionarLinhaVertical('Renda Presumida', self::formatarMoeda($renda), floatval($renda) > 0);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados profissionais não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardRelacionamentos()
    {
        self::criarCard('RELACIONAMENTOS', [142, 68, 173], 'relacionamentos');

        $relJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_relacionamentos']);
        $rel = self::safeJsonDecode($relJson);

        if ($rel) {
            $qtdEmpresas = self::extrairCampoJson($rel, ['TotalOwnerships'], '0');
            $qtdSocios = self::extrairCampoJson($rel, ['TotalPartners'], '0');
            $qtdRelacionamentos = self::extrairCampoJson($rel, ['TotalRelationships'], '0');
            $familiar = self::extrairCampoJson($rel, ['IsFamilyCompany']) === true ? 'Sim' : 'Não';

            self::adicionarLinhaVertical('Empresas como Sócio', $qtdEmpresas, intval($qtdEmpresas) > 0);
            self::adicionarLinhaVertical('Quantidade de Sócios', $qtdSocios);
            self::adicionarLinhaVertical('Total de Relacionamentos', $qtdRelacionamentos);
            self::adicionarLinhaVertical('Empresa Familiar', $familiar);
        } else {
            self::$pdf->SetFont('helvetica', 'I', 9);
            self::$pdf->Cell(0, 6, '- Dados de relacionamentos não disponíveis -', 0, 1, 'C');
            self::$y_position = self::$pdf->GetY();
        }

        self::finalizarCard();
    }

    private static function adicionarCardProtestos()
    {
        self::criarCard('PROTESTOS', [230, 126, 34], 'protestos');

        $protestoJson = self::getValueOrDash(self::$dados, ['campos', 'resultado_completo_protesto']);
        $protesto = self::safeJsonDecode($protestoJson);

        if ($protesto) {
            $lista = $protesto['lista_protestos'] ?? [];
            $valorTotal = self::formatarMoeda(self::getValueOrDash($lista, 'valor_total', '0'));
            $quantidade = self::getValueOrDash($lista, 'quantidade_total_protestos', '0');
            $dataPrimeira = self::formatarData(self::getValueOrDash($lista, 'data_primeiro_protesto', '-'));
            $dataUltima = self::formatarData(self::getValueOrDash($lista, 'data_ultimo_protesto', '-'));

            self::adicionarLinhaVertical('Valor Total', $valorTotal, $valorTotal !== '-' && $valorTotal !== 'R$ 0,00');
            self::adicionarLinhaVertical('Quantidade', $quantidade, intval($quantidade) > 0);
            self::adicionarLinhaVertical('Primeira Ocorrência', $dataPrimeira);
            self::adicionarLinhaVertical('Última Ocorrência', $dataUltima);
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
            self::adicionarCardDividasCobranca();
            self::adicionarCardProcessosJudiciais();
            self::adicionarCardDadosProfissionais();
            self::adicionarCardRelacionamentos();
            self::adicionarCardProtestos();

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