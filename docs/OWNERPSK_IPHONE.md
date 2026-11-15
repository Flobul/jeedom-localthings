# Importer une OwnerPSK depuis une sauvegarde iPhone

LocalThings peut lire le magasin de sécurité SmartThings iOS
`Library/Security/oic_svr_db_client.dat`, lister les clés OwnerPSK qu’il
contient et vérifier les appareils sélectionnés avant de les ajouter.

Cette fonction **ne réalise aucun transfert de propriété** et n’écrit aucune
ressource `/oic/sec/*`. Elle importe uniquement une clé déjà provisionnée par
SmartThings, conformément au modèle d’import décrit dans l’issue LocalThings
amont n°435.

## Obtenir le fichier

Le fichier se trouve dans le domaine d’application iOS :

```text
AppDomain-com.samsung.oneconnect4ios
└── Library/Security/oic_svr_db_client.dat
```

Il peut être exporté avec un lecteur de sauvegarde iPhone. Si la sauvegarde
Finder ou Apple Devices est chiffrée, le fichier doit d’abord être extrait et
déchiffré avec le mot de passe de cette sauvegarde. LocalThings accepte le
fichier `.dat` final ; il ne demande jamais le mot de passe de la sauvegarde.

## Analyser et importer

1. Ouvrir la page du plugin LocalThings.
2. Cliquer sur **OwnerPSK**.
3. Ouvrir **Importer depuis une sauvegarde iPhone**.
4. Sélectionner `oic_svr_db_client.dat`, puis cliquer sur **Analyser**.
5. Pour chaque clé souhaitée, renseigner l’adresse IPv4 actuelle de l’appareil.
6. Cocher les appareils à importer et cliquer sur
   **Vérifier et ajouter la sélection**.

Le fichier de sécurité ne contient généralement pas le nom commercial des
appareils. LocalThings affiche donc leur UUID OCF. Si un équipement correspondant
existe déjà dans Jeedom, son nom et son adresse sont affichés automatiquement.

Pour chaque sélection, le plugin :

- ouvre une nouvelle session DTLS avec la clé extraite ;
- vérifie l’UUID OCF de l’appareil et l’UUID propriétaire ;
- confirme que l’appareil est opérationnel ;
- exige au moins une lecture métier valide ;
- enregistre la clé seulement après ces vérifications ;
- lance ensuite une découverte ciblée pour créer ou actualiser l’équipement.

Une clé qui ne correspond pas à l’adresse saisie n’est pas enregistrée.

## Données extraites

Le magasin est un document CBOR imbriqué. LocalThings lit la ressource `cred`,
puis retient uniquement les enregistrements :

```text
credtype = 1
```

Pour chaque enregistrement utilisable :

- `cred.rowneruuid` devient l’identité OwnerPSK ;
- `subjectuuid` devient l’UUID OCF de l’appareil ;
- `privatedata.data` fournit la clé de 16 ou 32 octets.

Les enregistrements `credtype = 8` sont des certificats ou ancres de confiance :
ils ne sont pas proposés comme OwnerPSK.

## Sécurité et limites

- L’analyse est réservée aux administrateurs Jeedom.
- Le fichier est limité à 2 Mio et n’est pas conservé par le plugin après la
  requête d’analyse.
- Son contenu et les clés ne sont jamais écrits dans les logs.
- Les clés sont masquées par défaut dans l’interface.
- Après une vérification réussie, la clé disparaît du tableau du navigateur et
  est stockée dans le magasin privé LocalThings avec des permissions restreintes.
- Les sauvegardes Jeedom du répertoire `data` contiennent les clés enregistrées.

Un fichier peut ne contenir aucune OwnerPSK. Cela signifie notamment que ses
entrées sont uniquement de type certificat ; ce n’est pas nécessairement une
erreur de décodage. Sur certains appareils, une suppression puis une nouvelle
association dans SmartThings a créé une clé, mais cette opération peut modifier
la propriété OCF, les routines ou les automatisations. Elle n’est donc ni
exécutée ni recommandée automatiquement par le plugin.
