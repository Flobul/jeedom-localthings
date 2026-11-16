"use strict";

(function () {
  const root = document.getElementById("div_localthings");
  const endpoint = "plugins/localthings/core/ajax/localthings.ajax.php";
  if (!root) return;

  function ajax(action, data, success, failure) {
    domUtils.ajax({
      type: "POST",
      url: endpoint,
      data: Object.assign({ action: action }, data || {}),
      dataType: "json",
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
        if (failure) failure(error);
      },
      success: function (response) {
        if (response.state !== "ok") {
          jeedomUtils.showAlert({ message: response.result, level: "danger" });
          if (failure) failure(response.result);
          return;
        }
        if (success) success(response.result);
      },
    });
  }

  function ajaxPromise(action, data) {
    return new Promise(function (resolve, reject) {
      ajax(action, data, resolve, reject);
    });
  }

  let ownerPskCredentials = [];

  function ownerPskStatus(index, message, level) {
    const cell = document.querySelector('[data-ownerpsk-status="' + index + '"]');
    if (!cell) return;
    cell.textContent = message;
    cell.className = level ? "text-" + level : "";
  }

  function renderOwnerPskCredentials(result) {
    ownerPskCredentials = Array.isArray(result.credentials) ? result.credentials : [];
    const summary = document.getElementById("localthings-ownerpsk-database-summary");
    const container = document.getElementById("localthings-ownerpsk-credentials");
    const body = document.getElementById("tb_ownerPskCredentials");
    body.replaceChildren();
    const invalidReasonLabels = {
      owner_uuid_invalid: "{{UUID propriétaire absent ou invalide}}",
      owner_uuid_transport_incompatible: "{{identité propriétaire incompatible avec le transport DTLS}}",
      device_uuid_invalid: "{{UUID appareil absent ou invalide}}",
      private_data_missing: "{{clé privée absente}}",
      key_invalid: "{{format ou longueur de clé non pris en charge}}",
    };
    const invalidReasons = Object.entries(result.invalid_reasons || {}).map(function (entry) {
      return (invalidReasonLabels[entry[0]] || "{{credential non pris en charge}}")
        + " : " + Number(entry[1]);
    });
    summary.textContent = "{{Enregistrements OCF}} : " + Number(result.total_credentials || 0)
      + " · {{credentials PSK détectés}} : " + Number(result.psk_records || 0)
      + " · {{clés OwnerPSK utilisables}} : " + ownerPskCredentials.length
      + (Number(result.invalid_psk_credentials || 0) > 0
        ? " · {{clés ignorées}} : " + Number(result.invalid_psk_credentials)
          + (invalidReasons.length ? " (" + invalidReasons.join(" ; ") + ")" : "")
        : "");
    container.style.display = ownerPskCredentials.length ? "block" : "none";

    ownerPskCredentials.forEach(function (credential, index) {
      const row = document.createElement("tr");
      row.dataset.ownerpskIndex = String(index);

      const selectCell = document.createElement("td");
      const select = document.createElement("input");
      select.type = "checkbox";
      select.className = "ownerpsk-select";
      select.value = String(index);
      select.setAttribute("aria-label", "{{Sélectionner cette clé}}");
      selectCell.appendChild(select);
      row.appendChild(selectCell);

      const deviceCell = document.createElement("td");
      if (credential.equipment && credential.equipment.name) {
        const name = document.createElement("strong");
        name.textContent = String(credential.equipment.name);
        deviceCell.appendChild(name);
        deviceCell.appendChild(document.createElement("br"));
      }
      const deviceUuid = document.createElement("code");
      deviceUuid.textContent = String(credential.device_uuid);
      deviceCell.appendChild(deviceUuid);
      row.appendChild(deviceCell);

      const ownerCell = document.createElement("td");
      const ownerUuid = document.createElement("code");
      ownerUuid.textContent = String(credential.owner_uuid);
      ownerCell.appendChild(ownerUuid);
      row.appendChild(ownerCell);

      const keyCell = document.createElement("td");
      const keyGroup = document.createElement("div");
      keyGroup.className = "input-group";
      const keyInput = document.createElement("input");
      keyInput.type = "password";
      keyInput.className = "form-control input-sm ownerpsk-key";
      keyInput.readOnly = true;
      keyInput.value = String(credential.key);
      keyInput.autocomplete = "off";
      const keyButtons = document.createElement("span");
      keyButtons.className = "input-group-btn";
      const reveal = document.createElement("button");
      reveal.type = "button";
      reveal.className = "btn btn-default btn-sm bt-ownerpsk-reveal";
      reveal.dataset.ownerpskIndex = String(index);
      reveal.textContent = "{{Afficher}}";
      keyButtons.appendChild(reveal);
      keyGroup.appendChild(keyInput);
      keyGroup.appendChild(keyButtons);
      keyCell.appendChild(keyGroup);
      row.appendChild(keyCell);

      const hostCell = document.createElement("td");
      const hostInput = document.createElement("input");
      hostInput.type = "text";
      hostInput.inputMode = "decimal";
      hostInput.className = "form-control input-sm ownerpsk-host";
      hostInput.placeholder = "192.168.1.20";
      hostInput.value = credential.equipment && credential.equipment.host
        ? String(credential.equipment.host)
        : "";
      hostCell.appendChild(hostInput);
      row.appendChild(hostCell);

      const statusCell = document.createElement("td");
      statusCell.dataset.ownerpskStatus = String(index);
      statusCell.textContent = credential.equipment ? "{{Équipement connu}}" : "{{Prêt à vérifier}}";
      row.appendChild(statusCell);
      body.appendChild(row);
    });
  }

  async function uploadOwnerPskDatabase() {
    if (certificateBusy || scanBusy || scanState.running) return;
    const input = document.getElementById("in_ownerPskDatabase");
    const summary = document.getElementById("localthings-ownerpsk-database-summary");
    const file = input.files && input.files[0];
    if (!file) {
      summary.textContent = "{{Sélectionnez le fichier oic_svr_db_client.dat}}";
      return;
    }
    if (file.size < 1 || file.size > 2097152) {
      summary.textContent = "{{Le fichier doit faire moins de 2 Mio}}";
      return;
    }
    const data = new FormData();
    data.append("action", "decodeOwnerPskDatabase");
    data.append("database", file, file.name);
    summary.textContent = "{{Analyse du fichier CBOR en cours…}}";
    certificateBusy = true;
    renderScan(scanState);
    try {
      const request = await fetch(endpoint, {
        method: "POST",
        body: data,
        credentials: "same-origin",
      });
      const response = await request.json();
      if (!request.ok || response.state !== "ok") {
        throw new Error(String(response.result || "{{Analyse impossible}}"));
      }
      renderOwnerPskCredentials(response.result);
    } catch (error) {
      ownerPskCredentials = [];
      document.getElementById("localthings-ownerpsk-credentials").style.display = "none";
      summary.textContent = new DOMParser().parseFromString(
        String(error && error.message ? error.message : "{{Analyse impossible}}"),
        "text/html"
      ).body.textContent;
    } finally {
      certificateBusy = false;
      renderScan(scanState);
    }
  }

  async function importSelectedOwnerPsk() {
    if (certificateBusy || scanBusy || scanState.running) return;
    const selected = Array.from(document.querySelectorAll(".ownerpsk-select:checked"));
    if (!selected.length) {
      jeedomUtils.showAlert({ message: "{{Sélectionnez au moins une clé OwnerPSK}}", level: "warning" });
      return;
    }
    if (selected.length > 20) {
      jeedomUtils.showAlert({
        message: "{{Sélectionnez au maximum 20 appareils par import}}",
        level: "warning",
      });
      return;
    }
    const successfulHosts = [];
    const successfulRows = [];
    certificateBusy = true;
    renderScan(scanState);
    try {
      for (const checkbox of selected) {
        const index = Number(checkbox.value);
        const credential = ownerPskCredentials[index];
        const row = checkbox.closest("tr");
        const host = row.querySelector(".ownerpsk-host").value.trim();
        if (!credential || !host) {
          ownerPskStatus(index, "{{Adresse IPv4 requise}}", "danger");
          continue;
        }
        ownerPskStatus(index, "{{Vérification en cours…}}", "info");
        try {
          await ajaxPromise("importOwnerPsk", {
            host: host,
            owner_uuid: credential.owner_uuid,
            device_uuid: credential.device_uuid,
            key: credential.key,
            port: credential.equipment && credential.equipment.port
              ? credential.equipment.port
              : "",
          });
          successfulHosts.push(host);
          successfulRows.push({
            index: index,
            credential: credential,
            checkbox: checkbox,
            row: row,
          });
          ownerPskStatus(index, "{{Clé vérifiée, ajout en attente…}}", "success");
        } catch (error) {
          ownerPskStatus(index, new DOMParser().parseFromString(
            String(error || "{{Échec de la vérification}}"), "text/html"
          ).body.textContent, "danger");
        }
      }
      if (successfulHosts.length) {
        const status = await ajaxPromise("probeOwnerPskHosts", {
          hosts: JSON.stringify(successfulHosts),
        });
        scanGeneration++;
        scanState = status || { running: true };
        successfulRows.forEach(function (entry) {
          entry.credential.key = "";
          entry.row.querySelector(".ownerpsk-key").value = "";
          entry.checkbox.checked = false;
          entry.checkbox.disabled = true;
          entry.row.querySelector(".ownerpsk-host").disabled = true;
          ownerPskStatus(entry.index, "{{Ajout en cours…}}", "success");
        });
        renderScan(scanState);
        scheduleScan(250);
        document.getElementById("localthings-ownerpsk-result").textContent =
          successfulHosts.length + " {{appareil(s) OwnerPSK ajouté(s) à la découverte}}";
      }
    } catch (error) {
      successfulRows.forEach(function (entry) {
        ownerPskStatus(entry.index, "{{Clé enregistrée, ajout à relancer}}", "warning");
      });
      const message = new DOMParser().parseFromString(
        String(error || "{{Impossible de démarrer l’ajout des appareils}}"), "text/html"
      ).body.textContent;
      jeedomUtils.showAlert({ message: message, level: "warning" });
    } finally {
      certificateBusy = false;
      renderScan(scanState);
    }
  }

  let scanState = { running: false };
  let scanTimer = null;
  let scanPolling = false;
  let scanBusy = false;
  let certificateBusy = false;
  let scanGeneration = 0;

  function ownerPskGuidance(errors) {
    const messages = Array.isArray(errors) ? errors.map(String) : [];
    const relevant = messages.some(function (message) {
      return /unknown_ca|unknown ca|timeout|timed out|délai dépassé/i.test(message);
    });
    if (!relevant) return false;

    const panel = document.getElementById("localthings-ownerpsk-panel");
    if (panel) {
      panel.style.display = "block";
      const details = panel.querySelector("details");
      if (details) details.open = true;
    }
    const output = document.getElementById("localthings-ownerpsk-result");
    if (output) {
      output.textContent = "{{Le handshake DTLS n’a pas abouti. Si cet appareil utilise OwnerPSK, vous pouvez importer ici une clé déjà provisionnée. Vérifiez aussi que Jeedom se trouve sur le même réseau local que l’appareil.}}";
    }
    const firstField = document.getElementById("in_ownerPskOwner");
    if (firstField) window.setTimeout(function () { firstField.focus(); }, 0);
    return true;
  }

  function renderScan(status) {
    scanState = status || { running: false };
    const scan = document.getElementById("bt_scanLocalthings");
    const stopping = scanState.running && scanState.stop_requested;
    const icon = scan?.querySelector("i");
    const label = scan?.querySelector("span");
    const activeIcon = scanState.running ? "fas fa-stop-circle" : "fas fa-satellite-dish";
    const activeLabel = scanState.running ? "{{Arrêter la découverte}}" : "{{Découvrir}}";
    if (icon) icon.className = stopping ? "fas fa-spinner fa-spin" : activeIcon;
    if (label) label.textContent = stopping ? "{{Arrêt en cours…}}" : activeLabel;
    scan?.classList.toggle("localthings-discovery-stop", Boolean(scanState.running));
    scan?.classList.toggle("logoPrimary", !scanState.running);
    scan?.setAttribute("aria-busy", String(Boolean(stopping)));
    scan?.setAttribute("aria-disabled", String(scanBusy || Boolean(stopping)));
    const probe = document.getElementById("bt_probeLocalthings");
    if (probe) probe.disabled = certificateBusy || scanBusy || Boolean(scanState.running);
    // Le diagnostic comparatif ouvre aussi une session DTLS : il ne doit pas
    // concurrencer une découverte sur le même appareil.
    [
      "bt_ownerPskDiagnose",
      "bt_ownerPskImport",
      "bt_ownerPskImportSelected",
      "bt_ownerPskDatabase",
      "in_ownerPskDatabase",
    ].forEach(function (id) {
      const button = document.getElementById(id);
      if (button) button.disabled = certificateBusy || scanBusy || Boolean(scanState.running);
    });
    const certificate = document.getElementById("bt_certificateLocalthings");
    if (certificate) certificate.disabled = certificateBusy || scanBusy || Boolean(scanState.running);
    const progress = document.getElementById("localthings-scan-progress");
    const bar = progress?.querySelector(".progress-bar");
    if (progress && bar) {
      progress.style.display = scanState.running ? "block" : "none";
      bar.style.width = Number(scanState.progress || 0) + "%";
      bar.textContent = scanState.running
        ? Number(scanState.tested || 0) + "/" + Number(scanState.candidates || 0)
          + (scanState.current_host ? " · " + scanState.current_host : "") : "";
    }
  }

  function scheduleScan(delay) {
    window.clearTimeout(scanTimer);
    if (root.isConnected) scanTimer = window.setTimeout(pollScan, delay);
  }

  function pollScan() {
    if (!root.isConnected || scanPolling) return;
    scanPolling = true;
    const generation = scanGeneration;
    ajax("scanStatus", {}, function (status) {
      scanPolling = false;
      if (!root.isConnected) return;
      if (generation !== scanGeneration) { scheduleScan(500); return; }
      const wasRunning = scanState.running;
      renderScan(status);
      if (wasRunning && !status.running) {
        if (status.cancelled) {
          jeedomUtils.showAlert({ message: "{{Découverte arrêtée}}", level: "info" });
        } else if (Array.isArray(status.errors) && status.errors.length) {
          ownerPskGuidance(status.errors);
          const message = document.createElement("div");
          message.textContent = status.errors.join(" · ");
          jeedomUtils.showAlert({ message: message.innerHTML, level: "warning" });
        } else {
          const found = Array.isArray(status.found) ? status.found.length : 0;
          jeedomUtils.showAlert({ message: found + " {{appareil(s) LocalThings découvert(s)}}", level: found ? "success" : "info" });
          if (found) window.setTimeout(function () { if (root.isConnected) window.location.reload(); }, 900);
        }
      }
      scheduleScan(status.running ? 1500 : 5000);
    }, function () { scanPolling = false; scheduleScan(5000); });
  }

  function renderCertificateResults(results) {
    const panel = document.getElementById("localthings-certificate-result");
    if (!panel) return;
    const rows = Array.isArray(results) ? results : [];
    panel.textContent = "";
    if (!rows.length) {
      panel.style.display = "none";
      return;
    }
    // Le tableau reprend exactement ce que le diagnostic a classé. Les valeurs
    // passent par textContent : ni certificat, ni clé, ni identifiant d'appareil
    // ne provient du contenu de l'appareil, et rien n'est interprété comme HTML.
    const table = document.createElement("table");
    table.className = "table table-condensed table-bordered";
    table.style.marginBottom = "0";
    const head = table.createTHead().insertRow();
    ["{{Profil}}", "{{Suite}}", "{{Handshake}}", "{{Lecture protégée}}"].forEach(function (title) {
      const cell = document.createElement("th");
      cell.textContent = title;
      head.appendChild(cell);
    });
    const body = table.createTBody();
    rows.forEach(function (row) {
      const line = body.insertRow();
      const profile = line.insertCell();
      profile.textContent = row.label || row.profile || "";
      const cipher = line.insertCell();
      const code = document.createElement("code");
      code.textContent = row.cipher || "-";
      cipher.appendChild(code);
      const handshake = line.insertCell();
      const badge = document.createElement("span");
      if (row.authorized) {
        badge.className = "label label-success";
        badge.textContent = "{{autorisé}}";
      } else if (row.handshake === "accepted") {
        badge.className = "label label-warning";
        badge.textContent = "{{handshake accepté}}";
      } else {
        badge.className = "label label-danger";
        badge.textContent = row.handshake === "refused (unknown_ca)" ? "{{certificat refusé}}" : "{{connexion non établie}}";
      }
      handshake.appendChild(badge);
      line.insertCell().textContent = row.read || "-";
    });
    panel.appendChild(table);
    const note = document.createElement("p");
    note.className = "text-muted";
    note.style.margin = "6px 0 0";
    note.textContent = "{{Essai en lecture seule : aucun reset, aucun changement de propriétaire, "
      + "aucune écriture OCF. Les détails figurent dans le journal localthings.}}";
    panel.appendChild(note);
    panel.style.display = "block";
  }

  function compareCertificates(button, host) {
    if (certificateBusy) return;
    certificateBusy = true;
    renderScan(scanState);
    const icon = button.querySelector("i");
    if (icon) icon.className = "fas fa-spinner fa-spin";
    button.disabled = true;
    const restore = function () {
      if (icon) icon.className = "fas fa-certificate";
      certificateBusy = false;
      renderScan(scanState);
    };
    ajax("certificateDiagnostic", { host: host }, function (results) {
      restore();
      renderCertificateResults(results);
    }, function (error) {
      restore();
      const panel = document.getElementById("localthings-certificate-result");
      if (panel) {
        const alert = document.createElement("div");
        alert.className = "alert alert-warning";
        alert.style.margin = "0";
        const parsed = new DOMParser().parseFromString(String(error || "{{Comparaison impossible}}"), "text/html");
        alert.textContent = parsed.body.textContent;
        panel.textContent = "";
        panel.appendChild(alert);
        panel.style.display = "block";
      }
    });
  }

  function scanAction(action, data) {
    scanBusy = true;
    scanGeneration++;
    renderScan(scanState);
    ajax(action, data, function (status) {
      scanBusy = false;
      renderScan(status);
      scheduleScan(250);
    }, function () {
      scanBusy = false;
      renderScan(scanState);
      scheduleScan(250);
    });
  }

  // Reprend le suivi après rechargement, et détecte les lancements dans un autre onglet.
  pollScan();

  root.addEventListener("click", function (event) {
    if (event.target.closest("#bt_ownerPskDatabase")) {
      uploadOwnerPskDatabase();
      return;
    }
    if (event.target.closest("#bt_ownerPskImportSelected")) {
      importSelectedOwnerPsk();
      return;
    }
    const revealOwnerPsk = event.target.closest(".bt-ownerpsk-reveal");
    if (revealOwnerPsk) {
      const row = revealOwnerPsk.closest("tr");
      const input = row.querySelector(".ownerpsk-key");
      const reveal = input.type === "password";
      input.type = reveal ? "text" : "password";
      revealOwnerPsk.textContent = reveal ? "{{Masquer}}" : "{{Afficher}}";
      return;
    }
    if (event.target.closest("#cb_ownerPskSelectAll")) {
      const checked = event.target.checked === true;
      document.querySelectorAll(".ownerpsk-select:not(:disabled)").forEach(function (checkbox) {
        checkbox.checked = checked;
      });
      return;
    }

    const scan = event.target.closest("#bt_scanLocalthings");
    if (scan) {
      if (scanBusy || scanState.stop_requested) return;
      if (scanState.running) {
        scanAction("stopScan", { job_id: scanState.job_id });
        return;
      }
      scanBusy = true;
      scanGeneration++;
      renderScan(scanState);
      ajax("scanStatus", {}, function (status) {
        scanBusy = false;
        renderScan(status);
        if (status.running) {
          // Un autre onglet a démarré la découverte : afficher d'abord l'action
          // rouge. Seul un clic explicite sur celle-ci doit demander l'arrêt.
          scheduleScan(1500);
        } else {
          scanAction("scan", {});
        }
      }, function () { scanBusy = false; renderScan(scanState); scheduleScan(1500); });
      return;
    }

    const probe = event.target.closest("#bt_probeLocalthings");
    if (probe) {
      if (scanBusy || scanState.running) return;
      const host = document.getElementById("in_localthings_host")?.value.trim() || "";
      if (!host) {
        jeedomUtils.showAlert({ message: "{{Saisissez une adresse IPv4}}", level: "warning" });
        return;
      }
      scanAction("probe", { host: host });
      return;
    }

    if (event.target.closest("#bt_ownerPskLocalthings")) {
      const panel = document.getElementById("localthings-ownerpsk-panel");
      panel.style.display = panel.style.display === "none" ? "block" : "none";
      return;
    }
    const ownerImport = event.target.closest("#bt_ownerPskImport");
    if (ownerImport || event.target.closest("#bt_ownerPskDiagnose")) {
      if (certificateBusy || scanBusy || scanState.running) return;
      const host = document.getElementById("in_localthings_host").value.trim();
      const output = document.getElementById("localthings-ownerpsk-result");
      if (!host) { output.textContent = "{{Saisissez l’adresse IPv4 de l’appareil}}"; return; }
      const data = { host: host };
      if (ownerImport) {
        data.owner_uuid = document.getElementById("in_ownerPskOwner").value.trim();
        data.device_uuid = document.getElementById("in_ownerPskDevice").value.trim();
        data.key = document.getElementById("in_ownerPskKey").value.trim();
        document.getElementById("in_ownerPskKey").value = "";
      }
      certificateBusy = true;
      renderScan(scanState);
      output.textContent = "{{Vérification en cours pour}} " + host + "…";
      const finish = function () { certificateBusy = false; renderScan(scanState); };
      ajax(ownerImport ? "importOwnerPsk" : "ownerPskDiagnostic", data, function (result) {
        finish();
        const state = function (value) { return value === true ? "{{oui}}" : value === false ? "{{non}}" : "{{inconnu}}"; };
        output.textContent = ownerImport ? result.message : [
          "{{Adresse}} : " + host + ":" + result.port,
          "{{Déjà associé}} : " + state(result.owned),
          "{{Opérationnel}} : " + state(result.operational),
          "{{Méthode OTM sélectionnée}} : " + (result.oxm == null ? "{{inconnue}}" : result.oxm),
          "{{Sonde PSK (premier échange uniquement)}} : " + result.psk_probe,
          "{{Clé locale enregistrée}} : " + state(result.credential_installed),
          result.message
        ].join("\n");
      }, function (error) {
        finish();
        output.textContent = new DOMParser().parseFromString(String(error || "{{Diagnostic impossible}}"), "text/html").body.textContent;
      });
      return;
    }

    const certificateButton = event.target.closest("#bt_certificateLocalthings");
    if (certificateButton) {
      if (scanBusy || scanState.running) return;
      const host = document.getElementById("in_localthings_host")?.value.trim() || "";
      if (!host) {
        jeedomUtils.showAlert({ message: "{{Saisissez une adresse IPv4}}", level: "warning" });
        return;
      }
      compareCertificates(certificateButton, host);
      return;
    }

    if (event.target.closest("#bt_healthLocalthings")) {
      jeeDialog.dialog({
        id: "md_localthings_health",
        title: "{{Santé LocalThings}}",
        contentUrl: "index.php?v=d&plugin=localthings&modal=health",
      });
      return;
    }

    if (event.target.closest("#bt_resetSearch")) {
      const input = document.getElementById("in_searchEqlogic");
      if (input) {
        input.value = "";
        input.dispatchEvent(new Event("keyup"));
      }
      return;
    }

    if (event.target.closest("#bt_refreshLocalthings")) {
      const id = document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue();
      if (!id) return;
      ajax("refresh", { id: id }, function () {
        jeedomUtils.showAlert({ message: "{{État de l’appareil actualisé}}", level: "success" });
      });
      return;
    }

    const communicationTest = event.target.closest("#bt_testCommunicationLocalthings");
    if (communicationTest) {
      const id = document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue();
      if (!id) return;
      communicationTest.disabled = true;
      const icon = communicationTest.querySelector("i");
      icon?.classList.add("fa-spin");
      const resetCommunicationTest = function () {
        communicationTest.disabled = false;
        icon?.classList.remove("fa-spin");
      };
      ajax("testCommunication", { id: id }, function (result) {
        resetCommunicationTest();
        const lastCommunication = root.querySelector(
          '.eqLogicAttr[data-l1key="configuration"][data-l2key="last_communication"]'
        );
        const lastError = root.querySelector(
          '.eqLogicAttr[data-l1key="configuration"][data-l2key="last_error"]'
        );
        if (lastCommunication) lastCommunication.textContent = result.last_communication || "";
        if (lastError) lastError.textContent = result.last_error || "";
        jeedomUtils.showAlert({ message: result.message, level: "success" });
      }, resetCommunicationTest);
      window.setTimeout(function () {
        resetCommunicationTest();
      }, 20000);
    }
  });

  document.getElementById("in_searchEqlogic")?.addEventListener("keyup", function () {
    const search = this.value.toLowerCase().trim();
    root.querySelectorAll(".eqLogicDisplayCard").forEach(function (card) {
      card.style.display = !search || card.textContent.toLowerCase().includes(search) ? "" : "none";
    });
  });
})();

function initCommandOrdering(tbody) {
  if (!tbody || tbody.dataset.localthingsSortable === "1" || typeof Sortable === "undefined") return;
  tbody.dataset.localthingsSortable = "1";
  new Sortable(tbody, {
    animation: 150,
    draggable: ".cmd",
    handle: ".localthings-cmd-order-handle",
    onEnd: function (event) {
      event.item.querySelector(".cmdAttr")?.dispatchEvent(new Event("change", { bubbles: true }));
    },
  });
}

function renderSelectValueOrder(row) {
  const input = row.querySelector('.cmdAttr[data-l1key="configuration"][data-l2key="listValue"]');
  const list = row.querySelector(".localthings-select-values");
  if (!input || !list) return;

  list.textContent = "";
  input.value.split(";").filter(Boolean).forEach(function (entry) {
    const separator = entry.indexOf("|");
    const value = separator === -1 ? entry : entry.slice(0, separator);
    const label = separator === -1 ? entry : entry.slice(separator + 1);
    const item = document.createElement("li");
    item.className = "localthings-select-value";
    item.dataset.entry = entry;

    const handle = document.createElement("i");
    handle.className = "fas fa-grip-vertical localthings-select-value-handle";
    handle.setAttribute("aria-hidden", "true");
    item.appendChild(handle);

    const text = document.createElement("span");
    text.textContent = label === value ? label : label + " (" + value + ")";
    item.appendChild(text);
    list.appendChild(item);
  });

  if (list.dataset.localthingsSortable !== "1" && typeof Sortable !== "undefined") {
    list.dataset.localthingsSortable = "1";
    new Sortable(list, {
      animation: 150,
      draggable: ".localthings-select-value",
      handle: ".localthings-select-value-handle",
      onEnd: function () {
        input.value = Array.from(list.children).map(function (item) {
          return item.dataset.entry;
        }).join(";");
        input.dispatchEvent(new Event("input", { bubbles: true }));
      },
    });
  }
  if (input.dataset.localthingsOrderListener !== "1") {
    input.dataset.localthingsOrderListener = "1";
    input.addEventListener("change", function () {
      renderSelectValueOrder(row);
    });
  }
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) _cmd = { configuration: {} };
  if (!isset(_cmd.configuration)) _cmd.configuration = {};

  let html = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  html += '<td class="hidden-xs localthings-order-cell"><i class="fas fa-grip-vertical localthings-cmd-order-handle" data-title="{{Déplacer la commande}}"></i><span class="cmdAttr" data-l1key="id"></span></td>';
  html += '<td><div class="input-group">';
  html += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name">';
  html += '<span class="input-group-btn"><a class="btn btn-default btn-sm cmdAction roundedRight" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
  html += '</div><span class="cmdAttr" data-l1key="display" data-l2key="icon"></span>';
  html += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none"><option value="">{{Aucune}}</option></select></td>';
  html += '<td><span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span><span class="subType" subType="' + init(_cmd.subType) + '"></span></td>';
  html += '<td>';
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible">{{Afficher}}</label> ';
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>';
  if (init(_cmd.subType) === "numeric" || init(_cmd.subType) === "slider") {
    html += '<div class="input-group">';
    html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}">';
    html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}">';
    html += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}">';
    html += '</div>';
  }
  if (init(_cmd.subType) === "select") {
    html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="listValue" placeholder="{{Valeur|Libellé;...}}">';
    html += '<ol class="localthings-select-values" data-title="{{Glissez les valeurs pour modifier leur ordre d’affichage}}"></ol>';
  }
  html += '</td>';
  html += '<td>' + (init(_cmd.type) === "info" ? '<span class="cmdAttr" data-l1key="htmlstate"></span>' : "") + '</td>';
  html += '<td><div class="input-group" style="display:inline-flex">';
  if (is_numeric(_cmd.id) && _cmd.id !== "") {
    html += '<a class="btn btn-default btn-xs cmdAction roundedLeft" data-action="configure" title="{{Configuration avancée}}"><i class="fas fa-cogs"></i></a>';
    html += '<a class="btn btn-success btn-xs cmdAction" data-action="test" title="{{Tester}}"><i class="fas fa-rss"></i></a>';
  }
  html += '<a class="btn btn-danger btn-xs cmdAction roundedRight" data-action="remove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a>';
  html += '</div></td></tr>';

  const holder = document.createElement("tbody");
  holder.innerHTML = html;
  const row = holder.firstElementChild;
  const tbody = document.querySelector("#table_cmd tbody");
  tbody.appendChild(row);
  initCommandOrdering(tbody);
  jeedom.eqLogic.buildSelectCmd({
    id: document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue(),
    filter: { type: "info" },
    error: function (error) {
      jeedomUtils.showAlert({ message: error.message, level: "danger" });
    },
    success: function (result) {
      row.querySelector('.cmdAttr[data-l1key="value"]')?.insertAdjacentHTML("beforeend", result);
      row.setJeeValues(_cmd, ".cmdAttr");
      jeedom.cmd.changeType(row, init(_cmd.subType));
      renderSelectValueOrder(row);
    },
  });
}
