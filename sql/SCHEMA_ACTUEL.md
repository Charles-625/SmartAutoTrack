# Schéma actuel de la base `charles` (depuis le 23/09/2026)

Ce schéma a été créé directement en base par l'utilisateur, indépendamment des
fichiers `.sql` du dépôt (tous obsolètes, voir le bandeau sur chacun d'eux).
Il n'existe pas de script de création : ce document sert de référence, à jour
après les 6 phases d'adaptation du code (voir historique de session).

## Tables

```
utilisateur (idUtilisateur, nom, prenom, motDePasse, email, telephone,
             themePreference, photoProfil, dateCreation)
 ├─ administrateur (idAdministrateur)
 ├─ client (idClient, typeClient)
 │   ├─ particulier (idClient, adresse)
 │   └─ entreprise (idClient, idEntreprise, raisonSociale, adresse)
 └─ technicien (idTechnicien, idGarage, specialite, statutValidation,
                competences, experience)

garage (idGarage, nomGarage, adresse, statutGarage)
 └─ documentgarage (idDocument, idGarage, typeDocument, statutVerification, fichier)

vehicule (idVehicule, idClient, idGarage, marque, modele, dateAcquisition,
          etat, numeroChassis, immatriculation, couleur, kilometrage, annee,
          dateCreation)
anomalie (idAnomalie, idVehicule, description, dateDetection, dateResolution,
          type, niveau, statut)
intervention (idIntervention, idClient, idVehicule, idTechnicien, idGarage,
              type, description, priorite, dateIntervention, statut)
reparation (idReparation, idIntervention, idTechnicien, titre, description,
            diagnostic, travauxEffectues, piecesUtilisees, recommandations,
            cout, dureeIntervention, dateReparation, statut)
paiement, assistantia, analyseia, journalactivites   (non utilisées par le
            site PHP actuel — prévues pour un périmètre futur)

-- Tables reprises telles quelles de l'ancien schéma (pas d'équivalent neuf) :
messages (id, expediteur_id, destinataire_id, sujet, contenu, lu, date_envoi)
notifications (id, user_id, type, titre, message, lu, date_creation)
technician_documents (id, technicien_id, type_document, nom_fichier,
                       chemin_fichier, taille_fichier, uploaded_at)
```

## Colonnes ajoutées pendant la migration du code (phases 2-6)

Toutes ajoutées par `scripts/migrate_legacy_data.php` (idempotent, relançable).
Aucune n'existait dans le schéma initial du 23/09 :

| Table | Colonnes ajoutées | Pourquoi |
|---|---|---|
| `utilisateur` | `dateCreation`, `themePreference`, `photoProfil` | tri par date d'inscription, thème d'interface |
| `technicien` | `statutValidation`, `competences`, `experience` | validation admin, fiche technicien |
| `vehicule` | `kilometrage`, `annee`, `dateCreation` | champs du formulaire véhicule existant |
| `anomalie` | `type`, `niveau`, `dateResolution` | classification et affichage (faible/moyen/critique) |
| `intervention` | `priorite` | filtre technicien |
| `reparation` | `titre`, `diagnostic`, `travauxEffectues`, `piecesUtilisees`, `recommandations`, `dureeIntervention` | contenu du rapport de réparation |

## Pas de colonne `role`

Le rôle d'un utilisateur se déduit de la table où son id apparaît
(`administrateur`/`client`/`technicien`), jamais d'une colonne. Toujours passer
par `config/roles.php` (`getUserRole()`, `getUserProfile()`, `isAccountUsable()`,
`findUserByEmail()`) plutôt que de la redéduire à la main.

## Vocabulaire de statut : traduit à la volée, jamais stocké

Le code SQL traduit systématiquement les nouvelles valeurs (souvent en
MAJUSCULES) vers le vocabulaire historique attendu par chaque gabarit HTML/JS,
via `LOWER()` ou `CASE`. Deux points à connaître :

- **`reparation.statut`** n'a que 3 valeurs (`EN_ATTENTE`, `EN_COURS`,
  `TERMINEE`) — il n'y a plus d'étape de validation séparée. Chaque page
  traduit différemment :
  - vue **client** (`client/*.php`) : `TERMINEE` → `validee` (débloque le
    téléchargement du rapport)
  - vue **technicien** (`technicien/*.php`) : `TERMINEE` → `valide`
  - `rejete` n'a plus d'équivalent (filtre → toujours 0 résultat)
- **`anomalie.statut`** a 4 valeurs (`NOUVELLE`, `EN_COURS`, `TRAITEE`,
  `IGNOREE`) contre 3 avant ; `IGNOREE` est affichée comme « résolue ».

## Contraintes RESTRICT (nouveau, plus strict qu'avant)

`vehicule.idClient`, `intervention.idClient`, `intervention.idVehicule`,
`paiement.*` sont en `RESTRICT`, pas `CASCADE`. Une suppression en cascade
(client, véhicule) doit donc supprimer explicitement `paiement` puis
`intervention` puis `vehicule` avant `utilisateur` — voir `admin/clients.php`
et `client/vehicles.php` pour l'implémentation.

## Limites connues, assumées

- Pas de statut actif/inactif pour un **client** (seulement pour un technicien) :
  le bouton correspondant dans `admin/clients.php` est un no-op.
- Une **intervention** n'a plus de colonne « créée par quel admin ».
- `reparation` n'a pas de lien direct vers une anomalie précise
  (`anomalie_type` est donc toujours vide dans les rapports).
