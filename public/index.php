<?php

require __DIR__ . '/../vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

$app = AppFactory::create();

$app->get('/api/health', function (Request $request, Response $response) {
    $payload = [
        'status' => 'ok',
        'service' => 'teacheros-api',
        'timestamp' => gmdate('c')
    ];

    $response->getBody()->write(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
