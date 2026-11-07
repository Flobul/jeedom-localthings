# Réseau et connectivité LocalThings

## Contrainte générale

LocalThings communique directement avec les appareils Samsung via CoAP/DTLS sur le réseau local. Jeedom doit donc pouvoir échanger des paquets UDP avec l’appareil sur son port DTLS dynamique.

## Jeedom sur machine physique ou VM avec accès direct au réseau

Le mode recommandé. Jeedom partage le même sous-réseau que les appareils et aucune translation de port n’est requise.

## Jeedom dans Docker

Un conteneur Jeedom derrière un bridge Docker par défaut peut ne pas recevoir les réponses UDP/DTLS, même si la découverte TCP fonctionne. Symptôme typique : la découverte trouve l’appareil, mais le handshake DTLS reste silencieux ou expire.

### Recommandation

Utiliser un réseau `macvlan` (ou `ipvlan`) attaché au même sous-réseau que les appareils, afin que Jeedom possède une adresse IP réelle sur ce sous-réseau :

```bash
docker network create -d macvlan \
  --subnet=192.168.23.0/24 \
  --gateway=192.168.23.1 \
  -o parent=eth0 \
  localthings-lan
```

Puis lancer le conteneur Jeedom avec `--network localthings-lan`.

### Diagnostic

- Vérifier que Jeedom peut joindre l’adresse IP de l’appareil en UDP sur le port annoncé par `/oic/res`.
- Si seul le TCP fonctionne et l’UDP est silencieux, le problème vient de la topologie réseau, pas du plugin.
- Les deux sous-réseaux attendus sont `192.168.23.x` et `192.168.30.x`; le conteneur doit être présent sur celui qui contient l’appareil.

## Ports DTLS

Les ports sécurisés sont attribués dynamiquement par IoTivity. La plage historique `49152-49160` et le port `5684` ne sont pas exhaustifs. Le plugin lit le port annoncé par `/oic/res` et redécouvre automatiquement le port après un échec de reconnexion.
