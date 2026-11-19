<?php

/**
 * Bootstrap du jeton d'appareil legacy (upstream legacy_http_token.py).
 *
 * L'appareil ne livre pas son jeton dans la réponse : on ouvre un écouteur
 * HTTPS/TLS 1.0 sur 8889, on demande un jeton sur 8888 avec un en-tête Host
 * pointant vers cet écouteur, et l'appareil rappelle en POSTant le jeton.
 * Le callback n'est accepté que depuis l'IP de l'appareil.
 *
 * Additif et isolé ; nécessite TLS 1.0 (voir LocalThingsLegacyClient::tls10Available).
 * Non validé sur matériel réel. Aucun jeton n'est journalisé.
 */
class LocalThingsLegacyToken
{
    private const PLACEHOLDER_TOKEN = 'xxxxxxxxxxx';
    private const MAX_CALLBACK_BYTES = 65536;
    private const RETRY_INTERVAL_S = 12.0;
    private const ACCEPT_POLL_S = 0.25;

    /**
     * Adresse de cette machine sur le réseau de l'appareil (via la table de
     * routage, pas le hostname : un conteneur résout son nom vers une adresse
     * que l'appareil ne peut pas joindre).
     *
     * @param string $host IP de l'appareil.
     * @return string|null
     */
    public static function localAddressFor($host)
    {
        $host = trim((string) $host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $probe = @stream_socket_client('udp://' . $host . ':9', $errno, $errstr, 1.0);
        if (!is_resource($probe)) {
            return null;
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $address = substr($name, 0, strrpos($name, ':'));
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $address : null;
    }

    /**
     * Obtient un jeton via le callback, ou null si aucun n'arrive (l'appareil
     * doit être éveillé et 8889 joignable). L'import manuel d'un jeton reste le
     * repli quand le réseau rend le callback impossible.
     *
     * @param string $host
     * @param int $port
     * @param string $certPem
     * @param string $keyPem
     * @param object|null $logger
     * @param float $timeout
     * @param string|null $listenAddress
     * @return string|null
     */
    public static function obtain($host, $port, $certPem, $keyPem, $logger = null, $timeout = 90.0, $listenAddress = null)
    {
        if (!LocalThingsLegacyClient::tls10Available()) {
            throw new RuntimeException(LocalThingsLegacyClient::tls10UnsupportedMessage());
        }
        $certFile = self::writeCert($certPem, $keyPem);
        $server = null;
        try {
            $serverContext = stream_context_create(array('ssl' => array(
                'local_cert' => $certFile,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'ciphers' => 'DEFAULT@SECLEVEL=0',
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER,
            )));
            $server = @stream_socket_server(
                'tls://0.0.0.0:' . LocalThingsLegacyClient::CALLBACK_PORT,
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $serverContext
            );
            if (!is_resource($server)) {
                throw new RuntimeException(__('Impossible d’ouvrir l’écouteur de callback legacy 8889.', __FILE__));
            }
            stream_set_blocking($server, false);
            $address = $listenAddress !== null ? $listenAddress : self::localAddressFor($host);
            if ($address === null) {
                throw new RuntimeException(__('Adresse de callback locale introuvable pour l’appareil legacy.', __FILE__));
            }
            $deadline = microtime(true) + max(5.0, min(180.0, (float) $timeout));
            $token = null;
            while ($token === null && microtime(true) < $deadline) {
                self::requestToken($host, $port, $address, $certFile);
                $waited = 0.0;
                while ($token === null && $waited < self::RETRY_INTERVAL_S && microtime(true) < $deadline) {
                    $read = array($server);
                    $write = null;
                    $except = null;
                    $ready = @stream_select($read, $write, $except, 0, (int) (self::ACCEPT_POLL_S * 1000000));
                    if ($ready === false || $ready === 0) {
                        $waited += self::ACCEPT_POLL_S;
                        continue;
                    }
                    $connection = @stream_socket_accept($server, 2.0);
                    if (!is_resource($connection)) {
                        $waited += self::ACCEPT_POLL_S;
                        continue;
                    }
                    $peer = stream_socket_get_name($connection, true);
                    $peerIp = substr($peer, 0, strrpos($peer, ':'));
                    if ($peerIp !== $host) {
                        fclose($connection);
                        $waited += self::ACCEPT_POLL_S;
                        continue;
                    }
                    $data = self::readRequest($connection);
                    $token = LocalThingsLegacyClient::extractToken($data);
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                    fclose($connection);
                    $waited += self::ACCEPT_POLL_S;
                }
            }
            return $token;
        } finally {
            if (is_resource($server)) {
                fclose($server);
            }
            if (file_exists($certFile)) {
                @unlink($certFile);
            }
        }
    }

    /**
     * Demande un jeton sur 8888 (Host pointe vers l'écouteur 8889).
     *
     * @param string $host
     * @param int $port
     * @param string $listenAddress
     * @param string $certFile
     * @return void
     */
    private static function requestToken($host, $port, $listenAddress, $certFile)
    {
        $context = stream_context_create(array('ssl' => array(
            'local_cert' => $certFile,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'ciphers' => 'DEFAULT@SECLEVEL=0',
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
        )));
        $socket = @stream_socket_client(
            'tls://' . $host . ':' . $port,
            $errno,
            $errstr,
            15.0,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            return;
        }
        stream_set_timeout($socket, 15);
        $body = '{}';
        $request = "POST /devicetoken/request HTTP/1.1\r\n"
            . 'Host: ' . $listenAddress . ':' . LocalThingsLegacyClient::CALLBACK_PORT . "\r\n"
            . "Content-Type: application/json\r\n"
            . 'DeviceToken: ' . self::PLACEHOLDER_TOKEN . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n" . $body;
        @fwrite($socket, $request);
        // Lit la réponse sans bloquer indéfiniment (l'appareil peut attendre le callback).
        $response = '';
        $start = microtime(true);
        while (!feof($socket) && microtime(true) - $start < 5.0) {
            $chunk = @fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);
    }

    /**
     * Lit en-têtes + corps d'une requête de callback (le jeton est dans le corps).
     *
     * @param resource $connection
     * @return string
     */
    private static function readRequest($connection)
    {
        stream_set_timeout($connection, 10);
        $data = '';
        while (strpos($data, "\r\n\r\n") === false && strlen($data) < self::MAX_CALLBACK_BYTES) {
            $chunk = @fread($connection, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        $split = strpos($data, "\r\n\r\n");
        if ($split === false) {
            return $data;
        }
        $head = substr($data, 0, $split);
        $body = substr($data, $split + 4);
        $length = 0;
        if (preg_match('/Content-Length\s*:\s*(\d+)/i', $head, $matches)) {
            $length = min((int) $matches[1], self::MAX_CALLBACK_BYTES);
        }
        while (strlen($body) < $length) {
            $chunk = @fread($connection, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        return $head . "\r\n\r\n" . $body;
    }

    /**
     * Écrit cert+clé dans un PEM temporaire.
     *
     * @param string $certPem
     * @param string $keyPem
     * @return string
     */
    private static function writeCert($certPem, $keyPem)
    {
        $path = tempnam(sys_get_temp_dir(), 'lt_legacytok_');
        if ($path === false) {
            throw new RuntimeException(__('Impossible de créer un fichier temporaire pour le jeton legacy.', __FILE__));
        }
        file_put_contents($path, $certPem . "\n" . $keyPem);
        return $path;
    }
}