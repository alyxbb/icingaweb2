<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

namespace Icinga\Clicommands;

use Icinga\Application\Icinga;
use Icinga\Cli\Command;
use Icinga\Exception\IcingaException;

class WebCommand extends Command
{
    /**
     * Serve Icinga Web 2 with PHP's built-in web server
     *
     * USAGE
     *
     *   icingacli web serve [options]
     *
     * OPTIONS
     *
     *   --daemonize                Run in background
     *   --document-root=<dir>      The document root directory of Icinga Web 2 (e.g. ./public)
     *   --listen-addr=<host:port>  The address to listen on
     *
     * EXAMPLES
     *
     *   icingacli web serve --listen-addr=127.0.0.1:8080
     */
    public function serveAction()
    {
        $minVersion = '5.4.0';
        if (version_compare(PHP_VERSION, $minVersion) < 0) {
            throw new IcingaException(
                'You are running PHP %s, internal webserver requires %s.',
                PHP_VERSION,
                $minVersion
            );
        }

        $fork = $this->params->get('daemonize');
        $documentRoot = $this->params->shift('document-root');
        $socket  = $this->params->shift('listen-addr');

        // TODO: Sanity check!!
        if ($socket === null) {
            $socket = '0.0.0.0:80';
            // throw new IcingaException('Socket is required');
        }
        if ($documentRoot === null) {
            $documentRoot = Icinga::app()->getBaseDir('public');
            if (! file_exists($documentRoot) || ! is_dir($documentRoot)) {
                throw new IcingaException('Document root directory is required');
            }
        }
        $documentRoot = realpath($documentRoot);

        if ($fork) {
            $this->forkAndExit();
        }
        echo "Serving Icingaweb from $documentRoot\n";
        $cmd = sprintf(
            '%s -S %s -t %s %s',
            readlink('/proc/self/exe'),
            $socket,
            $documentRoot,
            Icinga::app()->getLibraryDir('/Icinga/Application/webrouter.php')
        );

        // TODO: Store webserver log, switch uid, log index.php includes, pid file
        if ($fork) {
            exec($cmd);
        } else {
            passthru($cmd);
        }
    }

    public function stopAction()
    {
        // TODO: No, that's NOT what we want
        $prog = readlink('/proc/self/exe');
        `killall $prog`;
    }

    protected function forkAndExit()
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
             throw new IcingaException('Could not fork');
        } else if ($pid) {
            echo $this->screen->colorize('[OK]')
               . " Icinga Web server forked successfully\n";
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            exit;
            // pcntl_wait($status);
        } else {
             // child
        }
    }
}
