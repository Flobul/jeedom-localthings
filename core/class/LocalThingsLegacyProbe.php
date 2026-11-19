<?php

/**
 * Détection de l'interface locale d'un appareil (upstream #887/#769/#883/#257).
 *
 * Distingue trois familles quand aucun service DTLS n'est détecté :
 *  - legacy 8888 ouvert : famille pré-DTLS (pont HTTP/TLS 1.0), bascule dédiée ;
 *  - CoAP public seul : pile OCF publique sans endpoint protégé joignable ;
 *  - ni DTLS, ni 8888, ni CoAP : appareil probablement cloud-only, sans interface
 *    locale exploitable — le message doit être clair, pas un échec ambigu.
 *
 * Lecture seule : n'écrit rien, ne journalise aucun identifiant sensible.
 */
class LocalThingsLegacyProbe
{
    /** Port du pont legacy pré-DTLS (upstream #168/#257). */
    public const LEGACY_PORT = 8888;

    /**
     * Pur : classe l'interface locale selon les preuves disponibles.
     *
     * @param bool $dtlsLive Un endpoint DTLS a répondu au ClientHello.
     * @param bool $legacy8888Open Le port TCP 8888 est ouvert.
     * @param bool $publicCoapResponded Le CoAP public 5683 a répondu.
     * @return string 'dtls'|'legacy_8888'|'ocf_public_only'|'cloud_only'
     */
    public static function localApiKind($dtlsLive, $legacy8888Open, $publicCoapResponded)
    {
        if ($dtlsLive) {
            return 'dtls';
        }
        if ($legacy8888Open) {
            return 'legacy_8888';
        }
        if ($publicCoapResponded) {
            return 'ocf_public_only';
        }
        return 'cloud_only';
    }

    /**
     * Teste si un port TCP est ouvert (connexion bornée, sans écriture).
     *
     * @param string $host Adresse IPv4.
     * @param int $port Port TCP.
     * @param float $timeout Délai de connexion en secondes.
     * @return bool
     */
    public static function tcpPortOpen($host, $port, $timeout = 2.0)
    {
        $host = trim((string) $host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            return false;
        }
        // Réutilise network::portOpen du core Jeedom (test d'ouverture TCP borné)
        // au lieu de refabriquer un fsockopen (revue K1). La validation IPv4/port
        // ci-dessus est la garde de sécurité propre au plugin ; le délai effectif
        // est celui du core. $timeout est conservé pour compatibilité d'appel.
        return network::portOpen($host, $port);
    }

    /**
     * Message clair quand aucune interface locale n'est détectée (cloud-only).
     * N'expose aucun identifiant sensible.
     *
     * @param string $host Adresse IPv4.
     * @return string
     */
    public static function noLocalApiMessage($host)
    {
        return __('Aucune interface locale détectée sur ', __FILE__) . $host
            . __(' (ni DTLS/OCF, ni legacy 8888). Cet appareil est probablement pilotable uniquement via le cloud SmartThings et ne peut pas être ajouté en local.', __FILE__);
    }
}