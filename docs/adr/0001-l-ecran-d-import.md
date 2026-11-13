# ADR-0001 — L'écran d'import, et l'import de comptes

- **Statut** : Proposé — 2026-11-13, à valider par jul6art avant d'écrire du code
- **Décideurs** : jul6art
- **Origine** : audit Fable 5.2, T20, lot 6 (le dernier lot du chantier de remontée)
- **Bundles** : dataflow-bundle (l'écran), admin-bundle (l'import de comptes, `admin.user_import`)

## Contexte

dataflow-bundle porte le moteur d'import : `ImportRunner`, `HeaderInspector`, les lecteurs, `ImportSpec` et
`DuplicatePolicy`, la voie asynchrone, et les deux partiels `@Dataflow/import/_mapper` et `_report`. Il ne porte
**aucun écran**. Chaque application recopie donc le même parcours en trois temps :

1. téléverser ;
2. associer chaque colonne à un champ ;
3. lancer, puis lire le rapport.

Les mêmes gardes se répètent dans chaque copie :

- la taille ;
- le type MIME ;
- la signature d'un classeur binaire (`SpreadsheetSignature`) ;
- le fichier rangé sous un jeton de session, jamais son chemin dans le navigateur ;
- la correspondance indexée par position de colonne ;
- deux colonnes vers un même champ refusées ;
- le rapport d'un lot en échec, annulé (`ImportFailedException`).

Mesure du 2026-11-13 (`superp/docs/analyse/audit_fable_5_2_t20_copies.md`, même méthode) :

| Contrôleur | Copies | Lignes | Particularité |
|---|---|---|---|
| `UserImportController` | devingame, devinthehood, tradebot, wtg, squelette : **identiques** | 244 | un seul import, les comptes ; deux écrans (le téléversement rend directement la correspondance) |
| `ImportController` (cereezer) | 1 | 309 | **un registre de six imports** (`customer`, `site`, `asset`, `service-contract`, `user`, `work-order`), trois écrans (téléversement → correspondance en `GET` → lancement), un modèle à télécharger, un **aperçu**, `UploadedImportStore` |
| `ImportController` (superp) | 1 | 318 | `user` et `product`, un modèle à télécharger ; le droit dépend de l'entité |
| `CrmImportController` (superp) | 1 | 284 | les contacts du CRM ; même patron que le précédent |
| `IndexImportController`, `CalendarImportController` (tradebot) | 2 | 166 | l'import vise **un objet de la route** (un indice `{id}`) |
| `MemberImportController` (cegeta) | 1 | 228 | un **quota** d'offre : le refus redirige vers la page de montée en gamme ; les membres sont invités |

Et derrière l'import de comptes, trois classes :

| Classe | Copies | Variantes |
|---|---|---|
| `UserImportService` | 7 | 4 : la famille devingame, devinthehood, tradebot, wtg ; le squelette (un commentaire) ; cereezer (118 lignes d'écart) ; superp (156) |
| `UserRowMapper` | 7 | 5 : devingame, devinthehood, wtg ; tradebot (+ la garde du super-administrateur) ; le squelette (le type de retour) ; cereezer (180) ; superp (179) |
| `UserDuplicateResolver` | 7 | 3 : la famille et le squelette ; cereezer (80) ; superp (47) |

⚠️ **Constat de sécurité, corrigé avant le lot (2026-11-13).** Le 2026-10-17, tradebot avait refermé une
élévation de privilège : un administrateur, qui détient `user:create` par défaut, importait un compte
`ROLE_SUPER_ADMIN` à une adresse qu'il contrôle, puis l'activait par « mot de passe oublié ».

- Les quatre autres copies l'avaient gardée : devingame, devinthehood, wtg et le squelette.
- Le formulaire de compte de devingame et de devinthehood était déjà fermé le 2026-10-25.
- Elles sont corrigées : seul un super-administrateur importe un super-administrateur.
- Le formulaire de compte de wtg l'est aussi : il proposait encore `ROLE_SUPER_ADMIN` à tout détenteur de
  `user:update`.

La version du bundle naît avec cette garde.

### Les écarts, un par un

| Où | Écart | Classement |
|---|---|---|
| la famille (4 + squelette) | deux écrans ; le **chemin** du fichier en session sous un jeton, revérifié sous `sys_get_temp_dir()` | **référence** pour le parcours |
| cereezer | trois écrans (la correspondance est une page qu'on recharge) ; **seul un nom** voyage en session, le fichier vit sous `var/import` (`UploadedImportStore`) | **meilleur, adopté** : un nom de 32 caractères hexadécimaux ne sort pas du répertoire, un chemin forgé si |
| cereezer | un registre : une route `/{entity}`, un service par import (`ImportServiceInterface` : champs, droit, domaine de traduction, route de retour) | **adopté** comme contrat du bundle |
| cereezer | un aperçu avant de lancer (`ImportPreviewBuilder` : lignes neuves, doublons, refus) | **besoin du produit**, puis **option** : `preview: true` par import |
| cereezer, superp, cegeta | un modèle CSV à télécharger | **adopté** (`TemplatableRowMapperInterface` existe déjà) |
| superp | le droit exigé dépend de l'entité importée | couvert par le contrat : chaque import dit son droit |
| tradebot | l'import vise un objet de la route (`/admin/indices/{id}/import`) | **point d'extension** : les attributs de la route sont passés à l'import |
| cegeta | un refus de quota redirige vers la montée en gamme | **point d'extension** : un événement avant le lancement, auquel l'application peut donner une réponse |
| tradebot, cegeta | 1 Mio au lieu de 5 | **configuration** : `max_bytes` par import |
| la famille | `ImportFailedException` rend le rapport, la ligne et l'annulation | **référence** |

## Décision

### 1. dataflow-bundle livre l'écran d'import (1.16.0)

Sous `Import/Web/` :

- **`ImportDefinitionInterface`** : le contrat d'un import, repris de cereezer. Il dit :
  - ses champs, et comment lire les en-têtes ;
  - comment lancer l'import (`import(path, mapping, ImportContext)`) ;
  - le droit exigé ;
  - son domaine de traduction ;
  - sa route de retour ;
  - ses gabarits, s'il en veut d'autres ;
  - s'il offre un modèle à télécharger.

  `ImportContext` porte le compte et les attributs de la route (l'indice de tradebot). Chaque import est un
  service étiqueté `dataflow.import`, sous une clé (`user`, `customer`…).
- **`UploadedImportStore`** : celui de cereezer.
  - Le fichier est déplacé sous `dataflow.import.directory`, par défaut `%kernel.project_dir%/var/import`.
  - Son nom est aléatoire.
  - Seul ce nom voyage en session, et il est revérifié avant usage.
  - Le fichier est consommé une seule fois.
- **`UploadGuard`** : la taille, `max_bytes` (5 Mio par défaut, surchargeable par import) ; la liste MIME ; le
  refus d'un classeur binaire, avec son propre message.
- **`ImportController`**, sans aucune route déclarée : l'application les nomme, comme pour la grille des
  permissions (lot 2) et la langue (lot 4). Ses actions :
  - `upload` (GET, POST) : le dépôt, puis une redirection vers la correspondance ;
  - `mapping` (GET) ;
  - `run` (POST) : le lancement, puis le rapport ;
  - `template` (GET) : le modèle.

  L'import visé vient de l'attribut de route `_import`, ou du paramètre `{entity}` d'une route unique à
  plusieurs imports.
- **L'événement `ImportStartingEvent`**, émis avant le lancement : une application peut y poser une réponse,
  par exemple la redirection de quota de cegeta. Le précédent est `FeatureAccessDeniedEvent` d'acl-bundle.
- **Les gabarits** `@Dataflow/import/upload.html.twig`, `mapping.html.twig` et `report.html.twig`. Ils
  s'appuient sur les deux partiels existants et étendent `dataflow.import.layout`. Chaque import peut les
  remplacer.

Jetons CSRF : `dataflow_import_upload` et `dataflow_import_run`. Le droit de l'import se vérifie à chaque action.
Un refus de navigation reste l'affaire du pare-feu de l'application : cereezer redirige vers son tableau de bord.

```yaml
dataflow:
    import:
        enabled: true
        directory: '%kernel.project_dir%/var/import'
        layout: 'admin/layout.html.twig'
        max_bytes: 5242880
```

Désactivé par défaut : une application garde ses contrôleurs tant qu'elle n'active pas la clé.

### 2. admin-bundle livre l'import de comptes (`admin.user_import`, 1.44.0)

Pour la famille mono-locataire : devingame, devinthehood, tradebot, wtg et le squelette. Il comprend une définition
`user`, un mappeur et un résolveur de doublons :

- **les champs** `firstName`, `lastName`, `email`, `role`, écrits par PropertyAccess dans `user_class` ;
- **l'adresse normalisée** comme l'entité la normalise (`Strings::lowerEmail` de core-bundle) ;
- **le doublon**, cherché par adresse, en une requête par lot ;
- **le mot de passe aléatoire** : le compte s'active par « mot de passe oublié » ;
- **les rôles** pris dans `roles`, une liste de la configuration ; un rôle hors liste est refusé ;
- ⚠️ **`ROLE_SUPER_ADMIN` seulement par un super-administrateur** ;
- **le droit** : `user:create`, configurable.

```yaml
admin:
    user_import:
        enabled: true
        user_class: App\Entity\User
        roles: !php/const App\Security\UserRoles::ASSIGNABLE
        permission: !php/const App\Security\PermissionCodes::USER_CREATE
```

admin-bundle met dataflow-bundle en `suggest` : l'import de comptes ne se charge que si dataflow est là.

### 3. Ce qui reste dans chaque application

| Application | Après le lot |
|---|---|
| devingame, devinthehood, wtg, squelette (backoffice) | `dataflow.import` et `admin.user_import`, les routes `admin_user_import_*` ; les quatre classes recopiées et leurs gabarits disparaissent |
| tradebot | idem pour les comptes ; ses deux imports d'indices deviennent deux définitions qui lisent `{id}` dans le contexte |
| cereezer | `dataflow.import`, avec l'aperçu (`preview: true`) ; ses six imports restent les siens (organisation, `DuplicatePolicy::Update`, invitations), en définitions du bundle ; `ImportController`, `UploadedImportStore` et le registre disparaissent |
| cegeta | `dataflow.import` ; son import de membres devient une définition, et son quota un écouteur d'`ImportStartingEvent` |
| superp | **à confirmer** (voir plus bas) |
| devinlive, devinthecode | pas d'import : non concernés |

## Comportements qui changent (assumés)

- **La famille** : trois écrans au lieu de deux. La correspondance devient une page qu'on peut recharger. Le
  fichier vit sous `var/import`, et la session n'en garde que le nom.
- **Les routes** gardent leurs noms (`admin_user_import_new`, `_execute`). La correspondance gagne la sienne.
- **tradebot et cegeta** gardent leur plafond de 1 Mio.
- Partout ailleurs : les mêmes champs, les mêmes refus et le même rapport.

## Points à confirmer avant de coder

1. **Le rangement** : l'écran va dans dataflow-bundle, et l'import de comptes dans admin-bundle (règle du plan
   T20.1). L'autre possibilité serait tout dans dataflow-bundle, avec la classe du compte en configuration. Elle
   ferait dépendre dataflow-bundle des rôles et du mot de passe d'un compte, qui ne sont pas son métier.
2. **Trois écrans plutôt que deux** pour la famille, comme chez cereezer.
3. **superp** : son import (`user`, `product`) et celui de son CRM suivent le même patron. superp aurait alors
   deux définitions. Ses mappeurs gardent leur quota de sièges et leurs jetons d'invitation. Sa section « NOT
   adopted » ne l'exclut pas. Le faire dans ce lot ? La proposition est oui, après les autres applications.
4. **L'aperçu de cereezer en option du bundle**, plutôt qu'une fonction qui lui reste propre.

## Tests exigés

- **Bundle**, chaque garde :
  - un fichier trop lourd ;
  - un type MIME refusé ;
  - un classeur binaire ;
  - un nom de session forgé (`../../.env`, un chemin absolu) ;
  - un fichier consommé deux fois.
- **Bundle**, le parcours en trois écrans sur une définition de test : la correspondance par position, deux
  colonnes vers un champ, `ImportFailedException` (rapport, ligne, annulation), le modèle téléchargé.
- **Bundle**, le reste : l'événement (une réponse posée court-circuite le lancement) ; le droit vérifié à chaque
  action ; la configuration (désactivée par défaut) ; dépendances hautes et basses ; PHPStan, Rector, style.
- **admin-bundle** : le mappeur, avec les champs requis, l'adresse invalide, le rôle hors liste et
  `ROLE_SUPER_ADMIN` refusé à un administrateur et accepté d'un super-administrateur ; le résolveur, avec la même
  adresse deux fois dans un fichier et une adresse déjà en base, le tout en une requête.
- **Chaque application** : `UserImportTest` joué avant et après (avec `testOnlyASuperAdminCanImportASuperAdmin`),
  et les tests d'import de cereezer, tradebot et cegeta ; porte complète.
- **Dans Chrome, avant et après** :
  - importer un fichier de trois comptes, dont un doublon, et lire le rapport ;
  - un classeur `.xlsx`, refusé avec son message ;
  - chez cereezer, l'aperçu ;
  - chez cegeta, le quota.
- **Squelette** : les cinq modes générés, leur porte jouée, et le parcours Chrome sur un backoffice généré.

## Conséquences

- dataflow-bundle 1.16.0 (son badge passe à v1.16.0) et admin-bundle 1.44.0 (son badge n'écrit que la majeure),
  des versions mineures. Les applications montent leur plancher.
- Démonstrations d'abord (superp si confirmé, cereezer, tradebot, wtg, devinthehood), puis devingame et cegeta.
- Fin du lot, puis de T20 : la mesure de duplication est rejouée. Les classes de ce tableau ne doivent plus
  exister dans les applications ni dans le squelette.
