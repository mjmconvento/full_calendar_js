<?php

declare(strict_types=1);

// Lets phpstan-doctrine analyse DQL and repository return types.
// Not a test: PHPUnit only loads tests/**/*Test.php.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__.'/../.env');

$kernel = new Kernel('dev', true);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
