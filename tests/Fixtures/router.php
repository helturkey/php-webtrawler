<?php

declare(strict_types=1);
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($p) {
    case '/big': header('Content-Type: text/html');
        for ($i = 0;$i < 200;$i++) {
            echo str_repeat('x', 50000);
            flush();
        } exit;
    case '/biglen': header('Content-Type: text/html');
        header('Content-Length: 10000000');
        echo str_repeat('x', 10000000);
        exit;
    case '/small': header('Content-Type: text/html; charset=utf-8');
        echo '<h1>hi</h1>';
        exit;
    case '/redir': header('Location: /small', true, 302);
        exit;
    case '/redir-other-host': header('Location: http://localhost:'.$_SERVER['SERVER_PORT'].'/small', true, 302);
        exit;
    case '/redir-private': header('Location: http://169.254.169.254/latest', true, 302);
        exit;
    case '/redir-loop': header('Location: /redir-loop', true, 302);
        exit;
    case '/sleep': sleep(3);
        echo 'late';
        exit;
    case '/delay': usleep(((int)($_GET['ms'] ?? 500)) * 1000);
        header('Content-Type: text/html');
        echo '<p>'.($_GET['n'] ?? '').'</p>';
        exit;
    case '/echo': header('Content-Type: application/json');
        echo json_encode(['method' => $_SERVER['REQUEST_METHOD'],'headers' => getallheaders(),'body' => file_get_contents('php://input'),'query' => $_GET]);
        exit;
    case '/status500': http_response_code(500);
        echo 'err';
        exit;
    case '/status429': http_response_code(429);
        header('Retry-After: 1');
        echo 'slow';
        exit;
    case '/cp1256': header('Content-Type: text/html; charset=windows-1256');
        echo iconv('UTF-8', 'WINDOWS-1256', '<html><body><h1>مرحبا</h1></body></html>');
        exit;
    case '/multi': header('Set-Cookie: a=1');
        header('Set-Cookie: b=2', false);
        header('X-Multi: one');
        header('X-Multi: two', false);
        echo 'm';
        exit;
    case '/flaky': $f = sys_get_temp_dir().'/scraper-flaky-'.preg_replace('/[^a-z0-9]/i', '', ($_GET['k'] ?? 'x'));
        $n = (int)@file_get_contents($f);
        file_put_contents($f, $n + 1);
        if ($n < 2) {
            http_response_code(503);
            echo 'try later';
            exit;
        } echo '<p>ok after '.$n.'</p>';
        exit;
    case '/links': $i = (int)($_GET['i'] ?? 0);
        header('Content-Type: text/html');
        echo '<html><body><h1>page '.$i.'</h1>';
        if ($i < 3) {
            for ($k = 1;$k <= 3;$k++) {
                echo '<a href="/links?i='.($i * 3 + $k).'">n</a>';
            }
        } echo '</body></html>';
        exit;
    case '/robots.txt': echo "User-agent: *\nDisallow: /private\n";
        exit;
}
http_response_code(404);
echo 'nf';
