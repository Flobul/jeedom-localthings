# Processus de Release stable

Ce dépôt contient un POC de création automatique des Releases GitHub. Il ne
publie rien tant que la variable GitHub `RELEASE_AUTOMATION_ENABLED` n’est pas
explicitement définie à `true`.

## Source de vérité

Une version stable doit être identique dans les trois surfaces suivantes :

1. `public static $_pluginVersion` dans `core/class/localthings.class.php` ;
2. `pluginVersion` dans `plugin_info/info.json` ;
3. une section datée et non vide `## Version X (JJ/MM/AAAA)` dans
   `docs/CHANGELOG.md`.

Le corps exact de cette section devient la note de Release.

## Référence initiale

`.github/release-baseline.json` fixe `0.5.0` comme version présente lors de
l’installation du workflow. Elle empêche toute Release rétroactive et traite les
notations `0.5.00` et `0.5.0` comme la même version pour la décision de créer
une Release.

Cette référence n’est pas déplacée à chaque Release. Les Releases et tags
stables existants déterminent ensuite la progression.

## Simulation

```bash
gh workflow run release.yml \
  --repo Flobul/jeedom-localthings \
  -f mode=simulate \
  -f ref=main
```

Le workflow exige le commit courant de la branche principale et une CI réussie
au même commit. Le plan et les notes exactes sont conservés en artefact.

## Publication

Une publication n’est autorisée que si `RELEASE_AUTOMATION_ENABLED=true`. Le
workflow :

1. rejoue les tests du garde de Release ;
2. vérifie la CI, les versions et le changelog ;
3. crée le tag s’il est absent ;
4. refuse de déplacer un tag existant ;
5. crée la Release ou reprend après un échec survenu après la création du tag ;
6. peut notifier `Flobul/.jeedom`, le polling central restant le rattrapage.

La notification est désactivée tant que `CENTRAL_DISPATCH_ENABLED` n’est pas
`true`. Aucun secret WordPress n’est présent dans ce dépôt.

## Nouvelle version

Mettre à jour dans le même commit :

- la version PHP ;
- `pluginVersion` ;
- la nouvelle section datée de `docs/CHANGELOG.md`.

Une correction de notation historique ne constitue pas une nouvelle version et
ne doit pas produire de Release.
