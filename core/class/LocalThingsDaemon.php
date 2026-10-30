<?php

/** Boucle IPC Unix privée du démon LocalThings. */
class LocalThingsDaemon
{
    private const MAX_FRAME_BYTES = 1048576;
    private $runtimePath;
    private $handler;
    private $tick;
    private $shutdown;
    private $logger;
    private $running = true;
    private $lockHandle;
    private $server;

    public function __construct($runtimePath, callable $handler, $tick = null, $shutdown = null, $logger = null)
    {
        $this->runtimePath = rtrim((string) $runtimePath, '/');
        $this->handler = $handler;
        $this->tick = is_callable($tick) ? $tick : null;
        $this->shutdown = is_callable($shutdown) ? $shutdown : null;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    public function run()
    {
        if (!$this->prepareRuntime()) {
            return 2;
        }
        $this->log('info', '[Daemon] Démarrage du propriétaire unique des sessions DTLS');
        $lastHeartbeat = 0.0;
        $lastTick = 0.0;
        try {
            $this->installSignalHandlers();
            while ($this->running) {
                $connection = @stream_socket_accept($this->server, 0.20);
                if (is_resource($connection)) {
                    $this->serveConnection($connection);
                }
                $now = microtime(true);
                if ($this->tick !== null && $now - $lastTick >= 1.0) {
                    $lastTick = $now;
                    try {
                        call_user_func($this->tick);
                    } catch (Throwable $error) {
                        $this->log('error', '[Daemon] Poll : ' . $error->getMessage());
                    }
                }
                if ($now - $lastHeartbeat >= 2.0) {
                    $lastHeartbeat = $now;
                    $this->writeHeartbeat();
                }
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
            return 0;
        } finally {
            if ($this->shutdown !== null) {
                try { call_user_func($this->shutdown); } catch (Throwable $error) {
                    $this->log('warning', '[Daemon] Fermeture : ' . $error->getMessage());
                }
            }
            $this->cleanupRuntime();
            $this->log('info', '[Daemon] Arrêt terminé');
        }
    }

    private function prepareRuntime()
    {
        if (!is_dir($this->runtimePath)
            && !mkdir($this->runtimePath, 0700, true)
            && !is_dir($this->runtimePath)) {
            throw new RuntimeException('Création du runtime LocalThings impossible');
        }
        @chmod($this->runtimePath, 0700);
        $this->lockHandle = @fopen($this->runtimePath . '/daemon.lock', 'c');
        if (!is_resource($this->lockHandle) || !@flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            if (is_resource($this->lockHandle)) { fclose($this->lockHandle); }
            $this->lockHandle = null;
            return false;
        }
        @unlink($this->runtimePath . '/daemon.sock');
        @unlink($this->runtimePath . '/daemon.pid');
        @unlink($this->runtimePath . '/heartbeat.json');
        $errno = 0;
        $error = '';
        $this->server = @stream_socket_server(
            'unix://' . $this->runtimePath . '/daemon.sock',
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if (!is_resource($this->server)) {
            throw new RuntimeException('Création de la socket LocalThings impossible : ' . $error);
        }
        @chmod($this->runtimePath . '/daemon.sock', 0600);
        if (@file_put_contents($this->runtimePath . '/daemon.pid', (string) getmypid(), LOCK_EX) === false) {
            throw new RuntimeException('Écriture du PID LocalThings impossible');
        }
        @chmod($this->runtimePath . '/daemon.pid', 0600);
        $this->writeHeartbeat();
        return true;
    }

    private function installSignalHandlers()
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
        pcntl_signal(SIGTERM, function () { $this->running = false; });
        pcntl_signal(SIGINT, function () { $this->running = false; });
    }

    private function serveConnection($connection)
    {
        stream_set_timeout($connection, 2);
        try {
            $line = fgets($connection, self::MAX_FRAME_BYTES + 2);
            if (!is_string($line) || strlen($line) > self::MAX_FRAME_BYTES || substr($line, -1) !== "\n") {
                $this->writeResponse($connection, '', false, null, array(
                    'code' => 'INVALID_FRAME', 'message' => 'Requête IPC invalide', 'retryable' => false, 'phase' => 'ipc'
                ));
                return;
            }
            $request = json_decode(rtrim($line, "\r\n"), true);
            $id = is_array($request) ? (string) ($request['id'] ?? '') : '';
            if (!is_array($request) || ($request['v'] ?? null) !== 1
                || !preg_match('/^[a-f0-9]{32}$/D', $id)
                || !is_string($request['op'] ?? null)
                || !is_array($request['payload'] ?? null)) {
                $this->writeResponse($connection, $id, false, null, array(
                    'code' => 'INVALID_REQUEST', 'message' => 'Requête IPC invalide', 'retryable' => false, 'phase' => 'ipc'
                ));
                return;
            }
            $operation = $request['op'];
            if ($operation === 'health') {
                $result = array('pid' => getmypid(), 'heartbeat' => time());
            } elseif ($operation === 'stop') {
                $result = array('stopping' => true, 'pid' => getmypid());
                $this->running = false;
            } else {
                $result = call_user_func($this->handler, $operation, $request['payload']);
            }
            $this->writeResponse($connection, $id, true, $result, null);
        } catch (Throwable $error) {
            $this->writeResponse($connection, $id ?? '', false, null, array(
                'code' => method_exists($error, 'remoteCode') ? $error->remoteCode() : 'DAEMON_ERROR',
                'message' => $error->getMessage(),
                'retryable' => method_exists($error, 'retryable') ? $error->retryable() : false,
                'phase' => method_exists($error, 'phase') ? $error->phase() : '',
            ));
        } finally {
            fclose($connection);
        }
    }

    private function writeResponse($connection, $id, $ok, $result, $error)
    {
        $frame = json_encode(array(
            'v' => 1,
            'id' => (string) $id,
            'ok' => (bool) $ok,
            'result' => $ok ? $result : null,
            'error' => $ok ? null : $error,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($frame) || strlen($frame) + 1 > self::MAX_FRAME_BYTES) {
            $frame = '{"v":1,"id":"","ok":false,"result":null,"error":{"code":"RESPONSE_TOO_LARGE","message":"Réponse IPC trop volumineuse","retryable":false,"phase":"ipc"}}';
        }
        @fwrite($connection, $frame . "\n");
        @fflush($connection);
    }

    private function writeHeartbeat()
    {
        $temporary = @tempnam($this->runtimePath, 'heartbeat.');
        if (!is_string($temporary)) {
            return;
        }
        $data = json_encode(array('pid' => getmypid(), 'updated' => time()));
        if (@file_put_contents($temporary, $data, LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $this->runtimePath . '/heartbeat.json');
        }
        if (is_file($temporary)) { @unlink($temporary); }
    }

    private function cleanupRuntime()
    {
        if (is_resource($this->server)) { fclose($this->server); }
        $this->server = null;
        @unlink($this->runtimePath . '/daemon.sock');
        @unlink($this->runtimePath . '/daemon.pid');
        @unlink($this->runtimePath . '/heartbeat.json');
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }

    private function log($level, $message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $level, $message);
        }
    }
}
