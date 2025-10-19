<?php
namespace App\Service;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class LoggerService
{
    private Logger $logger;

    public function __construct()
    {
        $this->logger = new Logger('file_processor');
        $logFile = $_ENV['LOG_FILE'] ?? 'var/logs/file_processor.log';
        $level = $_ENV['LOG_LEVEL'] ?? 'info';
        $this->logger->pushHandler(new StreamHandler($logFile, Logger::toMonologLevel($level)));
    }

    public function info(string $message): void { $this->logger->info($message); }
    public function warning(string $message): void { $this->logger->warning($message); }
    public function error(string $message): void { $this->logger->error($message); }
}
