-- Script de création de la base de données MySQL pour l'application de prêt de matériel (version 2)

-- Supprimer les tables existantes si elles existent pour permettre une réexécution propre
DROP TABLE IF EXISTS `details_pret_materiel_specifique`;
DROP TABLE IF EXISTS `prets`;
DROP TABLE IF EXISTS `materiels_specifiques`;
DROP TABLE IF EXISTS `types_materiel`;
DROP TABLE IF EXISTS `utilisateurs`;

-- Table: utilisateurs
CREATE TABLE `utilisateurs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `nom` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: types_materiel
CREATE TABLE `types_materiel` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `nom_type` VARCHAR(255) NOT NULL UNIQUE COMMENT "Ex: Ordinateur portable, Vidéoprojecteur",
    `description_type` TEXT NULL,
    `quantite_totale` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "Nombre total d'items de ce type que le service possède",
    `quantite_disponible` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT "Nombre d'items de ce type actuellement disponibles. Sera mis à jour par l'application."
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: materiels_specifiques
CREATE TABLE `materiels_specifiques` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `type_materiel_id` INT NOT NULL,
    `identifiant_unique` VARCHAR(255) NOT NULL UNIQUE COMMENT "Numéro de série, d'inventaire, ou autre identifiant unique",
    `etat` VARCHAR(50) NOT NULL DEFAULT 'disponible' COMMENT "disponible, emprunté, en maintenance, hors service",
    `notes` TEXT NULL COMMENT "Notes spécifiques à cet item, ex: configuration, historique de maintenance",
    FOREIGN KEY (`type_materiel_id`) REFERENCES `types_materiel`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: prets
CREATE TABLE `prets` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `utilisateur_id` INT NOT NULL,
    `type_materiel_id` INT NOT NULL COMMENT "Type de matériel demandé",
    `quantite_demandee` INT UNSIGNED NOT NULL DEFAULT 1,
    `d_deb_souhaitee` DATETIME NULL COMMENT "Date de début souhaitée par l'utilisateur",
    `d_fin_souhaitee` DATETIME NULL COMMENT "Date de fin souhaitée par l'utilisateur",
    `d_deb_effective` DATETIME NULL COMMENT "Date de début réelle, remplie par le service IT",
    `d_fin_effective` DATETIME NULL COMMENT "Date de fin réelle, remplie par le service IT",
    `lieu` VARCHAR(255) NULL,
    `pers_contact` VARCHAR(255) NULL COMMENT "Nom de la personne de contact pour ce prêt",
    `comment` TEXT NULL COMMENT "Commentaire général sur le prêt",
    `statut_validation` VARCHAR(50) NOT NULL DEFAULT 'en attente' COMMENT "en attente, validé, partiellement validé, retourné, annulé",
    `date_demande` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`),
    FOREIGN KEY (`type_materiel_id`) REFERENCES `types_materiel`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table: details_pret_materiel_specifique
CREATE TABLE `details_pret_materiel_specifique` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `pret_id` INT NOT NULL,
    `materiel_specifique_id` INT NOT NULL,
    FOREIGN KEY (`pret_id`) REFERENCES `prets`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`materiel_specifique_id`) REFERENCES `materiels_specifiques`(`id`),
    CONSTRAINT `uniq_pret_materiel` UNIQUE (`pret_id`, `materiel_specifique_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Note sur ON DELETE CASCADE pour materiels_specifiques:
-- Si un type_materiel est supprimé, tous les materiels_specifiques de ce type sont également supprimés.
-- C'est un choix de conception. Si l'on souhaitait empêcher la suppression d'un type_materiel
-- tant qu'il y a des materiels_specifiques associés, il faudrait retirer ON DELETE CASCADE
-- et gérer cela au niveau applicatif ou par des triggers.

-- Note sur ON DELETE pour prets:
-- Si un utilisateur ou un type_materiel est supprimé, la clé étrangère dans `prets`
-- par défaut (RESTRICT) empêchera la suppression si des prêts y sont liés.
-- C'est généralement le comportement souhaité pour ne pas perdre l'historique des prêts.

-- Note sur ON DELETE pour details_pret_materiel_specifique:
-- Si un prêt est supprimé, les détails associés sont supprimés (ON DELETE CASCADE).
-- Si un materiel_specifique est supprimé, la suppression sera empêchée par défaut (RESTRICT)
-- s'il est lié à un détail de prêt. Pour permettre la suppression du materiel_specifique
-- et que le détail soit aussi supprimé, il faudrait ajouter ON DELETE CASCADE aussi sur materiel_specifique_id.
-- Cependant, il est souvent préférable de marquer un matériel comme 'hors service' plutôt que de le supprimer
-- s'il a un historique de prêts. La configuration actuelle empêche la suppression d'un matériel spécifique
-- s'il est dans un détail de prêt.
