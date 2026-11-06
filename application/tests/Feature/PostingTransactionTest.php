<?php

namespace Tests\Feature;

use App\Http\ApiResponse;
use App\Http\Middleware\ResolveBranch;
use App\Models\Branch;
use App\Models\User;
use App\Services\PostingAudit;
use App\Services\PostingTransaction;
use Database\Seeders\ShopAccessSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostingTransactionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_overlapping_request_replays_the_committed_result(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $user = User::factory()->create(['tenant_id' => $branch->tenant_id]);
        $request = Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'overlap']);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('branch', $branch);
        $worker = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $user = App\Models\User::findOrFail($argv[1]);
        $branch = App\Models\Branch::findOrFail($argv[2]);
        $request = Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'overlap']);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('branch', $branch);
        echo "ready\n";
        $result = $app->make(App\Services\PostingTransaction::class)->run($request, 'test.overlap', [], function () {
            throw new RuntimeException('Duplicate executed the posting callback.');
        });
        echo $result->getContent();
        PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, (string) $user->id, (string) $branch->id], base_path(), [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'khatapos_test', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
        ], timeout: 15);

        try {
            $first = app(PostingTransaction::class)->run($request, 'test.overlap', [], function () use ($process) {
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $process->getErrorOutput());
                usleep(200_000);
                $this->assertTrue($process->isRunning(), $process->getErrorOutput());

                return ApiResponse::data(['posted' => true], 201);
            });
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertJsonStringEqualsJsonString($first->getContent(), trim(substr($process->getOutput(), strlen("ready\n"))));
            $this->assertDatabaseCount('idempotency_requests', 1);
        } finally {
            $process->stop();
        }
    }

    public function test_posting_replays_conflicts_and_rolls_back(): void
    {
        $this->seed(ShopAccessSeeder::class);
        $branch = Branch::firstOrFail();
        $user = User::factory()->create(['tenant_id' => $branch->tenant_id, 'has_all_branches' => true]);
        Route::post('/api/test/post', function (Request $request, PostingTransaction $posting, PostingAudit $audit) {
            return $posting->run($request, 'test.post', $request->all(), function (int $id) use ($request, $audit) {
                $audit->record($request, 'posted', 'fixture', $id, null, ['nested' => ['password' => 'secret', 'amount' => 1]], 'fixture reason');

                return $request->boolean('fail')
                    ? ApiResponse::conflict('TEST_FAILURE', 'Rollback fixture.')
                    : ApiResponse::data(['id' => $id], 201);
            });
        })->middleware(['auth', ResolveBranch::class]);

        $this->actingAs($user);
        $headers = ['X-Branch-Id' => $branch->id, 'Idempotency-Key' => 'posting-1'];
        $this->postJson('/api/test/post', [], ['X-Branch-Id' => $branch->id])->assertUnprocessable();
        $first = $this->postJson('/api/test/post', ['a' => 1, 'b' => 2], $headers)->assertCreated();
        $this->postJson('/api/test/post', ['b' => 2, 'a' => 1], $headers)->assertCreated()->assertExactJson($first->json());
        $this->postJson('/api/test/post', ['a' => 2, 'b' => 2], $headers)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        $headers['Idempotency-Key'] = 'posting-2';
        $this->postJson('/api/test/post', ['fail' => true], $headers)->assertConflict();
        $this->assertDatabaseCount('idempotency_requests', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $audit = DB::table('audit_logs')->first();
        $this->assertSame(['nested' => ['amount' => 1]], json_decode($audit->after_json, true));
        $this->assertSame('fixture reason', $audit->reason);
        $this->postJson('/api/test/post', [], $headers)->assertCreated();
    }
}
