<?php

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    require_once dirname(__FILE__) . '/../class/localthings.class.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();
    $action = (string) init('action');

    switch ($action) {
        case 'scan':
            ajax::success(localthings::synchronize());
            break;
        case 'scanStatus':
            ajax::success(localthings::scanStatus());
            break;
        case 'stopScan':
            ajax::success(localthings::stopDiscovery((string) init('job_id')));
            break;
        case 'probe':
            ajax::success(localthings::probeHost((string) init('host')));
            break;
        case 'ownerPskDiagnostic':
            ajax::success(localthings::deviceClient('localthings_ownerpsk')->ownerPskDiagnostic((string) init('host')));
            break;
        case 'importOwnerPsk':
            ajax::success(localthings::deviceClient()->importOwnerPsk((string) init('host'), array(
                'owner_uuid' => (string) init('owner_uuid'), 'device_uuid' => (string) init('device_uuid'),
                'key' => (string) init('key')), init('port')));
            break;
        case 'decodeOwnerPskDatabase':
            $upload = $_FILES['database'] ?? null;
            if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception(__('Le fichier oic_svr_db_client.dat n’a pas été reçu', __FILE__));
            }
            $size = (int) ($upload['size'] ?? 0);
            $temporary = (string) ($upload['tmp_name'] ?? '');
            if ($size < 1 || $size > LocalThingsOwnerPskImporter::MAX_DATABASE_BYTES
                || $temporary === '' || !is_uploaded_file($temporary)) {
                throw new Exception(__('Fichier OwnerPSK invalide ou trop volumineux', __FILE__));
            }
            $database = file_get_contents($temporary);
            if (!is_string($database) || strlen($database) !== $size) {
                throw new Exception(__('Lecture du fichier OwnerPSK impossible', __FILE__));
            }
            $result = LocalThingsOwnerPskImporter::decodeDatabase($database);
            $known = array();
            foreach (localthings::byType('localthings') as $equipment) {
                $deviceId = preg_replace(
                    '/[^a-f0-9]/',
                    '',
                    strtolower(trim((string) $equipment->getConfiguration('device_id', '')))
                );
                if (is_string($deviceId) && strlen($deviceId) === 32) {
                    $known[$deviceId] = array(
                        'id' => (int) $equipment->getId(),
                        'name' => (string) $equipment->getName(),
                        'host' => (string) $equipment->getConfiguration('host', ''),
                        'port' => (int) $equipment->getConfiguration('port', 0),
                    );
                }
            }
            foreach ($result['credentials'] as &$credential) {
                $deviceId = str_replace('-', '', (string) $credential['device_uuid']);
                $credential['equipment'] = $known[$deviceId] ?? null;
            }
            unset($credential, $database);
            ajax::success($result);
            break;
        case 'probeOwnerPskHosts':
            $hosts = json_decode((string) init('hosts'), true);
            if (!is_array($hosts)) {
                throw new Exception(__('Liste d’adresses OwnerPSK invalide', __FILE__));
            }
            ajax::success(localthings::probeHosts($hosts));
            break;
        case 'certificateDiagnostic':
            ajax::success(
                localthings::compareCertificates((string) init('host'), init('port'))
            );
            break;
        case 'refresh':
            $eqLogic = localthings::byId((int) init('id'));
            if (!is_object($eqLogic)) {
                throw new Exception(__('Équipement LocalThings introuvable', __FILE__));
            }
            ajax::success($eqLogic->refresh());
            break;
        case 'testCommunication':
            $eqLogic = localthings::byId((int) init('id'));
            if (!is_object($eqLogic)) {
                throw new Exception(__('Équipement LocalThings introuvable', __FILE__));
            }
            ajax::success($eqLogic->testCommunication());
            break;
        case 'transportStatus':
            ajax::success(localthings::transportStatus());
            break;
        case 'certificateStatus':
            ajax::success(localthings::certificateStatus());
            break;
        case 'bootstrapCertificates':
            ajax::success(localthings::bootstrapCertificates());
            break;
        case 'installCertificates':
            ajax::success(
                localthings::installCertificates(
                    (string) init('certificate'),
                    (string) init('private_key')
                )
            );
            break;
        default:
            throw new Exception(__('Aucune méthode correspondante : ', __FILE__) . $action);
    }
} catch (Exception $exception) {
    ajax::error(displayException($exception), $exception->getCode());
}
