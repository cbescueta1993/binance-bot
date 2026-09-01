<?php

namespace SupertrendBot;

/**
 * Writes timestamped lines to stdout and to a log file.
 * This is the only class that knows about bot.log.
 */
class Logger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;

        $dir = dirname($logFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!file_exists($logFile)) {
            touch($logFile);
        }
    }

    public function info(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;

        echo $line;

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }
}
