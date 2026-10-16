<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../core/class/localthings.class.php';
include_file('core', 'authentification', 'php');

if (!isConnect('admin')) {
    include_file('desktop', '404', 'php');
    die();
}

$plugin = plugin::byId('localthings');
$update = $plugin->getUpdate();
sendVarToJS('version', localthings::$_pluginVersion);
?>

<?php include_file('desktop', 'configuration', 'css', 'localthings'); ?>

<form class="form-horizontal" id="configuration_plugin_localthings">
    <div class="localthings-config-page">
        <header class="localthings-config-hero">
            <div class="localthings-config-identity">
                <div class="localthings-config-logo">
                    <img src="plugins/localthings/plugin_info/localthings_icon.png" alt="LocalThings">
                </div>
                <div>
                    <span class="localthings-config-eyebrow">{{Configuration du plugin}}</span>
                    <h2>LocalThings</h2>
                    <p>{{Communication locale avec les appareils Samsung compatibles, sans compte SmartThings ni jeton cloud.}}</p>
                </div>
            </div>
            <div class="localthings-config-meta">
                <span><i class="fas fa-code-branch"></i> {{Version}} <strong>v<?php echo htmlspecialchars((string) localthings::$_pluginVersion, ENT_QUOTES, 'UTF-8'); ?></strong></span>
                <?php if (is_object($update)) { ?>
                    <span><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars((string) $update->getLocalVersion(), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span><i class="fas fa-stream"></i> <?php echo htmlspecialchars((string) $update->getConfiguration('version', 'stable'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span><i class="fas fa-cloud-download-alt"></i> <?php echo htmlspecialchars((string) $update->getSource(), ENT_QUOTES, 'UTF-8'); ?></span>
                <?php } ?>
            </div>
            <div class="localthings-config-links">
                <a class="btn btn-sm localthings-config-link-documentation" target="_blank" rel="noopener noreferrer" href="<?php echo htmlspecialchars((string) $plugin->getDocumentation(), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-book"></i> {{Documentation}}
                </a>
                <a class="btn btn-sm localthings-config-link-changelog" target="_blank" rel="noopener noreferrer" href="<?php echo htmlspecialchars((string) $plugin->getChangelog(), ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="fas fa-list"></i> {{Changelog}}
                </a>
                <a class="btn btn-sm localthings-config-link-community" target="_blank" rel="noopener noreferrer" href="https://community.jeedom.com/tag/plugin-localthings">
                    <i class="fas fa-comments"></i> {{Communauté}}
                </a>
            </div>
        </header>

        <div class="alert alert-info localthings-config-notice">
            <i class="fas fa-network-wired"></i>
            {{LocalThings communique directement avec les appareils Samsung compatibles sur le réseau local. Aucun compte SmartThings ni jeton cloud n’est requis.}}
        </div>
        <div class="alert alert-warning localthings-config-notice">
            <i class="fas fa-exclamation-triangle"></i>
            {{Un appareil Samsung n’accepte qu’un client DTLS local actif. Désactivez les autres intégrations LocalThings qui contrôlent les mêmes appareils.}}
        </div>

        <section class="localthings-config-card">
            <div class="localthings-config-card-header">
                <i class="fas fa-search-location"></i>
                <div>
                    <h3>{{Découverte et communication}}</h3>
                    <p>{{Définissez les réseaux analysés, les intervalles de suivi et vérifiez le transport local.}}</p>
                </div>
            </div>
            <div class="localthings-config-card-body">
                <div class="form-group">
                    <label class="col-sm-4 control-label">
                        {{Plages réseau à analyser}}
                        <sup><i class="fas fa-question-circle" title="{{Notation CIDR, une plage par ligne ou séparées par une virgule ou un point-virgule. Si le champ est vide, le sous-réseau /24 de l’adresse interne Jeedom est utilisé. La découverte est limitée à 1 024 adresses.}}"></i></sup>
                    </label>
                    <div class="col-sm-5">
                        <textarea class="configKey form-control" data-l1key="discovery_networks" rows="3" placeholder="192.168.1.0/24&#10;192.168.2.0/24"></textarea>
                        <small class="help-block">
                            {{Notation CIDR, une plage par ligne ou séparées par une virgule ou un point-virgule.}}
                            {{Laissez vide pour utiliser automatiquement le sous-réseau /24 de l’adresse interne Jeedom.}}
                        </small>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">
                        {{Intervalle lorsque l’appareil est en ligne}}
                        <sup><i class="fas fa-question-circle" title="{{Cet intervalle est utilisé après une communication réussie avec l’appareil.}}"></i></sup>
                    </label>
                    <div class="col-sm-3">
                        <select class="configKey form-control" data-l1key="poll_interval_online">
                            <option value="10s">{{10 secondes}}</option>
                            <option value="20s">{{20 secondes}}</option>
                            <option value="30s">{{30 secondes}}</option>
                            <option value="1">1 {{min}}</option>
                            <option value="2">2 {{min}}</option>
                            <option value="3">3 {{min}}</option>
                            <option value="4">4 {{min}}</option>
                            <option value="5">5 {{min}}</option>
                            <option value="10">10 {{min}}</option>
                            <option value="15">15 {{min}}</option>
                            <option value="20">20 {{min}}</option>
                            <option value="30">30 {{min}}</option>
                            <option value="45">45 {{min}}</option>
                            <option value="60">{{1 heure}}</option>
                            <option value="120">{{2 heures}}</option>
                            <option value="240">{{4 heures}}</option>
                            <option value="360">{{6 heures}}</option>
                            <option value="720">{{12 heures}}</option>
                            <option value="1440">{{1 jour}}</option>
                        </select>
                        <p class="help-block">{{Un intervalle court permet de suivre rapidement les changements d’un appareil disponible.}}</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">
                        {{Intervalle lorsque l’appareil est hors ligne}}
                        <sup><i class="fas fa-question-circle" title="{{Cet intervalle est utilisé après un échec de communication, jusqu’au retour de l’appareil.}}"></i></sup>
                    </label>
                    <div class="col-sm-3">
                        <select class="configKey form-control" data-l1key="poll_interval_offline">
                            <option value="1">1 {{min}}</option>
                            <option value="2">2 {{min}}</option>
                            <option value="3">3 {{min}}</option>
                            <option value="4">4 {{min}}</option>
                            <option value="5">5 {{min}}</option>
                            <option value="10">10 {{min}}</option>
                            <option value="15">15 {{min}}</option>
                            <option value="20">20 {{min}}</option>
                            <option value="30">30 {{min}}</option>
                            <option value="45">45 {{min}}</option>
                            <option value="60">{{1 heure}}</option>
                            <option value="120">{{2 heures}}</option>
                            <option value="240">{{4 heures}}</option>
                            <option value="360">{{6 heures}}</option>
                            <option value="720">{{12 heures}}</option>
                            <option value="1440">{{1 jour}}</option>
                        </select>
                        <p class="help-block">{{Un intervalle plus long évite de solliciter inutilement un appareil indisponible.}}</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Transport DTLS}}</label>
                    <div class="col-sm-6">
                        <span id="localthings-transport-state" class="label label-default">{{Inconnu}}</span>
                        <button type="button" id="bt_localthings_test_transport" class="btn btn-default btn-sm">
                            <i class="fas fa-stethoscope"></i> {{Tester}}
                        </button>
                        <p class="help-block">{{Les requêtes CoAP et CBOR sont gérées en PHP. OpenSSL fournit uniquement le canal chiffré DTLS.}}</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="localthings-config-card">
            <div class="localthings-config-card-header">
                <i class="fas fa-certificate"></i>
                <div>
                    <h3>{{Certificats DTLS}}</h3>
                    <p>{{Contrôlez ou installez les certificats nécessaires à la communication locale sécurisée.}}</p>
                </div>
            </div>
            <div class="localthings-config-card-body">
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{État}}</label>
                    <div class="col-sm-7">
                        <span id="localthings-certificate-state" class="label label-default">{{Inconnu}}</span>
                        <span id="localthings-certificate-detail" class="help-block"></span>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Installation simplifiée}}</label>
                    <div class="col-sm-7">
                        <button type="button" id="bt_localthings_bootstrap_certificates" class="btn btn-warning">
                            <i class="fas fa-download"></i> {{Installer le bundle communautaire}}
                        </button>
                        <p class="help-block">{{Télécharge le bundle public utilisé par le projet LocalThings, puis le stocke localement avec des permissions restrictives.}}</p>
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-4 control-label">{{Installation manuelle}}</label>
                    <div class="col-sm-7">
                        <button type="button" id="bt_localthings_toggle_pem" class="btn btn-default">
                            <i class="fas fa-key"></i> {{Saisir un certificat et une clé}}
                        </button>
                    </div>
                </div>
                <div id="localthings-pem-panel" style="display:none;">
                    <div class="form-group">
                        <label class="col-sm-4 control-label">{{Chaîne de certificats PEM}}</label>
                        <div class="col-sm-7">
                            <textarea id="in_localthings_certificate" class="form-control" rows="9" autocomplete="off" spellcheck="false"></textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-sm-4 control-label">{{Clé privée PEM}}</label>
                        <div class="col-sm-7">
                            <textarea id="in_localthings_private_key" class="form-control" rows="9" autocomplete="new-password" spellcheck="false"></textarea>
                            <p class="help-block">{{Ces champs ne sont pas enregistrés dans le navigateur ni dans la configuration Jeedom.}}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-sm-7 col-sm-offset-4">
                            <button type="button" id="bt_localthings_install_certificates" class="btn btn-success">
                                <i class="fas fa-check"></i> {{Installer les certificats}}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</form>

<?php include_file('desktop', 'configuration', 'js', 'localthings'); ?>