<?php
class TodasConexoes
{
    private static $configuracoes = array(
        'Barra Mansa' => array(
            'host' => '10.10.94.15:c:/SIC_BM/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 15
        ),
        'Botucatu' => array(
            'host' => '10.10.94.15:c:/SIC_Botucatu/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 15
        ),
        'Votuporanga' => array(
            'host' => '10.10.94.15:c:/SIC/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 20
        ),
        'Lins' => array(
            'host' => '10.10.94.15:c:/SIC_Lins/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 20
        ),
        'Rio Preto' => array(
            'host' => '10.10.94.15:c:/SIC_RP/Arq01/ARQSIST.FDB',
            'user' => 'SYSDBA',
            'pass' => 'masterkey',
            'charset' => 'UTF8',
            'timeout' => 15
        )
    );
    
    // Método para conectar a uma unidade específica
    private static function conectar_unidade($unidade_nome)
    {
        if (!isset(self::$configuracoes[$unidade_nome])) {
            throw new Exception("Unidade não configurada: {$unidade_nome}");
        }
        
        $config = self::$configuracoes[$unidade_nome];
        
        // Conectar ao Firebird
        $con = @ibase_connect(
            $config['host'],
            $config['user'],
            $config['pass'],
            $config['charset']
        );
        
        if (!$con) {
            $erro = ibase_errmsg() ?: 'Erro desconhecido ao conectar';
            throw new Exception("Erro de conexão com {$unidade_nome}: {$erro}");
        }
        
        // Configurar timeout
        ibase_timeout($config['timeout'], $config['timeout'], $config['timeout'], $config['timeout'], $config['timeout']);
        
        return $con;
    }
    
    // Método auxiliar para converter objeto em array
    private static function obj2arr($objeto, $apenas_primeiro = false)
    {
        $array = array();
        foreach ($objeto as $chave => $valor) {
            if (is_object($valor)) {
                $array[$chave] = self::obj2arr($valor);
            } else {
                $array[$chave] = $valor;
            }
        }
        
        if ($apenas_primeiro && !empty($array)) {
            return array_values($array)[0];
        }
        
        return $array;
    }
    
    // Gerar formatos de CPF
    private static function gerarFormatosCpf($cpf)
    {
        $formatos = array();
        
        // Formato completo: 000.000.000-00
        $formatos[] = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
        
        // Formato sem pontuação: 00000000000
        $formatos[] = $cpf;
        
        // Formato parcial: 000.000.000-00 (sem máscara)
        $formatos[] = $cpf;
        
        return array_unique($formatos);
    }
    
    // Gerar formatos de CNPJ
    private static function gerarFormatosCnpj($cnpj)
    {
        $formatos = array();
        
        // Formato completo: 00.000.000/0000-00
        $formatos[] = substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12, 2);
        
        // Formato sem pontuação: 00000000000000
        $formatos[] = $cnpj;
        
        // Formato parcial
        $formatos[] = $cnpj;
        
        return array_unique($formatos);
    }
    
    // MÉTODO PRINCIPAL: Atualizar limite por CODIC (DIRETO)
    public static function atualizarLimitePorCodic($unidade, $codic, $limite, $usuario)
    {
        $con = null;
        
        try {
            // Validar CODIC
            if (!is_numeric($codic) || $codic <= 0) {
                throw new Exception("CODIC inválido: {$codic}");
            }
            
            // Validar limite
            if (!is_numeric($limite) || $limite < 0) {
                throw new Exception("Limite inválido: deve ser um número positivo");
            }
            
            if (empty(trim($usuario))) {
                throw new Exception("Usuário inválido");
            }
            
            $con = self::conectar_unidade($unidade);
            
            // Verificar se o cliente existe com este CODIC
            $sql_verifica = "SELECT FIRST 1 c.codic, c.nome FROM arqcad c 
                            WHERE c.codic = {$codic} AND c.tipoc = 'C'";
            
            $consulta = @ibase_query($con, $sql_verifica);
            
            if (!$consulta) {
                $erro = ibase_errmsg() ?: 'Erro desconhecido na consulta';
                throw new Exception("Erro ao verificar cliente: {$erro}");
            }
            
            $objeto = ibase_fetch_object($consulta);
            ibase_free_result($consulta);
            
            if (!$objeto) {
                throw new Exception("Cliente com CODIC {$codic} não encontrado na unidade {$unidade}");
            }
            
            // Realizar UPDATE com transação
            ibase_trans($con);
            
            $sql_update = "UPDATE arqcad c 
                          SET c.lcred = {$limite}, 
                              c.usu_alterou = '{$usuario}'
                          WHERE c.codic = {$codic} 
                          AND c.tipoc = 'C'";
            
            $resultado_update = @ibase_query($con, $sql_update);
            
            if (!$resultado_update) {
                ibase_rollback($con);
                $erro = ibase_errmsg() ?: 'Erro desconhecido no UPDATE';
                throw new Exception("Erro ao atualizar limite: {$erro}");
            }
            
            ibase_commit($con);
            
            return array(
                'success' => true,
                'unidade' => $unidade,
                'codic' => $codic,
                'limite' => $limite,
                'usuario' => $usuario,
                'mensagem' => 'Limite atualizado com sucesso'
            );
            
        } catch (Exception $e) {
            if ($con) {
                @ibase_rollback($con);
            }
            throw $e;
        } finally {
            if ($con) {
                @ibase_close($con);
            }
        }
    }
    
    // MÉTODO ALTERNATIVO: Atualizar limite por documento (CPF/CNPJ)
    public static function atualizarLimiteUnidade($unidade, $documento, $limite, $usuario)
    {
        $con = null;
        
        try {
            $documento_limpo = preg_replace('/[^0-9]/', '', $documento);
            
            if (strlen($documento_limpo) == 11) {
                $campo = 'ncpf';
                $formatos = self::gerarFormatosCpf($documento_limpo);
            } else if (strlen($documento_limpo) == 14) {
                $campo = 'ncgc';
                $formatos = self::gerarFormatosCnpj($documento_limpo);
            } else {
                throw new Exception("Documento inválido: {$documento}");
            }
            
            // Validar dados
            if (!is_numeric($limite) || $limite < 0) {
                throw new Exception("Limite inválido: deve ser um número positivo");
            }
            
            if (empty(trim($usuario))) {
                throw new Exception("Usuário inválido");
            }
            
            $con = self::conectar_unidade($unidade);
            $encontrado = false;
            $codic_encontrado = null;
            $nome_cliente = null;
            
            // Buscar CODIC pelo documento
            foreach ($formatos as $formato) {
                $sql_busca = "SELECT FIRST 1 c.codic, c.nome FROM arqcad c 
                             WHERE c.tipoc = 'C' AND TRIM(c.{$campo}) = '" . trim($formato) . "'";
                
                $consulta = @ibase_query($con, $sql_busca);
                
                if ($consulta && $objeto = ibase_fetch_object($consulta)) {
                    $linha = self::obj2arr($objeto, false);
                    $codic_encontrado = isset($linha['CODIC']) ? (string)$linha['CODIC'] : null;
                    $nome_cliente = isset($linha['NOME']) ? (string)$linha['NOME'] : null;
                    $encontrado = true;
                    ibase_free_result($consulta);
                    break;
                }
                
                if ($consulta) {
                    ibase_free_result($consulta);
                }
            }
            
            if (!$encontrado || !$codic_encontrado) {
                throw new Exception("Cliente não encontrado na unidade {$unidade} com documento {$documento}");
            }
            
            // Realizar UPDATE com transação
            ibase_trans($con);
            
            $sql_update = "UPDATE arqcad c 
                          SET c.lcred = {$limite}, 
                              c.usu_alterou = '{$usuario}'
                          WHERE c.codic = {$codic_encontrado} 
                          AND c.tipoc = 'C'";
            
            $resultado_update = @ibase_query($con, $sql_update);
            
            if (!$resultado_update) {
                ibase_rollback($con);
                $erro = ibase_errmsg() ?: 'Erro desconhecido no UPDATE';
                throw new Exception("Erro ao atualizar limite: {$erro}");
            }
            
            ibase_commit($con);
            
            return array(
                'success' => true,
                'unidade' => $unidade,
                'codic' => $codic_encontrado,
                'nome_cliente' => $nome_cliente,
                'limite' => $limite,
                'usuario' => $usuario,
                'mensagem' => 'Limite atualizado com sucesso'
            );
            
        } catch (Exception $e) {
            if ($con) {
                @ibase_rollback($con);
            }
            throw $e;
        } finally {
            if ($con) {
                @ibase_close($con);
            }
        }
    }
    
    // Método para fechar conexão
    public static function fecharConexao($conexao)
    {
        if ($conexao) {
            @ibase_close($conexao);
        }
    }
}