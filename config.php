<?php
// config.php

// Informations de connexion à la base de données
// Décommentez et remplacez par vos informations réelles

// define('DB_SERVER', 'localhost');
// define('DB_USERNAME', 'votre_utilisateur'); // Remplacez par votre nom d'utilisateur MySQL
// define('DB_PASSWORD', 'votre_mot_de_passe'); // Remplacez par votre mot de passe MySQL
// define('DB_NAME', 'votre_base_de_donnees'); // Remplacez par le nom de votre base de données (créée avec schema.sql)

/*
Pour que l'application fonctionne, vous devrez :
1. Avoir un serveur MySQL en cours d'exécution.
2. Créer une base de données (par exemple, 'gestion_materiel_db').
3. Importer la structure de la base de données en utilisant le fichier `schema.sql` fourni.
   Exemple de commande (depuis le terminal, après vous être connecté à MySQL) :
   CREATE DATABASE gestion_materiel_db;
   USE gestion_materiel_db;
   SOURCE /chemin/vers/votre/schema.sql;
4. Créer un utilisateur MySQL ayant les droits sur cette base de données.
   Exemple de commandes MySQL :
   CREATE USER 'nom_utilisateur'@'localhost' IDENTIFIED BY 'mot_de_passe_securise';
   GRANT ALL PRIVILEGES ON gestion_materiel_db.* TO 'nom_utilisateur'@'localhost';
   FLUSH PRIVILEGES;
5. Décommenter les lignes `define` ci-dessus et remplacer les valeurs par celles de votre configuration.
*/

// Configuration des emails
// IMPORTANT : Remplacez 'votre_email_valideur@example.com' par une adresse email réelle 
// à laquelle vous avez accès pour pouvoir tester la réception des notifications de demande.
define('EMAIL_VALIDEUR', 'votre_email_valideur@example.com'); 
define('EMAIL_EXPEDITEUR_NO_REPLY', 'no-reply@gestmat.local'); // Ou votre domaine actuel/prévu

// URL de base de l'application (utilisée dans les emails)
// IMPORTANT: Remplacez par l'URL réelle de votre application en production/test
define('APP_URL', 'http://localhost/gestion-materiel'); // Exemple, adaptez selon votre configuration

?>
