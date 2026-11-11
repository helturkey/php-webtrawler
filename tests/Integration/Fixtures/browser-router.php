<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/api/books') {
    $body = json_encode(['items' => [['title' => 'The PHP WebTrawler Handbook']]], JSON_THROW_ON_ERROR);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    echo $body;

    return;
}

header('Content-Type: text/html; charset=utf-8');

if ($path === '/seed') {
    setcookie('scraper_session', 'persisted', [
        'expires' => time() + 3600,
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
    echo <<<'HTML'
        <!doctype html><html><head><title>Seed Session</title></head><body>
        <script>localStorage.setItem('scraper_local', 'persisted');</script>
        <p>Session seeded</p>
        </body></html>
        HTML;

    return;
}

if ($path === '/check') {
    echo <<<'HTML'
        <!doctype html><html><head><title>Check Session</title></head><body>
        <p id="session-check">checking</p>
        <script>
          const cookiePresent = document.cookie.includes('scraper_session=persisted');
          const localPresent = localStorage.getItem('scraper_local') === 'persisted';
          document.querySelector('#session-check').textContent =
            `cookie:${cookiePresent ? 'yes' : 'no'};local:${localPresent ? 'yes' : 'no'}`;
        </script>
        </body></html>
        HTML;

    return;
}

if ($path === '/egress-check') {
    echo <<<'HTML'
        <!doctype html><html><head><title>Isolated Browser Egress</title></head><body>
        <p id="egress-check" data-complete="0">pending</p>
        <script>
          fetch('https://example.com/', { mode: 'no-cors', cache: 'no-store' })
            .then(() => { document.querySelector('#egress-check').textContent = 'reachable'; })
            .catch(() => { document.querySelector('#egress-check').textContent = 'blocked'; })
            .finally(() => { document.querySelector('#egress-check').dataset.complete = '1'; });
        </script>
        </body></html>
        HTML;

    return;
}

if ($path === '/adaptive-before') {
    echo '<!doctype html><html><body><div class="book-card"><h2 class="book-title" data-testid="book-title">The PHP WebTrawler Handbook</h2></div></body></html>';

    return;
}

if ($path === '/adaptive-after') {
    echo '<!doctype html><html><body><article class="publication"><section><h3 class="heading" data-testid="book-title">The PHP WebTrawler Handbook</h3></section></article></body></html>';

    return;
}

echo <<<'HTML'
    <!doctype html><html><head><title>Browser Network Fixture</title></head><body>
    <h1>Network capture is ready</h1><p id="api-result">loading</p>
    <script>
      fetch('/api/books', {
        headers: { 'X-Scraper-Fixture': 'browser-integration' }
      })
        .then(response => response.json())
        .then(data => { document.querySelector('#api-result').textContent = data.items[0].title; });
    </script>
    </body></html>
    HTML;
