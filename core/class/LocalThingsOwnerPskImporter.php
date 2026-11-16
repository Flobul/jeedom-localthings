<?php

require_once __DIR__ . '/LocalThingsProtocol.php';

/**
 * Extracts already-provisioned OwnerPSK credentials from SmartThings' iOS
 * oic_svr_db_client.dat store. The importer is read-only: no OCF security write
 * and no persistence happen while decoding the uploaded file.
 */
class LocalThingsOwnerPskImporter
{
    public const MAX_DATABASE_BYTES = 2097152;
    private const MAX_CBOR_DEPTH = 24;
    private const MAX_CBOR_ITEMS = 10000;

    /**
     * @param string $database Binary contents of oic_svr_db_client.dat.
     * @return array<string,mixed>
     */
    public static function decodeDatabase($database)
    {
        if (!is_string($database) || $database === '' || strlen($database) > self::MAX_DATABASE_BYTES) {
            throw new InvalidArgumentException(__('Fichier CBOR OwnerPSK vide ou trop volumineux', __FILE__));
        }

        try {
            $consumed = 0;
            $root = LocalThingsCbor::decodeLimited(
                $database,
                self::MAX_CBOR_DEPTH,
                self::MAX_CBOR_ITEMS,
                $consumed
            );
            if ($consumed !== strlen($database) || !is_array($root)) {
                throw new UnexpectedValueException('invalid root');
            }
            $credentialResource = self::credentialResource($root);
        } catch (Throwable $error) {
            throw new InvalidArgumentException(__('Fichier oic_svr_db_client.dat invalide ou non pris en charge', __FILE__));
        }

        $records = $credentialResource['creds'] ?? null;
        if (!is_array($records)) {
            throw new InvalidArgumentException(__('Aucune liste de certificats OCF trouvée dans le fichier', __FILE__));
        }

        $defaultOwner = $credentialResource['rowneruuid'] ?? null;
        $credentials = array();
        $invalid = 0;
        $pskRecords = 0;
        $invalidReasons = array();
        foreach ($records as $record) {
            if (!is_array($record) || (int) ($record['credtype'] ?? 0) !== 1) {
                continue;
            }
            $pskRecords++;
            try {
                $ownerUuid = self::normalizeUuid($record['rowneruuid'] ?? $defaultOwner);
            } catch (Throwable $error) {
                self::markInvalid($invalid, $invalidReasons, 'owner_uuid_invalid');
                continue;
            }
            try {
                $deviceUuid = self::normalizeUuid($record['subjectuuid'] ?? null);
            } catch (Throwable $error) {
                self::markInvalid($invalid, $invalidReasons, 'device_uuid_invalid');
                continue;
            }
            $privateData = $record['privatedata'] ?? null;
            if (!is_array($privateData) || !array_key_exists('data', $privateData)) {
                self::markInvalid($invalid, $invalidReasons, 'private_data_missing');
                continue;
            }
            try {
                $key = self::normalizeKey($privateData['data']);
            } catch (Throwable $error) {
                self::markInvalid($invalid, $invalidReasons, 'key_invalid');
                continue;
            }
            $deduplicationKey = $ownerUuid . '|' . $deviceUuid . '|' . $key;
            $credentials[$deduplicationKey] = array(
                'owner_uuid' => $ownerUuid,
                'device_uuid' => $deviceUuid,
                'key' => $key,
                'key_bytes' => (int) (strlen($key) / 2),
            );
        }

        return array(
            'total_credentials' => count($records),
            'psk_records' => $pskRecords,
            'psk_credentials' => count($credentials),
            'invalid_psk_credentials' => $invalid,
            'invalid_reasons' => $invalidReasons,
            'credentials' => array_values($credentials),
        );
    }

    /** @return array<string,mixed> */
    private static function credentialResource(array $root)
    {
        $resource = array_key_exists('cred', $root) ? $root['cred'] : $root;
        for ($depth = 0; $depth < 2 && is_string($resource); $depth++) {
            $consumed = 0;
            $decoded = LocalThingsCbor::decodeLimited(
                $resource,
                self::MAX_CBOR_DEPTH,
                self::MAX_CBOR_ITEMS,
                $consumed
            );
            if ($consumed !== strlen($resource)) {
                throw new UnexpectedValueException('invalid nested resource');
            }
            $resource = $decoded;
        }
        if (!is_array($resource)) {
            throw new UnexpectedValueException('invalid credential resource');
        }
        return $resource;
    }

    private static function normalizeUuid($value)
    {
        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException('missing uuid');
        }
        if (strlen($value) === 16) {
            $value = bin2hex($value);
        } else {
            $value = strtolower(trim($value));
            if (strpos($value, 'urn:uuid:') === 0) {
                $value = substr($value, 9);
            }
            $value = str_replace('-', '', $value);
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1 || $value === str_repeat('0', 32)) {
            throw new UnexpectedValueException('invalid uuid');
        }
        return substr($value, 0, 8) . '-'
            . substr($value, 8, 4) . '-'
            . substr($value, 12, 4) . '-'
            . substr($value, 16, 4) . '-'
            . substr($value, 20, 12);
    }

    private static function normalizeKey($value)
    {
        if (!is_string($value) || $value === '') {
            throw new UnexpectedValueException('missing key');
        }
        $trimmed = trim($value);
        if (preg_match('/^(?:[a-fA-F0-9]{32}|[a-fA-F0-9]{64})$/D', $trimmed) === 1) {
            return strtolower($trimmed);
        }
        if (strlen($value) === 16 || strlen($value) === 32) {
            return bin2hex($value);
        }
        $decoded = base64_decode($trimmed, true);
        if (is_string($decoded) && (strlen($decoded) === 16 || strlen($decoded) === 32)) {
            return bin2hex($decoded);
        }
        throw new UnexpectedValueException('invalid key');
    }


    private static function markInvalid(&$invalid, array &$reasons, $reason)
    {
        $invalid++;
        $reasons[$reason] = (int) ($reasons[$reason] ?? 0) + 1;
    }
}
