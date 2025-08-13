<?php

class Logger {
    private static $logFile = __DIR__ . '/error.log';
    
    /**
     * Log an error message
     * 
     * @param string $message The error message to log
     * @param string $level The log level (error, warning, info, etc.)
     * @return bool True on success, false on failure
     */
    public static function error($message, $level = 'ERROR') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = sprintf(
            "[%s] %s: %s\n",
            $timestamp,
            strtoupper($level),
            $message
        );
        
        // Create log directory if it doesn't exist
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Write to log file
        return file_put_contents(
            self::$logFile,
            $logMessage,
            FILE_APPEND | LOCK_EX
        ) !== false;
    }
    
    /**
     * Set a custom log file path
     * 
     * @param string $path Path to the log file
     */
    public static function setLogFile($path) {
        self::$logFile = $path;
    }
    
    /**
     * Get the current log file path
     * 
     * @return string Current log file path
     */
    public static function getLogFile() {
        return self::$logFile;
    }
}
