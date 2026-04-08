<?php
/**
 * laxus-proxy.php
 * ラクサスのコーデページから画像URLを3件取得してJSONで返すサーバーサイドプロキシ
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$TARGET_URL = 'https://laxus.co/app/pc/blog/outfit';

// ---- ページ取得 (cURL優先 → file_get_contents フォールバック) ----
function fetchPage(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LaxusProxy/1.0)',
            CURLOPT_HTTPHEADER     => ['Accept-Language: ja,en;q=0.9'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err) {
            throw new RuntimeException('cURL エラー: ' . $err);
        }
        if ($code !== 200) {
            throw new RuntimeException("HTTPエラー: {$code}");
        }
        return $body;
    }

    // file_get_contents フォールバック
    $ctx  = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: Mozilla/5.0\r\nAccept-Language: ja,en;q=0.9\r\n",
        'timeout' => 15,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        throw new RuntimeException('ページの取得に失敗しました');
    }
    return $body;
}

// ---- HTML から outfit リンク+画像を抽出 ----
function extractOutfits(string $html): array {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath   = new DOMXPath($dom);
    // /outfit/数字 を含む <a> の中に <img> があるものを対象
    $anchors = $xpath->query('//a[contains(@href, "/outfit/")]');

    $outfits = [];
    $seen    = [];

    foreach ($anchors as $anchor) {
        $href = $anchor->getAttribute('href');

        // /outfit/数字 パターン以外はスキップ
        if (!preg_match('#/outfit/\d+#', $href)) {
            continue;
        }
        if (in_array($href, $seen, true)) {
            continue;
        }

        $imgs = $anchor->getElementsByTagName('img');
        if ($imgs->length === 0) {
            continue;
        }

        $img = $imgs->item(0);
        $src = $img->getAttribute('src');
        if (empty($src)) {
            continue;
        }

        $seen[] = $href;

        // 相対URLを絶対URLに変換
        if (strpos($href, 'http') !== 0) {
            $href = 'https://laxus.co' . $href;
        }

        $outfits[] = [
            'href' => $href,
            'src'  => $src,
            'alt'  => $img->getAttribute('alt') ?? '',
        ];

        if (count($outfits) >= 3) {
            break;
        }
    }

    return $outfits;
}

// ---- メイン処理 ----
try {
    $html    = fetchPage($TARGET_URL);
    $outfits = extractOutfits($html);

    if (empty($outfits)) {
        http_response_code(404);
        echo json_encode(
            ['error' => 'コーデ画像が見つかりませんでした。ページの構造が変わった可能性があります。'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    echo json_encode(
        ['outfits' => $outfits],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(
        ['error' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE
    );
}
