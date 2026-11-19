# Acquérir une clé locale par classe d'appareil

LocalThings **importe une clé déjà provisionnée** ; il ne réalise aucun transfert
de propriété et n'écrit aucune ressource `/oic/sec/*` (décision 1 de l'issue amont
[#435]). Ce document décrit, par famille d'appareil, comment obtenir le matériel
d'authentification que le plugin sait importer. Aucune clé, UUID ou identifiant
réel ne doit être partagé dans une issue ou un journal.

La procédure détaillée d'import depuis une sauvegarde iPhone/iPad est dans
[`OWNERPSK_IPHONE.md`](OWNERPSK_IPHONE.md). Elle est **générique** : le fichier
`oic_svr_db_client.dat` contient les clés de tous les appareils appairés au même
compte SmartThings, pas seulement les lave-linge (confirmé pour les moniteurs,
amont #891).

## Ce que le plugin accepte

| Carrier | Rôle | Import | Identité |
|---|---|---|---|
| Certificat (AC14K_M / autosigné) | — | automatique, sans clé | essai cert puis repli lecture seule |
| PSK | OwnerPSK | clé + UUID propriétaire depuis la sauvegarde | `di` authentifié + preuve de clé |
| PSK | pairwise peer | clé + UUID peer fournis ensemble | `di` authentifié + preuve de clé |

Le carrier n'est pas déduit de `doxm` : le plugin essaie le certificat, et propose
l'import PSK quand l'appareil répond `unknown_ca`. `sct` et `oxms` décrivent
l'acquisition possible, pas la clé runtime à utiliser (amont #435).

## Lave-linge / sèche-linge (WD80, WD86, WD53, TP6X…)

- La plupart portent un certificat fabricant : le chemin certificat automatique
  fonctionne, ou l'import OwnerPSK depuis la sauvegarde iPhone du téléphone qui a
  appairé l'appareil.
- Une clé OwnerPSK s'obtient dans `oic_svr_db_client.dat` (sauvegarde **chiffrée**
  du téléphone d'origine). Voir `OWNERPSK_IPHONE.md`.
- Panneau endormi : l'appareil conserve son dernier état et se réveille à son
  rythme ; le plugin ne force pas de réauthentification agressive (décision 5).

## Réfrigérateur RF23 / TP1X_REF_21K (peer pairwise)

- Le contrôle utilise un **peer least-privilege** ; Samsung reste l'owner OCF.
  L'UUID peer et la clé 16 octets ne se déduisent pas de `devowneruuid` : ils
  doivent être fournis **ensemble** à l'import (décision 3/4).
- L'acquisition de ce peer (bootstrap spécifique au modèle, préservant l'owner)
  est hors du plugin ; un outil séparé produit la clé, le plugin l'importe.

## TV / The Frame / soundbar / moniteur (média, `sct: 1`)

- Ces appareils refusent souvent le certificat automatique et acceptent une clé
  OwnerPSK ou peer. La procédure iPhone s'applique (amont #868 Frame, #891
  ViewFinity) : sauvegarde chiffrée du téléphone d'origine, extraire
  `oic_svr_db_client.dat`, importer la clé de l'appareil.
- Si aucune clé utilisable n'est présente (entrées `credtype 8` = `trust_ca`),
  ré-appairer l'appareil via Easy Connect / code PIN (`oxm 1`) dans SmartThings
  puis refaire une sauvegarde chiffrée.
- Le profil peer est accepté à l'import même si l'owner déclaré reste Samsung
  (dès lors que `di`, état opérationnel et lectures métier sont vérifiés).

## Famille legacy pré-DTLS (pont 8888 : FlexDry, certains TP6X, dongles)

- Ces appareils n'exposent **pas** de DTLS/OCF : ils servent un pont HTTP/TLS 1.0
  sur le port **8888** et s'autorisent par **jeton d'appareil** (pas par OwnerPSK).
- Le plugin détecte « pas de DTLS mais 8888 ouvert » et bascule sur le transport
  legacy dédié. Voir `NETWORK.md` pour le port et le jeton.

## Appareils sans interface locale (cloud-only)

- Certains modèles récents (Family Hub RF28N9780SR, Jet Bot, QLED sans OCF)
  n'exposent ni DTLS, ni 8888, ni CoAP public : ils sont pilotables uniquement via
  le cloud SmartThings. Le plugin le signale clairement (« aucune interface
  locale détectée ») au lieu d'échouer silencieusement (amont #887/#769/#883).
- Les TV sans OCF mais avec l'API Samsung TV (TCP 8001/8002) relèvent d'un autre
  protocole, hors du cœur OCF/legacy de ce plugin.

## Limites et prudence

- La procédure iPhone suppose une sauvegarde **chiffrée** du téléphone **qui a
  appairé** l'appareil ; une sauvegarde non chiffrée ne contient pas le dossier
  Security. Le plugin ne demande jamais le mot de passe de la sauvegarde.
- Les routes d'acquisition non reset / bootstrap peer décrites dans #435 ne sont
  pas des recettes publiques garanties : elles dépendent du firmware et d'entrées
  privées one-time. Toute route non testée doit être notée comme telle.