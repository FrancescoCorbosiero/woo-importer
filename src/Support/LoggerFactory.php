<?php

namespace ResellPiacenza\Support;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

/**
 * Logger factory
 *
 * Creates pre-configured Monolog loggers with rotating file + optional console output.
 *
 * @package ResellPiacenza\Support
 */
class LoggerFactory
{
    /**
     * Create a logger instance
     *
     * @param string $channel Logger channel name
     * @param array $options Optional overrides:
     *   - file: string       Log file path (default: logs/{channel}.log)
     *   - max_files: int     Rotation count (default: 7)
     *   - file_level: int    File log level (default: DEBUG)
     *   - console: bool      Enable console output (default: true)
     *   - console_level: int Console log level (default: INFO)
     * @return Logger
     */
    public static function create(string $channel, array $options = []): Logger
    {
        $logger = new Logger($channel);

        $root = Config::projectRoot();
        $logDir = $root . '/logs';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $options['file'] ?? $logDir . '/' . strtolower(str_replace(' ', '-', $channel)) . '.log';
        $maxFiles = $options['max_files'] ?? 7;
        $fileLevel = $options['file_level'] ?? Logger::DEBUG;

        $logger->pushHandler(new RotatingFileHandler($logFile, $maxFiles, $fileLevel));

        if ($options['console'] ?? true) {
            $consoleLevel = $options['console_level'] ?? Logger::INFO;
            $logger->pushHandler(new StreamHandler('php://stdout', $consoleLevel));
        }

        return $logger;
    }
}
