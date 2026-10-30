<?php

class LocalThingsDaemonUnavailableException extends RuntimeException {}

class LocalThingsDaemonRemoteException extends RuntimeException
{
    private $remoteCode;
    private $retryable;
    private $phase;

    public function __construct($message, $remoteCode = 'DAEMON_ERROR', $retryable = false, $phase = '')
    {
        parent::__construct((string) $message);
        $this->remoteCode = (string) $remoteCode;
        $this->retryable = (bool) $retryable;
        $this->phase = (string) $phase;
    }

    public function remoteCode() { return $this->remoteCode; }
    public function retryable() { return $this->retryable; }
    public function phase() { return $this->phase; }
}

/** Client IPC synchrone du démon LocalThings. */
class LocalThingsDaemonClient
{
    private const MAX_FRAME_BYTES = 1048576;
    private $runtimePath;
    private $timeout;

    public function __construct($runtimePath, $timeout = 45.0)
    {
        $this->runtimePath = rtrim((string) $runtimePath, '/');
        $this->timeout = max(0.1, (float) $timeout);
    }

    public function socketPath()
    {
        return $this->runtimePath . '/daemon.sock';
    }

    /**
     * Indique si le runtime appartient toujours à un démon vivant, même quand
     * sa boucle synchrone est occupée par une opération DTLS longue.
     */
    public function runtimeStatus($maxHeartbeatAge = 180)
    {
        $maxHeartbeatAge = max(5, (int) $maxHeartbeatAge);
        if (!file_exists($this->socketPath())) {
            return false;
        }
        $pid = (int) @file_get_contents($this->runtimePath . '/daemon.pid');
        $heartbeat = json_decode((string) @file_get_contents($this->runtimePath . '/heartbeat.json'), true);
        if ($pid <= 1 || !is_array($heartbeat)
            || (int) ($heartbeat['pid'] ?? 0) !== $pid
            || time() - (int) ($heartbeat['updated'] ?? 0) > $maxHeartbeatAge) {
            return false;
        }
        $lock = @fopen($this->runtimePath . '/daemon.lock', 'c');
        if (!is_resource($lock)) {
            return false;
        }
        $acquired = @flock($lock, LOCK_EX | LOCK_NB);
        if ($acquired) {
            @flock($lock, LOCK_UN);
        }
        fclose($lock);
        return !$acquired;
    }

    public function health()
    {
        return $this->request('health', array(), 2000);
    }

    public function stop()
    {
        return $this->request('stop', array(), 2000);
    }

    public function request($operation, array $payload = array(), $deadlineMs = null)
    {
        $operation = trim((string) $operation);
        if ($operation === '' || strlen($operation) > 64) {
            throw new InvalidArgumentException('Opération IPC LocalThings invalide');
        }
        $deadlineMs = $deadlineMs === null
            ? (int) round($this->timeout * 1000)
            : max(100, min(120000, (int) $deadlineMs));
        $id = bin2hex(random_bytes(16));
        $frame = json_encode(array(
            'v' => 1,
            'id' => $id,
            'op' => $operation,
            'deadline_ms' => $deadlineMs,
            'payload' => $payload,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($frame) || strlen($frame) + 1 > self::MAX_FRAME_BYTES) {
            throw new InvalidArgumentException('Requête IPC LocalThings trop volumineuse');
        }

        $timeout = max(0.1, $deadlineMs / 1000);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath(),
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new LocalThingsDaemonUnavailableException(
                'Démon LocalThings indisponible' . ($error !== '' ? ' : ' . $error : '')
            );
        }
        $seconds = (int) floor($timeout);
        $microseconds = (int) round(($timeout - $seconds) * 1000000);
        stream_set_timeout($socket, $seconds, $microseconds);
        try {
            $data = $frame . "\n";
            $offset = 0;
            while ($offset < strlen($data)) {
                $written = @fwrite($socket, substr($data, $offset));
                if (!is_int($written) || $written <= 0) {
                    throw new LocalThingsDaemonUnavailableException('Écriture IPC LocalThings interrompue');
                }
                $offset += $written;
            }
            @fflush($socket);

            $response = '';
            while (strpos($response, "\n") === false) {
                $chunk = @fread($socket, 8192);
                if ($chunk === false || ($chunk === '' && feof($socket))) {
                    throw new LocalThingsDaemonUnavailableException('Réponse IPC LocalThings interrompue');
                }
                $response .= $chunk;
                if (strlen($response) > self::MAX_FRAME_BYTES) {
                    throw new LocalThingsDaemonUnavailableException('Réponse IPC LocalThings trop volumineuse');
                }
                $metadata = stream_get_meta_data($socket);
                if (!empty($metadata['timed_out'])) {
                    throw new LocalThingsDaemonUnavailableException('Délai IPC LocalThings dépassé');
                }
            }
            $decoded = json_decode(strtok($response, "\n"), true);
            if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1 || ($decoded['id'] ?? '') !== $id) {
                throw new LocalThingsDaemonUnavailableException('Réponse IPC LocalThings invalide');
            }
            if (empty($decoded['ok'])) {
                $remote = is_array($decoded['error'] ?? null) ? $decoded['error'] : array();
                throw new LocalThingsDaemonRemoteException(
                    (string) ($remote['message'] ?? 'Erreur du démon LocalThings'),
                    (string) ($remote['code'] ?? 'DAEMON_ERROR'),
                    !empty($remote['retryable']),
                    (string) ($remote['phase'] ?? '')
                );
            }
            return $decoded['result'] ?? array();
        } finally {
            fclose($socket);
        }
    }
}
