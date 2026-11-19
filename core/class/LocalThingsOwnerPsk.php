<?php

/** OwnerPSK primitives and private, per-host runtime credentials. No ownership writes. */
class LocalThingsOwnerPsk
{
    private $directory;
    private const PREFIX = "<?php http_response_code(404); exit; ?>\n";

    public function __construct($dataDirectory)
    {
        $this->directory = $dataDirectory . '/ownerpsk';
    }

    public static function uuidBytes($uuid)
    {
        if (!is_string($uuid) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid)) {
            throw new InvalidArgumentException(__('UUID OCF canonique attendu', __FILE__));
        }
        $bytes = hex2bin(str_replace('-', '', $uuid));
        if ($bytes === str_repeat("\0", 16)) { throw new InvalidArgumentException(__('UUID OCF nul interdit', __FILE__)); }
        return $bytes;
    }

    public static function validate(array $credential)
    {
        self::uuidBytes($credential['owner_uuid'] ?? null);
        self::uuidBytes($credential['device_uuid'] ?? null);
        if (!isset($credential['key']) || !is_string($credential['key'])
            || !preg_match('/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{64})$/D', $credential['key'])) {
            throw new InvalidArgumentException(__('OwnerPSK attendue : 32 ou 64 caractères hexadécimaux (16 ou 32 octets)', __FILE__));
        }
        return array('owner_uuid' => $credential['owner_uuid'], 'device_uuid' => $credential['device_uuid'],
            'key' => strtolower($credential['key']));
    }

    /** Pure derivation: callers must supply an authorized manufacturer session. Never claims an appliance. */
    public static function derive($masterSecret, $clientRandom, $serverRandom, $ownerUuid, $deviceUuid, $oxm, $cipher)
    {
        foreach (array(array($masterSecret, 48), array($clientRandom, 32), array($serverRandom, 32)) as $part) {
            if (!is_string($part[0]) || strlen($part[0]) !== $part[1]) { throw new InvalidArgumentException(__('État DTLS de dérivation invalide', __FILE__)); }
        }
        if ($cipher !== 'ECDHE-ECDSA-AES128-GCM-SHA256' || !in_array($oxm, array(2, 65282), true)) {
            throw new InvalidArgumentException(__('Profil de dérivation OwnerPSK non pris en charge', __FILE__));
        }
        $label = $oxm === 2 ? 'oic.sec.doxm.mfgcert' : 'x.org.iotivity.conmfgcert';
        $keyBlock = self::pHash($masterSecret, 'key expansion' . $serverRandom . $clientRandom, 120);
        return self::pHash($keyBlock, $label . self::uuidBytes($ownerUuid) . self::uuidBytes($deviceUuid), 16);
    }

    private static function pHash($key, $seed, $length)
    {
        $a = $seed; $output = '';
        while (strlen($output) < $length) {
            $a = hash_hmac('sha256', $a, $key, true);
            $output .= hash_hmac('sha256', $a . $seed, $key, true);
        }
        return substr($output, 0, $length);
    }

    private function path($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { throw new InvalidArgumentException(__('Adresse IPv4 invalide', __FILE__)); }
        return $this->directory . '/' . hash('sha256', $host) . '.php';
    }

    public function load($host)
    {
        $path = $this->path($host);
        if (!is_file($path)) { return null; }
        $raw = file_get_contents($path);
        if (substr($raw, 0, strlen(self::PREFIX)) !== self::PREFIX) { throw new RuntimeException(__('Stockage OwnerPSK invalide', __FILE__)); }
        $data = json_decode(substr($raw, strlen(self::PREFIX)), true);
        if (!is_array($data)) { throw new RuntimeException(__('Stockage OwnerPSK invalide', __FILE__)); }
        return self::validate($data);
    }

    /** Publish only after a fresh PSK session proved the expected device and protected access. */
    public function saveVerified($host, array $credential)
    {
        $credential = self::validate($credential);
        $path = $this->path($host);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(__('Stockage OwnerPSK inaccessible', __FILE__));
        }
        if (!chmod($this->directory, 0700)) { throw new RuntimeException(__('Permissions OwnerPSK non garanties', __FILE__)); }
        $temporary = tempnam($this->directory, '.pending-');
        if ($temporary === false) { throw new RuntimeException(__('Stockage OwnerPSK inaccessible', __FILE__)); }
        $protectedTemporary = $temporary . '.php';
        if (!rename($temporary, $protectedTemporary)) {
            unlink($temporary);
            throw new RuntimeException(__('Préparation du stockage OwnerPSK impossible', __FILE__));
        }
        $temporary = $protectedTemporary;
        try {
            if (!chmod($temporary, 0600)) { throw new RuntimeException(__('Permissions OwnerPSK non garanties', __FILE__)); }
            $data = self::PREFIX . json_encode($credential, JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !rename($temporary, $path)) {
                throw new RuntimeException(__('Enregistrement OwnerPSK impossible', __FILE__));
            }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}

/** DTLS PSK over a private pipe. Binary UUID, not its text spelling, is the PSK identity. */
class LocalThingsOwnerPskTransport extends LocalThingsDtlsClient
{
    private $host;
    private $port;
    private $credential; // credential validated by LocalThingsOwnerPsk
    private $localPort;
    private $process;
    private $pipes = array();

    public function __construct($host, $port, array $credential)
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException(__('Endpoint OwnerPSK invalide', __FILE__));
        }
        $this->host = $host; $this->port = (int) $port;
        // Port source éphémère (bind 0) : chaque nouvelle association DTLS utilise un
        // 4-tuple frais, ce qui évite qu'un appareil Samsung conserve une association
        // résiduelle sur un port source fixe et ignore le ClientHello suivant (timeout).
        // Le port réellement lié est rapporté par le helper après le bind (upstream #66/#77).
        $this->localPort = 0;
        $this->credential = call_user_func(array('LocalThingsOwnerPsk', 'validate'), $credential);
    }

    public function connect($timeout = 12.0)
    {
        if (is_resource($this->process)) { return; }
        $this->process = proc_open(array('python3', __DIR__ . '/../../resources/owner_psk_transport.py'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
            $this->pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($this->process)) { throw new RuntimeException(__('Transport OwnerPSK indisponible (Python 3 et libssl requis)', __FILE__)); }
        stream_set_blocking($this->pipes[1], false);
        try {
            $connectResult = $this->rpc(array('action' => 'connect', 'host' => $this->host, 'port' => $this->port,
                'source_port' => $this->localPort,
                'identity' => bin2hex(LocalThingsOwnerPsk::uuidBytes($this->credential['owner_uuid'])),
                'key' => $this->credential['key'], 'timeout' => $timeout), $timeout + 2);
            $this->adoptReportedSourcePort($connectResult);
            // Verify the device binding before exposing this session to normal reads/writes.
            $session = new LocalThingsSession($this);
            list($code, $payload) = $session->get('/oic/d', 8.0);
            $device = $code === 69 ? LocalThingsCbor::decode($payload) : array();
            if (!is_array($device) || ($device['di'] ?? '') !== $this->credential['device_uuid']) {
                throw new RuntimeException(__('Identité de l’appareil OwnerPSK non confirmée', __FILE__));
            }
        } catch (Throwable $error) { $this->close(); throw $error; }
    }

    private function rpc(array $request, $timeout)
    {
        if (!is_resource($this->process)) { throw new RuntimeException(__('Session OwnerPSK fermée', __FILE__)); }
        $data = json_encode($request) . "\n";
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($this->pipes[0], substr($data, $offset));
            if (!$written) { throw new RuntimeException(__('Transport OwnerPSK interrompu', __FILE__)); }
            $offset += $written;
        }
        fflush($this->pipes[0]);
        $deadline = microtime(true) + max(.1, $timeout); $response = '';
        while (microtime(true) < $deadline) {
            if (class_exists('LocalThingsDiscovery', false)) {
                LocalThingsDiscovery::checkpoint();
            }
            $chunk = stream_get_contents($this->pipes[1]);
            if ($chunk === false) {
                throw new RuntimeException(__('Lecture RPC OwnerPSK impossible', __FILE__) . ' [ownerpsk_rpc_invalid]');
            }
            if ($chunk !== '') {
                $response .= $chunk;
            }
            if (strlen($response) > 40000) {
                throw new RuntimeException(__('Réponse RPC OwnerPSK trop volumineuse', __FILE__) . ' [ownerpsk_rpc_invalid]');
            }
            if (strpos($response, "\n") !== false) {
                $decoded = json_decode($response, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException(__('Réponse RPC OwnerPSK invalide', __FILE__) . ' [ownerpsk_rpc_invalid]');
                }
                if (empty($decoded['ok'])) {
                    $code = (string) ($decoded['code'] ?? '');
                    $suffix = preg_match('/^ownerpsk_[a-z_]+$/D', $code) ? ' [' . $code . ']' : '';
                    throw new RuntimeException(__('Session OwnerPSK impossible ou interrompue', __FILE__) . $suffix);
                }
                return $decoded['result'] ?? null;
            }
            $status = @proc_get_status($this->process);
            if ((!is_array($status) || empty($status['running'])) && feof($this->pipes[1])) {
                throw new RuntimeException(__('Helper OwnerPSK interrompu', __FILE__) . ' [ownerpsk_helper_stopped]');
            }
            usleep(10000);
        }
        throw new RuntimeException(__('Délai OwnerPSK dépassé ou transport indisponible', __FILE__)
            . ' [ownerpsk_rpc_timeout]');
    }

    public function write($data) { $this->rpc(array('action' => 'write', 'data' => base64_encode($data)), 4.0); }
    public function readFrame($timeout)
    {
        $result = $this->rpc(array('action' => 'read', 'timeout' => $timeout), $timeout + 1);
        if ($result === null) { return null; }
        $frame = base64_decode($result, true);
        if ($frame === false) { throw new RuntimeException(__('Trame OwnerPSK invalide', __FILE__)); }
        return $frame;
    }
    public function errorSummary() { return 'Transport OwnerPSK'; }
    public function localPort() { return (int) $this->localPort; }

    /** Adopte le port source réellement lié par le helper (bind éphémère) pour les journaux. */
    private function adoptReportedSourcePort($result)
    {
        if (is_array($result) && isset($result['source_port'])) {
            $this->localPort = (int) $result['source_port'];
        }
    }

    /**
     * Sonde de carrier PSK à identité/clé jetables : prouve qu'un appareil parle
     * ECDHE-PSK (alerte 115/20/51) sans tenter un handshake certificat complet,
     * qui peut wedger un lave-linge PSK-only (upstream #504/#852). Aucun secret
     * réel n'est utilisé ni journalisé.
     *
     * @param string $host Adresse IPv4 cible.
     * @param int $port Port DTLS à sonder.
     * @param float $timeout Délai maximal de la sonde.
     * @return array<string,mixed> carrier (psk|unknown), outcome, alerte éventuelle.
     */
    public static function probeCarrier($host, $port, $timeout = 3.0)
    {
        $unknown = array('carrier' => 'unknown', 'outcome' => 'unavailable', 'alert' => null);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $port < 1 || $port > 65535) {
            return array('carrier' => 'unknown', 'outcome' => 'invalid_target', 'alert' => null);
        }
        $timeout = max(.2, min(10.0, (float) $timeout));
        $process = proc_open(array('python3', __DIR__ . '/../../resources/owner_psk_transport.py'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
            $pipes, null, null, array('bypass_shell' => true));
        if (!is_resource($process)) { return $unknown; }
        try {
            stream_set_blocking($pipes[1], false);
            $request = json_encode(array('action' => 'psk_probe', 'host' => $host, 'port' => (int) $port,
                'timeout' => $timeout), JSON_UNESCAPED_SLASHES) . "\n";
            fwrite($pipes[0], $request); fflush($pipes[0]);
            $deadline = microtime(true) + $timeout + 2.0; $response = '';
            while (microtime(true) < $deadline) {
                $chunk = stream_get_contents($pipes[1]);
                if ($chunk !== false && $chunk !== '') { $response .= $chunk; }
                if (strpos($response, "\n") !== false) {
                    $decoded = json_decode($response, true);
                    if (is_array($decoded) && !empty($decoded['ok']) && is_array($decoded['result'] ?? null)) {
                        return $decoded['result'];
                    }
                    return $unknown;
                }
                $status = @proc_get_status($process);
                if ((!is_array($status) || empty($status['running'])) && feof($pipes[1])) { break; }
                usleep(10000);
            }
            return array('carrier' => 'unknown', 'outcome' => 'timeout', 'alert' => null);
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            @proc_terminate($process); @proc_close($process);
        }
    }
    public function isConnected()
    {
        if (!is_resource($this->process)) { return false; }
        $status = @proc_get_status($this->process);
        return is_array($status) && !empty($status['running'])
            && isset($this->pipes[0], $this->pipes[1])
            && is_resource($this->pipes[0]) && is_resource($this->pipes[1]);
    }
    public function close()
    {
        if ($this->isConnected()) {
            try { $this->rpc(array('action' => 'close'), 2.0); } catch (Throwable $error) {}
        }
        foreach ($this->pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
        $this->pipes = array();
        if (is_resource($this->process)) {
            $status = @proc_get_status($this->process);
            if (is_array($status) && !empty($status['running'])) { @proc_terminate($this->process, 15); }
            try { @proc_close($this->process); } catch (Throwable $error) {}
        }
        $this->process = null;
    }
    public function __destruct() { $this->close(); }
}
