<?php

/**
 * Transport legacy pré-DTLS (pont HTTP/TLS 1.0 sur 8888), famille FlexDry/TP6X/
 * dongles (upstream #168/#257/#887). Additif et isolé du chemin DTLS/OCF : il
 * n'est tenté que lorsqu'aucun DTLS n'est détecté mais que 8888 est ouvert.
 *
 * LIMITE ENVIRONNEMENT : l'appareil n'offre que TLS 1.0 (un ClientHello TLS 1.2
 * est rejeté). Les builds OpenSSL récents (3.x durci, 4.x) ont retiré TLS 1.0 ;
 * `tls10Available()` sonde la capacité réelle et le transport échoue alors avec
 * un message clair plutôt qu'une erreur de handshake ambiguë. Non validé sur
 * matériel réel ; la traduction et l'extraction de jeton sont testées hors TLS.
 *
 * Lecture seule par défaut ; aucun jeton ni secret n'est journalisé.
 */
class LocalThingsLegacyClient
{
    public const LEGACY_PORT = 8888;
    public const CALLBACK_PORT = 8889;
    private const PREFIX = 'x.com.samsung.da.';
    private const PLACEHOLDER_TOKEN = 'xxxxxxxxxxx';

    /** @var string */
    private $host;
    /** @var int */
    private $port;
    /** @var string */
    private $certPem;
    /** @var string */
    private $keyPem;
    /** @var string|null */
    private $token;
    /** @var object|null */
    private $logger;
    /** @var float */
    private $timeout;

    public function __construct($host, $port, $certPem, $keyPem, $token, $logger = null, $timeout = 15.0)
    {
        $this->host = trim((string) $host);
        $this->port = (int) $port;
        $this->certPem = (string) $certPem;
        $this->keyPem = (string) $keyPem;
        $this->token = $token !== null && $token !== '' ? (string) $token : null;
        $this->logger = $logger;
        $this->timeout = max(1.0, min(60.0, (float) $timeout));
    }

    /**
     * Pur : traduit un corps 8888 indexé par wrapper en ressources OCF {href: rep}.
     * Table fidèle à l'amont (legacy_http.py RESOURCES) ; un wrapper absent est
     * simplement omis, comme un href absent d'un batch /device/0.
     *
     * @param array<string,mixed> $body Corps JSON /devices/0 (wrapper-keyed).
     * @return array<string,array<string,mixed>>
     */
    public static function toResources($body)
    {
        if (!is_array($body)) {
            return array();
        }
        $out = array();
        foreach (self::resourceTable() as $resource) {
            $wrapper = $resource['wrapper'];
            if (!isset($body[$wrapper])) {
                continue;
            }
            $data = $body[$wrapper];
            if (!empty($resource['as_items'])) {
                if (!is_array($data)) {
                    continue;
                }
                $out[$resource['href']] = array(self::PREFIX . 'items' => array_values($data));
                continue;
            }
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $name => $value) {
                $field = isset($resource['rename'][$name]) ? $resource['rename'][$name] : $name;
                $href = isset($resource['fan_out'][$name]) ? $resource['fan_out'][$name] : $resource['href'];
                if (!isset($out[$href])) {
                    $out[$href] = array();
                }
                $out[$href][self::PREFIX . $field] = $value;
            }
        }
        return $out;
    }

    /**
     * Table wrapper -> href canonique (fidèle à l'amont, sans les sémantiques
     * cycle/start non portées). Les champs non nommés se traduisent mécaniquement.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function resourceTable()
    {
        return array(
            array('wrapper' => 'Operation', 'href' => '/operational/state/vs/0',
                'fan_out' => array('power' => '/power/vs/0', 'kidsLock' => '/kidslock/vs/0')),
            array('wrapper' => 'Mode', 'href' => '/mode/vs/0'),
            array('wrapper' => 'Washer', 'href' => '/washer/vs/0'),
            array('wrapper' => 'Oven', 'href' => '/oven/vs/0'),
            array('wrapper' => 'Fridge', 'href' => '/refrigeration/vs/0'),
            array('wrapper' => 'Temperatures', 'href' => '/temperatures/vs/0', 'as_items' => true),
            array('wrapper' => 'Doors', 'href' => '/doors/vs/0', 'as_items' => true),
            array('wrapper' => 'Wind', 'href' => '/airflow/vs/0'),
            array('wrapper' => 'Configuration', 'href' => '/remotectrl/vs/0'),
            array('wrapper' => 'Information', 'href' => '/information/vs/0',
                'rename' => array('modelID' => 'modelNum', 'serialNumber' => 'serialNum')),
            array('wrapper' => 'Diagnosis', 'href' => '/diagnosis/vs/0'),
            array('wrapper' => 'Alarms', 'href' => '/alarms/vs/0', 'as_items' => true),
            array('wrapper' => 'Sensors', 'href' => '/sensors/vs/0', 'as_items' => true,
                'rename' => array('values' => 'value')),
            array('wrapper' => 'Consumables', 'href' => '/consumable/vs/0', 'as_items' => true),
        );
    }

    /**
     * Pur : extrait un jeton d'appareil (8 hex) d'un corps de callback JSON.
     *
     * @param string $data Corps de la requête de callback.
     * @return string|null
     */
    public static function extractToken($data)
    {
        if (preg_match('/"DeviceToken"\s*:\s*"([0-9a-f]{8})"/i', (string) $data, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Pur : identifiant d'appareil legacy stable, fondé sur le numéro de série
     * (ou le modèle, ou l'hôte à défaut) plutôt que sur la seule IP. Un changement
     * de bail DHCP ne crée ainsi pas un équipement double (D3 revue P3).
     *
     * @param string $serial Numéro de série (/information).
     * @param string $model Modèle (/information).
     * @param string $host IP de repli.
     * @return string
     */
    public static function stableDeviceId($serial, $model, $host)
    {
        $serial = trim((string) $serial);
        $model = trim((string) $model);
        $basis = $serial !== '' ? $serial : ($model !== '' ? $model : (string) $host);
        return 'legacy-' . substr(hash('sha256', $basis), 0, 16);
    }

    /**
     * Pur : retire toute action writable des entités (appareil legacy en lecture
     * seule tant que le contrat d'écriture POST n'est pas porté/validé). Les
     * informations restent lisibles ; aucune commande d'action n'est matérialisée.
     *
     * @param array<int,array<string,mixed>> $entities
     * @return array<int,array<string,mixed>>
     */
    public static function readOnlyEntities($entities)
    {
        if (!is_array($entities)) {
            return array();
        }
        foreach ($entities as &$entity) {
            if (is_array($entity)) {
                $entity['actions'] = array();
                $entity['read_only'] = true;
            }
        }
        unset($entity);
        return $entities;
    }

    /**
     * Garde environnement : TLS 1.0 est-il réellement utilisable par cette
     * bibliothèque SSL ? Sonde boucle locale bornée (résultat mis en cache).
     * OpenSSL 4.x a retiré TLS 1.0 ; certains builds 3.x le désactivent aussi.
     *
     * @return bool
     */
    public static function tls10Available()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = false;
        if (!defined('STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT') || !defined('STREAM_CRYPTO_METHOD_TLSv1_0_SERVER')) {
            return false;
        }
        $cert = self::probeCertificate();
        if ($cert === null) {
            return false;
        }
        try {
            $serverContext = stream_context_create(array('ssl' => array(
                'local_cert' => $cert,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'ciphers' => 'DEFAULT@SECLEVEL=0',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
            )));
            $server = @stream_socket_server(
                'tls://127.0.0.1:0',
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $serverContext
            );
            if (!is_resource($server)) {
                return false;
            }
            $name = stream_socket_get_name($server, false);
            $port = (int) substr(strrchr($name, ':'), 1);
            $clientContext = stream_context_create(array('ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'ciphers' => 'DEFAULT@SECLEVEL=0',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
            )));
            $client = @stream_socket_client(
                'tls://127.0.0.1:' . $port,
                $errno2,
                $errstr2,
                2.0,
                STREAM_CLIENT_CONNECT,
                $clientContext
            );
            if (is_resource($client)) {
                $accepted = @stream_socket_accept($server, 2.0);
                if (is_resource($accepted)) {
                    $cache = true;
                    fclose($accepted);
                }
                fclose($client);
            }
            fclose($server);
            return $cache;
        } finally {
            // Le PEM de sonde contient une clé privée (jetable) : il ne doit pas
            // rester sur disque, quel que soit le chemin de sortie (D2 revue P3).
            @unlink($cert);
        }
    }

    /**
     * Certificat autosigné jetable pour la sonde TLS 1.0 (aucun secret réel).
     *
     * @return string|null Chemin d'un PEM cert+clé, ou null.
     */
    private static function probeCertificate()
    {
        if (!function_exists('openssl_pkey_new')) {
            return null;
        }
        $key = @openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
        if ($key === false) {
            return null;
        }
        $csr = @openssl_csr_new(array('commonName' => 'localhost'), $key);
        if ($csr === false) {
            return null;
        }
        $x509 = @openssl_csr_sign($csr, null, $key, 1);
        if ($x509 === false) {
            return null;
        }
        $certOut = '';
        $keyOut = '';
        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($key, $keyOut);
        $path = tempnam(sys_get_temp_dir(), 'lt_tls10_');
        if ($path === false) {
            return null;
        }
        file_put_contents($path, $certOut . $keyOut);
        return $path;
    }

    /**
     * Lit un appareil legacy et traduit ses ressources. Nécessite TLS 1.0.
     *
     * @param int $index Index du sous-appareil (0 = racine).
     * @return array<string,array<string,mixed>> Ressources OCF-like.
     */
    public function readDevice($index = 0)
    {
        if (!self::tls10Available()) {
            throw new RuntimeException(self::tls10UnsupportedMessage());
        }
        if ($this->token === null) {
            throw new RuntimeException(__('Aucun jeton d’appareil legacy disponible ; appairage requis.', __FILE__));
        }
        $response = $this->request('GET', '/devices/' . max(0, (int) $index));
        if ($response['status'] === 401) {
            throw new RuntimeException(__('Jeton d’appareil legacy refusé (401).', __FILE__));
        }
        if ($response['status'] === 403 && strpos($response['body'], 'SHE-001') !== false) {
            throw new RuntimeException(__('Contrôle à distance désactivé sur l’appareil legacy (SHE-001).', __FILE__));
        }
        if ($response['status'] >= 400) {
            throw new RuntimeException(__('Erreur HTTP legacy : ', __FILE__) . $response['status']);
        }
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException(__('Réponse legacy illisible (JSON attendu).', __FILE__));
        }
        return self::toResources($decoded);
    }

    /**
     * Message clair quand TLS 1.0 n'est pas supporté par la bibliothèque SSL.
     *
     * @return string
     */
    public static function tls10UnsupportedMessage()
    {
        return __('Cet appareil legacy (8888) n’accepte que TLS 1.0, non supporté par la bibliothèque SSL de ce serveur (OpenSSL récent). Le pilotage local legacy nécessite un hôte Jeedom dont OpenSSL autorise encore TLS 1.0.', __FILE__);
    }

    /**
     * Requête HTTP sur le pont TLS 1.0. Parsing tolérant (ces firmwares émettent
     * des réponses non standard : espace avant le deux-points des en-têtes).
     *
     * @param string $method
     * @param string $path
     * @param string|null $body
     * @param array<string,string> $extraHeaders
     * @return array{status:int,body:string}
     */
    private function request($method, $path, $body = null, $extraHeaders = array())
    {
        $certFile = $this->writeClientCert();
        try {
            $context = stream_context_create(array('ssl' => array(
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'ciphers' => 'DEFAULT@SECLEVEL=0',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
            )));
            $socket = @stream_socket_client(
                'tls://' . $this->host . ':' . $this->port,
                $errno,
                $errstr,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );
            if (!is_resource($socket)) {
                throw new RuntimeException(__('Connexion legacy TLS 1.0 impossible vers le pont 8888.', __FILE__));
            }
            stream_set_timeout($socket, (int) ceil($this->timeout));
            $headers = array(
                'Host: ' . $this->host . ':' . $this->port,
                'Accept: */*',
                'Connection: close',
            );
            if ($this->token !== null) {
                $headers[] = 'Authorization: Bearer ' . $this->token;
            }
            foreach ($extraHeaders as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $payload = $body !== null ? $body : '';
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($payload);
            }
            $request = $method . ' ' . $path . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $payload;
            fwrite($socket, $request);
            $raw = '';
            while (!feof($socket)) {
                $chunk = fread($socket, 8192);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $raw .= $chunk;
                if (strlen($raw) > 2000000) {
                    break;
                }
            }
            fclose($socket);
            return self::parseResponse($raw);
        } finally {
            if (is_string($certFile) && file_exists($certFile)) {
                @unlink($certFile);
            }
        }
    }

    /**
     * Pur : parse une réponse HTTP brute (statut + corps), tolérant aux en-têtes
     * non standard. Sépare en-têtes et corps sur la première ligne vide.
     *
     * @param string $raw
     * @return array{status:int,body:string}
     */
    public static function parseResponse($raw)
    {
        $raw = (string) $raw;
        $status = 0;
        if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#i', $raw, $matches)) {
            $status = (int) $matches[1];
        }
        $body = $raw;
        $split = strpos($raw, "\r\n\r\n");
        if ($split !== false) {
            $body = substr($raw, $split + 4);
        }
        return array('status' => $status, 'body' => $body);
    }

    /**
     * Écrit cert+clé client dans un PEM temporaire (effacé après la requête).
     *
     * @return string
     */
    private function writeClientCert()
    {
        $path = tempnam(sys_get_temp_dir(), 'lt_legacy_');
        if ($path === false) {
            throw new RuntimeException(__('Impossible de créer un fichier temporaire pour le certificat legacy.', __FILE__));
        }
        file_put_contents($path, $this->certPem . "\n" . $this->keyPem);
        return $path;
    }
}