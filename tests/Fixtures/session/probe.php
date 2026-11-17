<?php

/**
 * The session child for BwrapSessionTest. Reads one command per line and answers one JSON line:
 *
 *   env           the child's whole environment
 *   read <path>   whether <path> is readable, and its first bytes
 *   net           whether a TCP connect to 1.1.1.1:53 succeeds within 2 s
 *   echo <text>   the text back
 *
 * Exits 0 at stdin EOF.
 */
while (($line = fgets(STDIN)) !== false) {
    [$command, $arg] = array_pad(explode(' ', rtrim($line, "\r\n"), 2), 2, '');

    $answer = match ($command) {
        'env' => ['env' => getenv()],
        'read' => ['path' => $arg, 'readable' => is_readable($arg), 'head' => is_readable($arg) ? substr((string) @file_get_contents($arg), 0, 32) : null],
        'net' => ['connected' => (function (): bool {
            $socket = @fsockopen('1.1.1.1', 53, $errno, $errstr, 2.0);

            if ($socket === false) {
                return false;
            }

            fclose($socket);

            return true;
        })()],
        'echo' => ['echo' => $arg],
        default => ['error' => "unknown command `{$command}`"],
    };

    fwrite(STDOUT, json_encode($answer)."\n");
    fflush(STDOUT);
}

exit(0);
