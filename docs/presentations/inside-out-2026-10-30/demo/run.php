<?php
// Boot Laravel and run a demo script; exit non-zero on any exception (artisan tinker would swallow it).
// Refuses to run unless the live connection is the demo sqlite file named in DEMO_DB.
//   php run.php <script.php>
$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $conn = Illuminate\Support\Facades\DB::connection();
    $want = realpath((string) getenv('DEMO_DB'));
    if ($conn->getDriverName() !== 'sqlite' || ! $want || realpath($conn->getDatabaseName()) !== $want) {
        throw new RuntimeException('Refusing: the live DB connection is not the demo sqlite file ('
            .$conn->getDriverName().' '.$conn->getDatabaseName().')');
    }
    if (($argv[1] ?? 'guard') !== 'guard') {
        require $argv[1];
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
