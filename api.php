<?php
/**
 * api.php — Proxy de dados de mercado para o Painel de Índices
 * Fonte: Yahoo Finance (endpoint público "chart"), com cache em disco.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ===== CONFIGURAÇÃO =====
const TTL_COTACAO = 30;                   // segundos
const DIR_CACHE = __DIR__ . '/cache';

const ATIVOS = [
    'ewz'    => ['nome' => 'iShares Brazil (EWZ)',   'yahoo' => 'EWZ',      'dec' => 2, 'un' => 'US$'],
    'dxy'    => ['nome' => 'Índice Dólar (DXY)',     'yahoo' => 'DX-Y.NYB', 'dec' => 3, 'un' => 'pts'],
    'es'     => ['nome' => 'S&P 500 Futuros',        'yahoo' => 'ES=F',     'dec' => 2, 'un' => 'pts'],
    'ym'     => ['nome' => 'Dow 30 Futuros',         'yahoo' => 'YM=F',     'dec' => 0, 'un' => 'pts'],
    'nq'     => ['nome' => 'Nasdaq 100 Futuros',     'yahoo' => 'NQ=F',     'dec' => 2, 'un' => 'pts'],
    'vix'    => ['nome' => 'VIX (Volatilidade S&P 500)', 'yahoo' => '^VIX',  'dec' => 2, 'un' => 'pts'],
    'usdbrl' => ['nome' => 'USD/BRL',                'yahoo' => 'USDBRL=X', 'dec' => 4, 'un' => 'R$'],
];
// período => [range Yahoo, intervalo, intradiário?, cache em segundos]
const PERIODOS = [
    '1d'  => ['1d',  '5m',  true,  60],
    '5d'  => ['5d',  '15m', true,  900],
    '1mo' => ['1mo', '1d',  false, 900],
];

if (!is_dir(DIR_CACHE)) { @mkdir(DIR_CACHE, 0775, true); }

function saida(array $d, int $code = 200): never {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function cacheado(string $chave, int $ttl, callable $gerar): mixed {
    $arq = DIR_CACHE . '/' . md5($chave) . '.json';
    if (is_file($arq) && (time() - filemtime($arq)) < $ttl) {
        $c = json_decode((string)file_get_contents($arq), true);
        if ($c !== null) return $c;
    }
    $novo = $gerar();
    if ($novo !== null) {
        file_put_contents($arq, json_encode($novo), LOCK_EX);
        return $novo;
    }
    // falha na origem: devolve cache antigo, se existir
    return is_file($arq) ? json_decode((string)file_get_contents($arq), true) : null;
}

function yahoo(string $simbolo, string $range, string $intervalo): ?array {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($simbolo)
         . '?range=' . $range . '&interval=' . $intervalo . '&includePrePost=false';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PainelIndices/1.0)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $http !== 200) return null;
    $j = json_decode($resp, true);
    return $j['chart']['result'][0] ?? null;
}

/** Série [{d, c}]: intradiário => d = epoch (s); diário => d = 'YYYY-MM-DD' */
function serie(array $r, bool $intra): array {
    $ts = $r['timestamp'] ?? [];
    $cl = $r['indicators']['quote'][0]['close'] ?? [];
    $off = (int)($r['meta']['gmtoffset'] ?? 0);
    $out = [];
    foreach ($ts as $i => $t) {
        if (!isset($cl[$i]) || $cl[$i] === null) continue;
        $out[] = ['d' => $intra ? (int)$t : gmdate('Y-m-d', $t + $off), 'c' => (float)$cl[$i]];
    }
    return $out;
}

function cotacaoYahoo(string $id): ?array {
    $a = ATIVOS[$id];
    $r = cacheado("cot:$id", TTL_COTACAO, fn() => yahoo($a['yahoo'], '5d', '1d'));
    if (!$r) return null;
    $meta  = $r['meta'];
    $preco = $meta['regularMarketPrice'] ?? null;
    $tsAtual = (int)($meta['regularMarketTime'] ?? time());
    $off = (int)($meta['gmtoffset'] ?? 0);
    $diaAtual = gmdate('Y-m-d', $tsAtual + $off);
    $anterior = null;
    foreach (serie($r, false) as $p) {           // último fechamento de dia anterior
        if ($p['d'] < $diaAtual) $anterior = $p['c'];
    }
    if ($preco === null) return null;
    return ['preco' => (float)$preco, 'anterior' => $anterior, 'hora' => $tsAtual,
            'estado' => $meta['marketState'] ?? null];
}

$acao = $_GET['acao'] ?? 'cotacoes';

if ($acao === 'cotacoes') {
    $res = [];
    foreach (ATIVOS as $id => $a) {
        $c = cotacaoYahoo($id);
        $item = ['id' => $id, 'nome' => $a['nome'], 'dec' => $a['dec'], 'un' => $a['un'], 'ok' => $c !== null];
        if ($c) {
            $item += $c;
            $item['var'] = ($c['anterior'] ?? null) ? $c['preco'] - $c['anterior'] : null;
            $item['pct'] = ($c['anterior'] ?? null) ? ($c['preco'] / $c['anterior'] - 1) * 100 : null;
        }
        $res[] = $item;
    }
    saida(['atualizado' => time(), 'ativos' => $res]);
}

if ($acao === 'historico') {
    $per = $_GET['periodo'] ?? '1mo';
    if (!isset(PERIODOS[$per])) saida(['erro' => 'periodo inválido'], 400);
    [$range, $intervalo, $intra, $ttl] = PERIODOS[$per];
    $res = [];
    foreach (ATIVOS as $id => $a) {
        $r = cacheado("hist:$id:$per", $ttl, fn() => yahoo($a['yahoo'], $range, $intervalo));
        $res[$id] = $r ? serie($r, $intra) : [];
    }
    saida(['periodo' => $per, 'series' => $res]);
}

// Diagnóstico: mostra os dados brutos do Yahoo e como a variação foi calculada.
// Uso: api.php?acao=diagnostico&id=usdbrl
if ($acao === 'diagnostico') {
    $id = $_GET['id'] ?? 'usdbrl';
    if (!isset(ATIVOS[$id])) saida(['erro' => 'ativo inválido'], 400);
    $r = yahoo(ATIVOS[$id]['yahoo'], '5d', '1d');
    if (!$r) saida(['erro' => 'falha ao consultar a origem'], 502);
    $campos = ['symbol', 'regularMarketPrice', 'regularMarketTime', 'gmtoffset', 'exchangeTimezoneName', 'previousClose', 'chartPreviousClose'];
    saida([
        'meta'      => array_intersect_key($r['meta'], array_flip($campos)),
        'barras'    => serie($r, false),
        'calculado' => cotacaoYahoo($id),
    ]);
}

saida(['erro' => 'acao desconhecida'], 400);
