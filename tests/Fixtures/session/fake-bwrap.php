<?php

/*
 * A stand-in for `bwrap` in BwrapSessionTest (amend, Codex review ff28ff40 finding 1). It ISOLATES NOTHING.
 *
 * The test installs it as an executable with a `#!<php> -n` shebang. It records the exact argv and environment IT was
 * launched with (what the host's process list and /proc would show for bwrap) to <its own path>.log. It then emulates
 * only the `--ro-bind <bundle> /pkg` + `--chdir /pkg` mapping, so the guest path resolves, and runs the guest command
 * after `--`, passing on its OWN runtime view of the environment (`getenv()`), as real bwrap passes its environment
 * when its floor has no --clearenv.
 *
 * That view is not always exactly what this process was given. On macOS a PHP process has been observed to see one name
 * it was not handed (kernel ADR-0003 amendment, root 44be5842; RuntimeEnvAdditions), and this fake then FORWARDS that
 * name to the guest. So on Darwin, what the guest sees through this fake is the delivered env plus at most that one
 * listed name. It is delivery evidence modulo that name, not proof of exact delivery, and not real bwrap.
 */
$args = array_slice($argv, 1);
file_put_contents(__FILE__.'.log', json_encode(['argv' => $args, 'env' => getenv()]));

$guest = null;
$bundle = null;
$chdir = null;
$i = 0;
while ($i < count($args)) {
    $a = $args[$i];
    if ($a === '--') {
        $i++;
        break;
    }
    if (in_array($a, ['--ro-bind', '--bind', '--ro-bind-try', '--symlink', '--setenv'], true)) {
        if ($a === '--ro-bind' && ($args[$i + 2] ?? null) === '/pkg') {
            $bundle = $args[$i + 1];
            $guest = '/pkg';
        }
        $i += 3;

        continue;
    }
    if (in_array($a, ['--uid', '--gid', '--proc', '--dev', '--tmpfs', '--chdir'], true)) {
        if ($a === '--chdir') {
            $chdir = $args[$i + 1];
        }
        $i += 2;

        continue;
    }
    $i++;
}

$command = array_map(
    static fn (string $a): string => $guest !== null && str_starts_with($a, $guest.'/') ? $bundle.substr($a, strlen($guest)) : $a,
    array_slice($args, $i),
);
$process = proc_open($command, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $chdir === $guest ? $bundle : null, getenv());
exit(is_resource($process) ? proc_close($process) : 96);
