<?php
if (!isConnect('admin')) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}

/**
 * Échappe une valeur avant son insertion dans le tableau de santé.
 *
 * @param mixed $value Valeur à afficher.
 * @return string
 */
function localthingsHealthEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$checks = localthings::health();
$eqLogics = localthings::byType('localthings');
$enabled = 0;
$online = 0;
$healthyChecks = 0;
foreach ($checks as $check) {
    if (!empty($check['state'])) {
        $healthyChecks++;
    }
}
foreach ($eqLogics as $eqLogic) {
    if (!$eqLogic->getIsEnable()) {
        continue;
    }
    $enabled++;
    $connected = $eqLogic->getCmd('info', 'connected');
    if (is_object($connected) && (int) $connected->execCmd() === 1) {
        $online++;
    }
}
?>
<?php include_file('desktop', 'health', 'css', 'localthings'); ?>
<div id="div_healthLocalthings">
    <header class="localthings-health-hero">
        <div class="localthings-health-hero-icon"><i class="fas fa-heartbeat"></i></div>
        <div class="localthings-health-hero-copy">
            <span class="localthings-health-eyebrow">{{Diagnostic du plugin}}</span>
            <h2>{{Santé LocalThings}}</h2>
            <p>{{État du transport local, des certificats et des équipements Samsung.}}</p>
        </div>
        <button type="button" class="btn localthings-health-refresh" id="bt_refreshHealthLocalthings">
            <i class="fas fa-sync-alt"></i> {{Rafraîchir}}
        </button>
    </header>

    <section class="localthings-health-summary" aria-label="{{Résumé de la santé LocalThings}}">
        <article class="localthings-health-summary-card">
            <span class="localthings-health-summary-icon"><i class="fas fa-microchip"></i></span>
            <div><span>{{Appareils}}</span><strong><?php echo count($eqLogics); ?></strong><small>{{configuré(s)}}</small></div>
        </article>
        <article class="localthings-health-summary-card">
            <span class="localthings-health-summary-icon"><i class="fas fa-power-off"></i></span>
            <div><span>{{Actifs}}</span><strong><?php echo (int) $enabled; ?></strong><small>{{activé(s) dans Jeedom}}</small></div>
        </article>
        <article class="localthings-health-summary-card">
            <span class="localthings-health-summary-icon"><i class="fas fa-wifi"></i></span>
            <div><span>{{En ligne}}</span><strong><?php echo (int) $online; ?></strong><small><?php echo max(0, $enabled - $online); ?> {{hors ligne}}</small></div>
        </article>
        <article class="localthings-health-summary-card">
            <span class="localthings-health-summary-icon"><i class="fas fa-stethoscope"></i></span>
            <div><span>{{Contrôles OK}}</span><strong><?php echo (int) $healthyChecks; ?>/<?php echo count($checks); ?></strong><small>{{diagnostics validés}}</small></div>
        </article>
    </section>

    <section class="localthings-health-panel">
        <div class="localthings-health-panel-header">
            <i class="fas fa-heartbeat"></i>
            <h3>{{État du plugin}}</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-condensed table-bordered">
                <thead>
                    <tr>
                        <th>{{Contrôle}}</th>
                        <th>{{État}}</th>
                        <th>{{Détail}}</th>
                        <th>{{Conseil}}</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $row) { ?>
                        <tr>
                            <td><?php echo localthingsHealthEscape($row['test']); ?></td>
                            <td>
                                <span class="label label-<?php echo !empty($row['state']) ? 'success' : 'danger'; ?>">
                                    <?php echo !empty($row['state']) ? 'OK' : 'NOK'; ?>
                                </span>
                            </td>
                            <td><?php echo localthingsHealthEscape($row['result']); ?></td>
                            <td><?php echo localthingsHealthEscape($row['advice']); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="localthings-health-panel">
        <div class="localthings-health-panel-header">
            <i class="fas fa-microchip"></i>
            <h3>{{Équipements}}</h3>
        </div>
        <div class="table-responsive">
            <table class="table table-condensed table-bordered table-striped" id="table_healthLocalthings">
                <thead>
                    <tr>
                        <th>{{Nom}}</th>
                        <th>{{Type}}</th>
                        <th>{{Modèle}}</th>
                        <th>{{Adresse}}</th>
                        <th>{{État}}</th>
                        <th>{{Dernière communication}}</th>
                        <th>{{Dernière erreur}}</th>
                        <th>{{Actions}}</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eqLogics as $eqLogic) {
                        $connected = $eqLogic->getCmd('info', 'connected');
                        $isOnline = $eqLogic->getIsEnable()
                            && is_object($connected)
                            && (int) $connected->execCmd() === 1;
                        $host = (string) $eqLogic->getConfiguration('host', '');
                        $port = (int) $eqLogic->getConfiguration('port', 0);
                        $lastCommunication = (string) $eqLogic->getConfiguration('last_communication', '');
                        $lastError = (string) $eqLogic->getConfiguration('last_error', '');
                    ?>
                        <tr<?php echo $eqLogic->getIsEnable() ? '' : ' class="localthings-health-disabled"'; ?>>
                            <td>
                                <a href="<?php echo localthingsHealthEscape($eqLogic->getLinkToConfiguration()); ?>">
                                    <?php echo localthingsHealthEscape($eqLogic->getHumanName()); ?>
                                </a>
                            </td>
                            <td><?php echo localthingsHealthEscape($eqLogic->getConfiguration('device_type', '')); ?></td>
                            <td><?php echo localthingsHealthEscape($eqLogic->getConfiguration('model', '')); ?></td>
                            <td><code><?php echo localthingsHealthEscape($host . ($port > 0 ? ':' . $port : '')); ?></code></td>
                            <td>
                                <?php if (!$eqLogic->getIsEnable()) { ?>
                                    <span class="label label-default">{{Désactivé}}</span>
                                <?php } elseif ($isOnline) { ?>
                                    <span class="label label-success">{{En ligne}}</span>
                                <?php } else { ?>
                                    <span class="label label-danger">{{Hors ligne}}</span>
                                <?php } ?>
                            </td>
                            <td><?php echo localthingsHealthEscape($lastCommunication ?: '—'); ?></td>
                            <td class="text-danger localthings-health-error"><?php echo localthingsHealthEscape($lastError ?: '—'); ?></td>
                            <td>
                                <button type="button" class="btn btn-default btn-xs bt_testHealthCommunication"
                                        data-eqlogic_id="<?php echo (int) $eqLogic->getId(); ?>"
                                        data-title="{{Tester la communication}}">
                                    <i class="fas fa-satellite-dish"></i>
                                </button>
                            </td>
                        </tr>
                    <?php } ?>
                    <?php if (count($eqLogics) === 0) { ?>
                        <tr><td colspan="8" class="text-center text-muted">{{Aucun équipement LocalThings}}</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include_file('desktop', 'health', 'js', 'localthings'); ?>