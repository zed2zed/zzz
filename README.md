# Logiciel de Prêt de Matériel

## Description

Ce logiciel est une application web PHP/MySQL conçue pour faciliter la gestion des prêts de matériel au sein d'une organisation. Il permet aux utilisateurs de demander du matériel, et au service informatique (ou administrateurs) de valider ces demandes, de suivre les prêts en cours, de gérer les retours, et de maintenir un inventaire du matériel disponible.

## Fonctionnalités Principales

*   **Demande de Prêt Utilisateur :**
    *   Consultation des types de matériel disponibles et de leur stock.
    *   Formulaire de demande de prêt simple (choix du type de matériel, quantité, dates souhaitées, informations de contact).
*   **Administration des Prêts :**
    *   Tableau de bord pour visualiser toutes les demandes de prêt (en attente, validées, retournées, annulées).
    *   Validation des demandes "en attente" :
        *   Assignation de matériel(s) spécifique(s) à un prêt.
        *   Définition des dates effectives de début et de fin de prêt.
        *   Mise à jour automatique des états des items spécifiques ('emprunté') et des quantités disponibles des types de matériel.
    *   Gestion des retours de matériel :
        *   Marquage d'un prêt comme "retourné".
        *   Mise à jour automatique des états des items spécifiques ('disponible') et des quantités disponibles des types de matériel.
    *   Annulation des demandes "en attente".
*   **Notifications par Email :**
    *   Envoi d'un email au **valideur désigné** lorsqu'une nouvelle demande de prêt est soumise. L'email contient un lien direct vers la demande dans l'interface d'administration.
    *   Envoi d'un email au **demandeur** lorsque sa demande de prêt est validée par un administrateur, avec les détails du matériel attribué et les dates effectives.
*   **Module Calendrier des Prêts :**
    *   Fournit une vue hebdomadaire verticale des prêts, affichant les débuts et fins (effectives ou souhaitées si non retourné) de prêts pour chaque jour.
    *   Chaque événement affiché dans le calendrier est cliquable et mène directement au détail du prêt concerné dans l'interface d'administration (`admin_prets.php`), avec le prêt en question mis en évidence.
    *   Permet une navigation par semaine pour visualiser les activités de prêt passées, présentes et futures.
*   **Gestion de l'Inventaire :**
    *   **Types de Matériel :**
        *   Ajout, modification et suppression des catégories générales de matériel (ex: "Ordinateur Portable", "Vidéoprojecteur").
        *   Gestion de la quantité totale possédée pour chaque type.
        *   La quantité disponible est calculée automatiquement en fonction des items spécifiques et de leurs états.
        *   Protection contre la suppression d'un type si des items spécifiques y sont encore associés.
    *   **Matériels Spécifiques (Items Individuels) :**
        *   Ajout, modification et suppression d'items individuels avec un identifiant unique (ex: numéro de série).
        *   Association à un type de matériel.
        *   Gestion de l'état de chaque item ('disponible', 'en maintenance', 'hors service'). L'état 'emprunté' est géré via le module de prêt.
        *   Mise à jour automatique de la quantité disponible du type de matériel parent lors de l'ajout/modification/suppression d'un item ou de son état.
        *   Protection contre la suppression d'un item s'il est actuellement 'emprunté'.
*   **Interface Utilisateur :**
    *   Utilisation de Bootstrap 5 pour une interface responsive et moderne.
    *   Utilisation de Tempus Dominus pour une sélection de date et heure conviviale.
    *   Validation des formulaires côté client et côté serveur.
    *   Messages de feedback clairs pour les actions utilisateur.
*   **Tests (Infrastructure) :**
    *   PHPUnit est configuré pour permettre l'écriture de tests unitaires (structure de test et exemples fournis).

## Prérequis Techniques

*   **PHP :** Version 7.4+ (testé avec PHP 8.x également)
*   **Serveur Web :** Apache, Nginx, ou tout autre serveur web supportant PHP.
*   **Base de données :** MySQL version 5.7+ ou MariaDB 10.2+.
*   **Extensions PHP :**
    *   `mysqli` (utilisée pour la connexion à la base de données dans les scripts principaux).
    *   `pdo_mysql` (nécessaire si vous adaptez `config.php` pour utiliser PDO, ou pour PHPUnit avec DB).
    *   `mbstring` (souvent activée par défaut, bonne pratique pour les chaînes de caractères).
*   **Navigateur Web :** Chrome, Firefox, Safari, Edge modernes.
*   **Composer :** Pour l'installation des dépendances de développement (PHPUnit).

## Instructions d'Installation

1.  **Clonage/Téléchargement :**
    *   Clonez ce dépôt : `git clone <url_du_depot>`
    *   Ou téléchargez et décompressez l'archive ZIP des fichiers dans le répertoire de votre choix sur votre serveur web.

2.  **Installation des dépendances (PHPUnit) :**
    *   Assurez-vous que [Composer](https://getcomposer.org/) est installé sur votre système.
    *   Ouvrez un terminal à la racine du projet et lancez :
        ```bash
        composer install --dev --no-interaction
        ```
    *   Cela installera PHPUnit dans le dossier `vendor/`.

3.  **Création de la Base de Données :**
    *   Connectez-vous à votre serveur MySQL.
    *   Créez une nouvelle base de données. Par exemple :
        ```sql
        CREATE DATABASE gestion_materiel_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
        ```

4.  **Importation du Schéma :**
    *   Utilisez le fichier `schema.sql` fourni pour créer la structure des tables. Exécutez la commande suivante depuis votre terminal (adaptez les identifiants et le nom de la base) :
        ```bash
        mysql -u votre_utilisateur_mysql -p gestion_materiel_db < schema.sql
        ```
    *   Vous serez invité à entrer le mot de passe de l'utilisateur MySQL.

5.  **Configuration (`config.php`) :**
    *   Le fichier `config.php` à la racine du projet doit être configuré avec vos identifiants de base de données.
    *   Ouvrez `config.php` et modifiez les lignes suivantes avec vos informations :
        ```php
        <?php
        // config.php

        // Informations de connexion à la base de données
        // Décommentez et remplacez par vos informations réelles

        // define('DB_SERVER', 'localhost');
        // define('DB_USERNAME', 'votre_utilisateur_mysql');
        // define('DB_PASSWORD', 'votre_mot_de_passe_mysql');
        // define('DB_NAME', 'gestion_materiel_db'); // Le nom de la base que vous avez créée

        /* ... (commentaires existants sur la BDD) ... */

        // Configuration des emails
        // IMPORTANT : Remplacez 'votre_email_valideur@example.com' par une adresse email réelle 
        // à laquelle vous avez accès pour pouvoir tester la réception des notifications de demande.
        define('EMAIL_VALIDEUR', 'votre_email_valideur@example.com'); 
        define('EMAIL_EXPEDITEUR_NO_REPLY', 'no-reply@gestmat.local'); // Ou votre domaine actuel/prévu

        // URL de base de l'application (utilisée dans les emails)
        // IMPORTANT: Remplacez par l'URL réelle de votre application en production/test
        define('APP_URL', 'http://localhost/gestion-materiel'); // Exemple, adaptez selon votre configuration
        ?>
        ```
    *   **Détails des constantes de configuration pour les emails :**
        *   `EMAIL_VALIDEUR` : L'adresse email de l'administrateur/valideur qui reçoit les notifications de nouvelles demandes. **Il est crucial de remplacer la valeur par défaut par une adresse email réelle et accessible pour tester la fonctionnalité.**
        *   `EMAIL_EXPEDITEUR_NO_REPLY` : L'adresse d'expédition utilisée pour les emails (par exemple, `no-reply@votredomaine.com`). Elle peut être fictive si votre serveur d'envoi le permet, mais doit être syntaxiquement valide.
        *   `APP_URL` : L'URL de base complète de votre application (ex: `http://localhost/votre_projet` ou `https://votre-site.com`). Elle est utilisée pour construire des liens corrects dans les emails de notification. Assurez-vous qu'elle est correcte pour l'environnement où l'application est déployée.
    *   **Note importante sur la connexion à la base de données :** L'application actuelle utilise l'extension `mysqli` pour se connecter à la base de données directement dans chaque script PHP qui en a besoin. Le fichier `config.php` sert uniquement à définir les constantes de connexion et de configuration.
    *   **Note cruciale sur l'envoi d'emails :** Pour que la fonctionnalité d'envoi d'emails fonctionne, votre environnement PHP doit être correctement configuré pour utiliser la fonction `mail()`.
        *   Cela peut impliquer la configuration du fichier `php.ini` pour spécifier un serveur SMTP (par exemple, en utilisant les directives `SMTP` et `smtp_port` si vous êtes sous Windows, ou en configurant `sendmail_path` si vous êtes sous Linux/macOS).
        *   Alternativement, pour le développement et les tests, vous pouvez utiliser des outils comme [MailHog](https://github.com/mailhog/MailHog) ou [Mailtrap](https://mailtrap.io/) qui simulent un serveur SMTP et capturent les emails sortants. Cela vous permet de visualiser les emails envoyés par l'application sans qu'ils ne soient réellement expédiés à des adresses externes.

6.  **Déploiement :**
    *   Assurez-vous que le répertoire du projet est accessible par votre serveur web (par exemple, dans `htdocs/`, `www/`, ou un VirtualHost configuré).
    *   Vérifiez les permissions des fichiers si nécessaire.

7.  **Accès à l'application :**
    *   Ouvrez votre navigateur web et accédez à `index.php`. Par exemple : `http://localhost/votre_projet/index.php`.
    *   Le calendrier des prêts est accessible via le chemin `[APP_URL]/calendrier/` (par exemple, `http://localhost/votre_projet/calendrier/`).

## Structure du Projet

*   `index.php`: Page d'accueil pour les utilisateurs, affichage du matériel disponible et formulaire de demande de prêt.
*   `admin_prets.php`: Interface d'administration pour la gestion des demandes de prêt (validation, retour, annulation).
*   `demande_pret.php`: Script PHP pour le traitement des soumissions de formulaires de demande de prêt depuis `index.php`.
*   `gestion_pret.php`: Script PHP pour le traitement des actions d'administration sur les prêts (validation, retour, annulation) depuis `admin_prets.php`.
*   `gestion_inventaire.php`: Interface d'administration pour la gestion des types de matériel et des items spécifiques de l'inventaire (CRUD).
*   `config.php`: Fichier de configuration contenant les constantes pour la connexion à la base de données et la configuration des emails. **Doit être configuré manuellement.**
*   `schema.sql`: Script SQL pour créer la structure de la base de données et les tables nécessaires.
*   `utils/`: Répertoire contenant les scripts utilitaires.
    *   `email_sender.php`: Contient la fonction `envoyer_email()` pour l'expédition des notifications.
*   `calendrier/`: Répertoire du module Calendrier.
    *   `index.php`: Page principale du calendrier des prêts, affichant une vue hebdomadaire.
*   `composer.json`: Fichier de configuration pour Composer, définissant les dépendances de développement (PHPUnit).
*   `phpunit.xml.dist`: Fichier de configuration pour PHPUnit, indiquant comment exécuter les tests.
*   `tests/`: Répertoire contenant les tests unitaires PHPUnit.
    *   `InventoryLogicTest.php`: Exemple de classe de test (actuellement avec des tests marqués comme incomplets).
*   `vendor/`: Répertoire (généré par Composer) contenant les dépendances téléchargées (PHPUnit). Il est généralement inclus dans `.gitignore`.

## (Optionnel) Lancer les Tests

L'application est configurée avec une structure de base pour les tests unitaires avec PHPUnit.

1.  **Configuration de la base de données de test (Optionnel mais recommandé) :**
    *   Les tests peuvent nécessiter une base de données séparée. Vous pouvez configurer les identifiants de cette base de données de test via des variables d'environnement que PHPUnit peut lire.
    *   Modifiez le fichier `phpunit.xml.dist` et décommentez/adaptez les lignes `<env name="DB_HOST" ... />` etc., pour pointer vers votre base de données de test.
    *   Assurez-vous que votre fichier `config.php` est capable de lire ces variables d'environnement si elles sont définies (ce qui n'est pas le cas par défaut dans la version actuelle du `config.php`). Pour une suite de tests complète, `config.php` et la logique de connexion dans les scripts devraient être adaptés pour permettre une configuration de base de données alternative en mode test.
    *   Chargez le schéma (`schema.sql`) dans votre base de données de test.

2.  **Exécution des tests :**
    *   Depuis la racine du projet, exécutez :
        ```bash
        ./vendor/bin/phpunit
        ```
    *   Les tests actuels dans `tests/InventoryLogicTest.php` sont des placeholders et marqués comme incomplets. Ils servent d'exemple pour la structure. Pour des tests fonctionnels, la logique métier dans les scripts principaux (comme `gestion_inventaire.php`) devrait être refactorisée en classes ou fonctions plus modulaires et testables.

---

Ce README fournit une vue d'ensemble du projet, de son installation et de son utilisation.
