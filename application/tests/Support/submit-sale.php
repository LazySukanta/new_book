<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->scalar('select database()') !== 'khatapos_test') {
    throw new LogicException('Sale worker requires the dedicated MySQL test database.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$request = Request::create('/api/v1/sales', 'POST', server: [
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_X_BRANCH_ID' => $input['branch'],
    'HTTP_IDEMPOTENCY_KEY' => $input['key'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
], content: json_encode($input['data'], JSON_THROW_ON_ERROR));
fwrite(STDERR, "ready\n");
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
