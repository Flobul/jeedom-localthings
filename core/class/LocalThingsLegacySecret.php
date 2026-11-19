<?php

/**
 * Stockage privé du jeton d'appareil legacy et de l'identifiant de certificat
 * associé, par identité stable d'appareil. Même pattern que LocalThingsOwnerPsk :
 * fichier .php gardé (404 si accès web direct), permissions 0600, écriture
 * atomique (temp + rename). Le jeton ne transite NI par la configuration eqLogic
 * NI par l'instantané IPC (durcissement N1 de la revue P3).
 *
 * Aucun jeton n'est journalisé.
 */
class LocalThingsLegacySecret
{
    private const PREFIX = "<?php http_response_code(404); exit; ?>\n";

    /** @var string */
    private $directory;

    public function __construct($dataDirectory)
    {
        $this->directory = rtrim((string) $dataDirectory, '/') . '/legacy';
    }

    /**
     * @param string $deviceId Identité stable de l'appareil.
     * @return string
     */
    private function path($deviceId)
    {
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '') {
            throw new InvalidArgumentException(__('Identifiant d’appareil legacy invalide', __FILE__));
        }
        return $this->directory . '/' . hash('sha256', $deviceId) . '.php';
    }

    /**
     * Charge le jeton + cert_id, ou null si absent.
     *
     * @param string $deviceId
     * @return array{token:string,cert_id:string}|null
     */
    public function load($deviceId)
    {
        $path = $this->path($deviceId);
        if (!is_file($path)) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (substr($raw, 0, strlen(self::PREFIX)) !== self::PREFIX) {
            return null;
        }
        $data = json_decode(substr($raw, strlen(self::PREFIX)), true);
        if (!is_array($data) || !isset($data['token']) || !is_string($data['token'])) {
            return null;
        }
        return array(
            'token' => $data['token'],
            'cert_id' => isset($data['cert_id']) && is_string($data['cert_id']) ? $data['cert_id'] : '',
        );
    }

    /**
     * Enregistre le jeton + cert_id de façon atomique (permissions 0600).
     *
     * @param string $deviceId
     * @param string $token
     * @param string $certId
     * @return void
     */
    public function save($deviceId, $token, $certId)
    {
        $path = $this->path($deviceId);
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(__('Stockage legacy inaccessible', __FILE__));
        }
        if (!chmod($this->directory, 0700)) {
            throw new RuntimeException(__('Permissions legacy non garanties', __FILE__));
        }
        $temporary = tempnam($this->directory, '.pending-');
        if ($temporary === false) {
            throw new RuntimeException(__('Stockage legacy inaccessible', __FILE__));
        }
        $protectedTemporary = $temporary . '.php';
        if (!rename($temporary, $protectedTemporary)) {
            unlink($temporary);
            throw new RuntimeException(__('Préparation du stockage legacy impossible', __FILE__));
        }
        $temporary = $protectedTemporary;
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException(__('Permissions legacy non garanties', __FILE__));
            }
            $data = self::PREFIX . json_encode(
                array('token' => (string) $token, 'cert_id' => (string) $certId),
                JSON_UNESCAPED_SLASHES
            );
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data) || !rename($temporary, $path)) {
                throw new RuntimeException(__('Enregistrement legacy impossible', __FILE__));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Supprime le secret d'un appareil (équipement retiré).
     *
     * @param string $deviceId
     * @return void
     */
    public function delete($deviceId)
    {
        $path = $this->path($deviceId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}