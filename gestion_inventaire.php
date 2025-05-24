<?php
session_start();
@include 'config.php';

$conn = null;
$erreur_connexion = "";
$feedback_message = null;

if (isset($_SESSION['feedback_message'])) {
    $feedback_message = $_SESSION['feedback_message'];
    unset($_SESSION['feedback_message']);
}

if (defined('DB_SERVER') && defined('DB_USERNAME') && defined('DB_PASSWORD') && defined('DB_NAME')) {
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($conn->connect_error) {
            $erreur_connexion = "Erreur de connexion à la base de données : " . $conn->connect_error;
        } else {
            $conn->set_charset("utf8");
        }
    } catch (Exception $e) {
        $erreur_connexion = "Erreur de connexion à la base de données : " . $e->getMessage();
    }
} else {
    $erreur_connexion = "Le fichier de configuration (config.php) n'est pas correctement configuré ou n'est pas inclus. Veuillez définir les constantes DB_SERVER, DB_USERNAME, DB_PASSWORD, et DB_NAME.";
}

<?php
<?php
// This block should be at the very top, before any HTML output if redirecting.
// However, since we also display data on this page, we'll handle POST logic here,
// then the rest of the script will re-fetch data to show updated lists.

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn) && $conn) {
    //======================================================================
    // START CRUD Logique pour TYPES DE MATERIEL (depuis la modale)
    //======================================================================
    if (isset($_POST['action'])) { // Actions: add_type, edit_type
        $action = $_POST['action'];
        $nom_type = isset($_POST['nom_type']) ? trim($_POST['nom_type']) : '';
        $description_type = isset($_POST['description_type']) ? trim($_POST['description_type']) : '';
        $quantite_totale_str = isset($_POST['quantite_totale']) ? trim($_POST['quantite_totale']) : '';
        $type_materiel_id = isset($_POST['type_materiel_id']) ? filter_var(trim($_POST['type_materiel_id']), FILTER_VALIDATE_INT) : null;

        // Validation commune
        if (empty($nom_type)) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Le nom du type de matériel ne peut pas être vide.'];
        } elseif (!is_numeric($quantite_totale_str) || intval($quantite_totale_str) < 0) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'La quantité totale doit être un nombre entier non négatif.'];
        } else {
            $quantite_totale = intval($quantite_totale_str);

            if ($action === 'add_type') {
                // Vérification doublon nom_type pour ajout
                $stmt_check_nom = $conn->prepare("SELECT id FROM types_materiel WHERE nom_type = ?");
                $stmt_check_nom->bind_param("s", $nom_type);
                $stmt_check_nom->execute();
                $result_check_nom = $stmt_check_nom->get_result();
                if ($result_check_nom->num_rows > 0) {
                    $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Un type de matériel avec ce nom existe déjà.'];
                } else {
                        // Ajout du type de matériel
                        // quantite_disponible est initialisée à quantite_totale
                    $stmt = $conn->prepare("INSERT INTO types_materiel (nom_type, description_type, quantite_totale, quantite_disponible) VALUES (?, ?, ?, ?)");
                    if ($stmt) {
                            $stmt->bind_param("ssii", $nom_type, $description_type, $quantite_totale, $quantite_totale); 
                        if ($stmt->execute()) {
                            $_SESSION['feedback_message'] = ['type' => 'success', 'message' => 'Type de matériel ajouté avec succès.'];
                        } else {
                            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Erreur lors de l\'ajout : ' . $stmt->error];
                        }
                        $stmt->close();
                    } else {
                         $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Erreur de préparation de la requête d\'ajout: ' . $conn->error];
                    }
                }
                $stmt_check_nom->close();

            } elseif ($action === 'edit_type') {
                    // Modification d'un type de matériel existant
                if (!$type_materiel_id) {
                    $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'ID du type de matériel invalide pour la modification.'];
                } else {
                    // Vérification doublon nom_type pour modification (excluant l'ID actuel)
                    $stmt_check_nom_edit = $conn->prepare("SELECT id FROM types_materiel WHERE nom_type = ? AND id != ?");
                    $stmt_check_nom_edit->bind_param("si", $nom_type, $type_materiel_id);
                    $stmt_check_nom_edit->execute();
                    $result_check_nom_edit = $stmt_check_nom_edit->get_result();

                    if ($result_check_nom_edit->num_rows > 0) {
                        $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Un autre type de matériel avec ce nom existe déjà.'];
                    } else {
                            // Validation de la quantité totale :
                            // La nouvelle quantité totale ne peut pas être inférieure au nombre d'items déjà "sortis" (empruntés ou en maintenance).
                            // Nombre d'items non disponibles = quantite_totale (ancienne) - quantite_disponible (ancienne)
                        $stmt_get_qtes = $conn->prepare("SELECT quantite_totale, quantite_disponible FROM types_materiel WHERE id = ?");
                        $stmt_get_qtes->bind_param("i", $type_materiel_id);
                        $stmt_get_qtes->execute();
                        $result_qtes = $stmt_get_qtes->get_result();
                        if($current_qtes = $result_qtes->fetch_assoc()) {
                                $aqt = $current_qtes['quantite_totale']; // Ancienne Quantité Totale
                                $aqd = $current_qtes['quantite_disponible']; // Ancienne Quantité Disponible
                                $items_non_disponibles = $aqt - $aqd;

                            if ($quantite_totale < $items_non_disponibles) {
                                    $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => "La quantité totale ne peut pas être inférieure au nombre d'items actuellement non disponibles ($items_non_disponibles)."];
                            } else {
                                    // Calcul de la nouvelle quantité disponible :
                                    // NQD = AQD + (Nouvelle QTE Totale - Ancienne QTE Totale)
                                $nqd = $aqd + ($quantite_totale - $aqt);
                                    if ($nqd < 0) $nqd = 0; // Sécurité, ne devrait pas arriver si la logique ci-dessus est correcte

                                $stmt = $conn->prepare("UPDATE types_materiel SET nom_type = ?, description_type = ?, quantite_totale = ?, quantite_disponible = ? WHERE id = ?");
                                if ($stmt) {
                                    $stmt->bind_param("ssiii", $nom_type, $description_type, $quantite_totale, $nqd, $type_materiel_id);
                                    if ($stmt->execute()) {
                                        $_SESSION['feedback_message'] = ['type' => 'success', 'message' => 'Type de matériel modifié avec succès.'];
                                    } else {
                                        $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Erreur de modification : ' . $stmt->error];
                                    }
                                    $stmt->close();
                                } else {
                                    $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Erreur de préparation de la requête de modification: ' . $conn->error];
                                }
                            }
                        } else {
                             $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Type de matériel non trouvé pour la validation des quantités.'];
                        }
                        $stmt_get_qtes->close();
                    }
                    $stmt_check_nom_edit->close();
                }
            }
        }
        // Redirection après traitement pour éviter resoumission du formulaire et pour rafraîchir
        header("Location: gestion_inventaire.php");
        exit();
    }

        // Traitement pour Suppression d'un type de matériel
    if (isset($_POST['action_delete_type']) && $_POST['action_delete_type'] === 'delete_type_confirm') {
        $type_materiel_id_delete = isset($_POST['type_materiel_id_delete']) ? filter_var(trim($_POST['type_materiel_id_delete']), FILTER_VALIDATE_INT) : null;

        if (!$type_materiel_id_delete) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'ID du type de matériel invalide pour la suppression.'];
        } else {
                // Vérification cruciale : ne pas supprimer si des items spécifiques sont encore liés.
            $stmt_check_items = $conn->prepare("SELECT id FROM materiels_specifiques WHERE type_materiel_id = ? LIMIT 1");
            $stmt_check_items->bind_param("i", $type_materiel_id_delete);
            $stmt_check_items->execute();
            $result_check_items = $stmt_check_items->get_result();

            if ($result_check_items->num_rows > 0) {
                $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Impossible de supprimer ce type : des items spécifiques y sont encore associés. Veuillez d\'abord supprimer ou réassigner ces items.'];
            } else {
                $stmt_delete = $conn->prepare("DELETE FROM types_materiel WHERE id = ?");
                if ($stmt_delete) {
                    $stmt_delete->bind_param("i", $type_materiel_id_delete);
                    if ($stmt_delete->execute()) {
                        $_SESSION['feedback_message'] = ['type' => 'success', 'message' => 'Type de matériel supprimé avec succès.'];
                    } else {
                        $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Erreur lors de la suppression : ' . $stmt_delete->error];
                    }
                    $stmt_delete->close();
                } else {
                     $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Erreur de préparation de la requête de suppression: ' . $conn->error];
                }
            }
            $stmt_check_items->close();
        }
        header("Location: gestion_inventaire.php");
        exit();
    }
}
        header("Location: gestion_inventaire.php"); // Default redirect for type actions
        exit();
    }

    // Traitement pour Ajout/Modification Matériel Spécifique
    if (isset($_POST['action_item_specific'])) {
        $action_item = $_POST['action_item_specific'];
        $materiel_specifique_id = isset($_POST['materiel_specifique_id']) ? filter_var(trim($_POST['materiel_specifique_id']), FILTER_VALIDATE_INT) : null;
        $type_materiel_id_specific = isset($_POST['type_materiel_id_specific_modal']) ? filter_var(trim($_POST['type_materiel_id_specific_modal']), FILTER_VALIDATE_INT) : null;
        $identifiant_unique = isset($_POST['identifiant_unique']) ? trim($_POST['identifiant_unique']) : '';
        $etat_specific = isset($_POST['etat_specific_modal']) ? trim($_POST['etat_specific_modal']) : '';
        $notes_specific = isset($_POST['notes_specific_modal']) ? trim($_POST['notes_specific_modal']) : '';
        $allowed_etats = ['disponible', 'en maintenance', 'hors service'];

        // Validations communes pour ajout/modif item
        if (empty($identifiant_unique)) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'L\'identifiant unique ne peut pas être vide.'];
        } elseif (!$type_materiel_id_specific) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'Le type de matériel est requis.'];
        } elseif (!in_array($etat_specific, $allowed_etats)) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'État sélectionné non valide.'];
        } else {
            $conn->begin_transaction();
            try {
                if ($action_item === 'add_item_specific') {
                    // Vérif identifiant unique
                    $stmt_check_identifiant = $conn->prepare("SELECT id FROM materiels_specifiques WHERE identifiant_unique = ?");
                    $stmt_check_identifiant->bind_param("s", $identifiant_unique);
                    $stmt_check_identifiant->execute();
                    if ($stmt_check_identifiant->get_result()->num_rows > 0) {
                        throw new Exception("Un item avec cet identifiant unique existe déjà.");
                    }
                    $stmt_check_identifiant->close();

                    $stmt_insert_item = $conn->prepare("INSERT INTO materiels_specifiques (type_materiel_id, identifiant_unique, etat, notes) VALUES (?, ?, ?, ?)");
                    if (!$stmt_insert_item) throw new Exception("Erreur de préparation (insert item): " . $conn->error);
                    $stmt_insert_item->bind_param("isss", $type_materiel_id_specific, $identifiant_unique, $etat_specific, $notes_specific);
                    if (!$stmt_insert_item->execute()) throw new Exception("Erreur d'insertion de l'item: " . $stmt_insert_item->error);
                    $stmt_insert_item->close();

                    if ($etat_specific === 'disponible') {
                        $stmt_update_qte_type = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible + 1 WHERE id = ?");
                        if (!$stmt_update_qte_type) throw new Exception("Erreur de préparation (update qte type add): " . $conn->error);
                        $stmt_update_qte_type->bind_param("i", $type_materiel_id_specific);
                        if (!$stmt_update_qte_type->execute()) throw new Exception("Erreur maj qte type (add): " . $stmt_update_qte_type->error);
                        $stmt_update_qte_type->close();
                    }
                    $_SESSION['feedback_message'] = ['type' => 'success', 'message' => 'Item spécifique ajouté avec succès.'];

                } elseif ($action_item === 'edit_item_specific') {
                    if (!$materiel_specifique_id) throw new Exception("ID de l'item spécifique manquant pour la modification.");

                    // Vérif identifiant unique (excluant l'item actuel)
                    $stmt_check_identifiant_edit = $conn->prepare("SELECT id FROM materiels_specifiques WHERE identifiant_unique = ? AND id != ?");
                    $stmt_check_identifiant_edit->bind_param("si", $identifiant_unique, $materiel_specifique_id);
                    $stmt_check_identifiant_edit->execute();
                    if ($stmt_check_identifiant_edit->get_result()->num_rows > 0) {
                        throw new Exception("Un autre item avec cet identifiant unique existe déjà.");
                    }
                    $stmt_check_identifiant_edit->close();

                    // Récupérer ancien état et ancien type_id
                    $stmt_get_old_item_data = $conn->prepare("SELECT type_materiel_id, etat FROM materiels_specifiques WHERE id = ?");
                    if (!$stmt_get_old_item_data) throw new Exception("Erreur prep (get old item data): " . $conn->error);
                    $stmt_get_old_item_data->bind_param("i", $materiel_specifique_id);
                    $stmt_get_old_item_data->execute();
                    $old_item_data_result = $stmt_get_old_item_data->get_result();
                    if (!($old_item_data = $old_item_data_result->fetch_assoc())) {
                        throw new Exception("Item spécifique non trouvé pour la modification.");
                    }
                    $old_type_id = $old_item_data['type_materiel_id'];
                    $old_etat = $old_item_data['etat'];
                    $stmt_get_old_item_data->close();

                    if ($old_etat === 'emprunté' && $type_materiel_id_specific != $old_type_id) {
                        throw new Exception("Impossible de changer le type d'un matériel actuellement emprunté.");
                    }
                    
                    $stmt_update_item = $conn->prepare("UPDATE materiels_specifiques SET type_materiel_id = ?, identifiant_unique = ?, etat = ?, notes = ? WHERE id = ?");
                    if (!$stmt_update_item) throw new Exception("Erreur de préparation (update item): " . $conn->error);
                    $stmt_update_item->bind_param("isssi", $type_materiel_id_specific, $identifiant_unique, $etat_specific, $notes_specific, $materiel_specifique_id);
                    if (!$stmt_update_item->execute()) throw new Exception("Erreur de modification de l'item: " . $stmt_update_item->error);
                    $stmt_update_item->close();

                    // Logique de mise à jour des stocks
                    if ($old_type_id != $type_materiel_id_specific) { // Changement de type
                        if ($old_etat === 'disponible') {
                            $stmt_dec_old_type = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible - 1 WHERE id = ? AND quantite_disponible > 0");
                            if (!$stmt_dec_old_type) throw new Exception("Erreur prep (dec old type): " . $conn->error);
                            $stmt_dec_old_type->bind_param("i", $old_type_id);
                            if (!$stmt_dec_old_type->execute()) throw new Exception("Erreur exec (dec old type): " . $stmt_dec_old_type->error);
                            $stmt_dec_old_type->close();
                        }
                        if ($etat_specific === 'disponible') {
                            $stmt_inc_new_type = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible + 1 WHERE id = ?");
                            if (!$stmt_inc_new_type) throw new Exception("Erreur prep (inc new type): " . $conn->error);
                            $stmt_inc_new_type->bind_param("i", $type_materiel_id_specific);
                            if (!$stmt_inc_new_type->execute()) throw new Exception("Erreur exec (inc new type): " . $stmt_inc_new_type->error);
                            $stmt_inc_new_type->close();
                        }
                    } else { // Même type, changement d'état possible
                        if ($old_etat === 'disponible' && $etat_specific !== 'disponible') {
                            $stmt_dec_type = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible - 1 WHERE id = ? AND quantite_disponible > 0");
                             if (!$stmt_dec_type) throw new Exception("Erreur prep (dec type): " . $conn->error);
                            $stmt_dec_type->bind_param("i", $type_materiel_id_specific);
                            if (!$stmt_dec_type->execute()) throw new Exception("Erreur exec (dec type): " . $stmt_dec_type->error);
                            $stmt_dec_type->close();
                        } elseif ($old_etat !== 'disponible' && $etat_specific === 'disponible') {
                            $stmt_inc_type = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible + 1 WHERE id = ?");
                            if (!$stmt_inc_type) throw new Exception("Erreur prep (inc type): " . $conn->error);
                            $stmt_inc_type->bind_param("i", $type_materiel_id_specific);
                            if (!$stmt_inc_type->execute()) throw new Exception("Erreur exec (inc type): " . $stmt_inc_type->error);
                            $stmt_inc_type->close();
                        }
                    }
                     $_SESSION['feedback_message'] = ['type' => 'success', 'message' => 'Item spécifique modifié avec succès.'];
                }
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => $e->getMessage()];
            }
        }
        header("Location: gestion_inventaire.php#materiels-specifiques-pane"); // Ancre pour l'onglet
        exit();
    }

    // Traitement pour Suppression Item Spécifique
    if (isset($_POST['action_delete_item_specific']) && $_POST['action_delete_item_specific'] === 'delete_item_specific_confirm') {
        $materiel_specifique_id_delete = isset($_POST['materiel_specifique_id_delete']) ? filter_var(trim($_POST['materiel_specifique_id_delete']), FILTER_VALIDATE_INT) : null;

        if (!$materiel_specifique_id_delete) {
            $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => 'ID de l\'item spécifique invalide pour la suppression.'];
        } else {
            $conn->begin_transaction();
            try {
                $stmt_get_item_info = $conn->prepare("SELECT type_materiel_id, etat FROM materiels_specifiques WHERE id = ?");
                if (!$stmt_get_item_info) throw new Exception("Erreur prep (get item info): " . $conn->error);
                $stmt_get_item_info->bind_param("i", $materiel_specifique_id_delete);
                $stmt_get_item_info->execute();
                $item_info_result = $stmt_get_item_info->get_result();
                if (!($item_info = $item_info_result->fetch_assoc())) {
                    throw new Exception("Item spécifique non trouvé pour la suppression.");
                }
                $item_type_id = $item_info['type_materiel_id'];
                $item_etat = $item_info['etat'];
                $stmt_get_item_info->close();

                if ($item_etat === 'emprunté') {
                    throw new Exception("Impossible de supprimer un item spécifique qui est actuellement emprunté.");
                }
                
                // Vérifier si l'item est lié à des détails de prêt (même non actifs)
                // Si le schema.sql a ON DELETE RESTRICT ou NO ACTION (par défaut) sur details_pret_materiel_specifique.materiel_specifique_id
                // cette vérification est importante. Si c'est ON DELETE CASCADE, cette vérification peut être omise.
                // Pour l'instant, on suppose que la suppression est permise si non 'emprunté'.

                $stmt_delete_item = $conn->prepare("DELETE FROM materiels_specifiques WHERE id = ?");
                if (!$stmt_delete_item) throw new Exception("Erreur prep (delete item): " . $conn->error);
                $stmt_delete_item->bind_param("i", $materiel_specifique_id_delete);
                if (!$stmt_delete_item->execute()) throw new Exception("Erreur de suppression de l'item: " . $stmt_delete_item->error);
                $stmt_delete_item->close();

                if ($item_etat === 'disponible') {
                    $stmt_update_qte_type_del = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible - 1 WHERE id = ? AND quantite_disponible > 0");
                    if (!$stmt_update_qte_type_del) throw new Exception("Erreur prep (update qte type del): " . $conn->error);
                    $stmt_update_qte_type_del->bind_param("i", $item_type_id);
                    if (!$stmt_update_qte_type_del->execute()) throw new Exception("Erreur maj qte type (del): " . $stmt_update_qte_type_del->error);
                    $stmt_update_qte_type_del->close();
                }
                $conn->commit();
                $_SESSION['feedback_message'] = ['type' => 'success', 'message' => 'Item spécifique supprimé avec succès.'];
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['feedback_message'] = ['type' => 'danger', 'message' => $e->getMessage()];
            }
        }
        header("Location: gestion_inventaire.php#materiels-specifiques-pane"); // Ancre pour l'onglet
        exit();
    }
}
// Fin du bloc de traitement POST -- La suite du script (HTML et récupération de données) s'exécute après
?>

<?php
// Logique de récupération des données
$liste_types_materiel = [];
$erreur_requete_types = "";

$liste_materiels_specifiques = [];
$erreur_requete_specifiques = "";

if ($conn) {
    // Récupération des types de matériel (déjà existant)
    $sql_types = "SELECT id, nom_type, description_type, quantite_totale, quantite_disponible FROM types_materiel ORDER BY nom_type ASC";
    $result_types = $conn->query($sql_types);
    if ($result_types) {
        while ($row = $result_types->fetch_assoc()) {
            $liste_types_materiel[] = $row;
        }
    } else {
        $erreur_requete_types = "Erreur lors de la récupération des types de matériel : " . $conn->error;
    }

    // Récupération des matériels spécifiques
    $sql_specifiques = "SELECT ms.id, ms.type_materiel_id, ms.identifiant_unique, ms.etat, ms.notes, tm.nom_type 
                        FROM materiels_specifiques ms 
                        JOIN types_materiel tm ON ms.type_materiel_id = tm.id 
                        ORDER BY tm.nom_type ASC, ms.identifiant_unique ASC";
    $result_specifiques = $conn->query($sql_specifiques);
    if ($result_specifiques) {
        while ($row = $result_specifiques->fetch_assoc()) {
            $liste_materiels_specifiques[] = $row;
        }
    } else {
        $erreur_requete_specifiques = "Erreur lors de la récupération des matériels spécifiques : " . $conn->error;
    }
}


?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion de l'Inventaire</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Optionnel: Ajout de Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
</head>
<body>
    <div class="container mt-4">
        <header class="mb-4">
            <h1 class="display-5 text-center">Gestion de l'Inventaire</h1>
        </header>

        <?php if ($feedback_message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($feedback_message['type']); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($feedback_message['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($erreur_connexion && !$conn): // Afficher seulement si la connexion a échoué et n'est pas établie ?>
            <div class="alert alert-danger" role="alert">
                <strong>Erreur de connexion à la base de données :</strong> <?php echo htmlspecialchars($erreur_connexion); ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3" id="inventoryTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="types-materiel-tab" data-bs-toggle="tab" data-bs-target="#types-materiel-pane" type="button" role="tab" aria-controls="types-materiel-pane" aria-selected="true">Gestion des Types de Matériel</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="materiels-specifiques-tab" data-bs-toggle="tab" data-bs-target="#materiels-specifiques-pane" type="button" role="tab" aria-controls="materiels-specifiques-pane" aria-selected="false">Gestion des Matériels Spécifiques</button>
            </li>
        </ul>

        <div class="tab-content" id="inventoryTabsContent">
            <!-- Section Gestion des Types de Matériel -->
            <div class="tab-pane fade show active" id="types-materiel-pane" role="tabpanel" aria-labelledby="types-materiel-tab" tabindex="0">
                <div class="card">
                    <div class="card-header">
                        <h3>Types de Matériel</h3>
                    </div>
                    <div class="card-body">
                        <button type="button" class="btn btn-primary mb-3" id="btnAjouterTypeMateriel" data-bs-toggle="modal" data-bs-target="#modalTypeMateriel">
                            <i class="bi bi-plus-circle"></i> Ajouter un nouveau type de matériel
                        </button>
                        
                        <?php if ($erreur_requete_types): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($erreur_requete_types); ?></div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Nom du Type</th>
                                        <th>Description</th>
                                        <th>Qté Totale</th>
                                        <th>Qté Disponible</th>
                                        <th style="width: 15%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($liste_types_materiel)): ?>
                                        <?php foreach ($liste_types_materiel as $type): ?>
                                            <tr>
                                                <td><?php echo $type['id']; ?></td>
                                                <td><?php echo htmlspecialchars($type['nom_type']); ?></td>
                                                <td><?php echo nl2br(htmlspecialchars($type['description_type'])); ?></td>
                                                <td><?php echo $type['quantite_totale']; ?></td>
                                                <td><?php echo $type['quantite_disponible']; ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-warning btnModifierType"
                                                            data-bs-toggle="modal" data-bs-target="#modalTypeMateriel"
                                                            data-id="<?php echo $type['id']; ?>"
                                                            data-nom="<?php echo htmlspecialchars($type['nom_type']); ?>"
                                                            data-description="<?php echo htmlspecialchars($type['description_type']); ?>"
                                                            data-qte-totale="<?php echo $type['quantite_totale']; ?>">
                                                        <i class="bi bi-pencil-square"></i> Modifier
                                                    </button>
                                                    <form method="POST" action="gestion_inventaire.php" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer ce type de matériel ? \n\nATTENTION : La suppression d\'un type de matériel n\'est possible que si aucun item spécifique n\'est actuellement associé à ce type. \nAssurez-vous d\'avoir supprimé ou réassigné tous les items spécifiques de ce type avant de continuer.');">
                                                        <input type="hidden" name="type_materiel_id_delete" value="<?php echo $type['id']; ?>">
                                                        <input type="hidden" name="action_delete_type" value="delete_type_confirm"> {/* Changé pour correspondre à la logique PHP */}
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-trash"></i> Supprimer
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php elseif ($conn && !$erreur_requete_types): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">Aucun type de matériel trouvé.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if (!$conn): ?>
                                         <tr>
                                            <td colspan="6" class="text-center text-danger">Connexion à la base de données non établie. Impossible de charger les données.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section Gestion des Matériels Spécifiques -->
            <div class="tab-pane fade" id="materiels-specifiques-pane" role="tabpanel" aria-labelledby="materiels-specifiques-tab" tabindex="0">
                <div class="card">
                    <div class="card-header">
                        <h3>Matériels Spécifiques (Items Individuels)</h3>
                    </div>
                    <div class="card-body">
                        <button type="button" class="btn btn-primary mb-3" id="btnAjouterMaterielSpecifique" data-bs-toggle="modal" data-bs-target="#modalMaterielSpecifique">
                            <i class="bi bi-plus-circle"></i> Ajouter un nouvel item spécifique
                        </button>

                        <?php if ($erreur_requete_specifiques): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($erreur_requete_specifiques); ?></div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Type de Matériel</th>
                                        <th>Identifiant Unique</th>
                                        <th>État</th>
                                        <th>Notes</th>
                                        <th style="width: 15%;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($liste_materiels_specifiques)): ?>
                                        <?php foreach ($liste_materiels_specifiques as $item): ?>
                                            <tr>
                                                <td><?php echo $item['id']; ?></td>
                                                <td><?php echo htmlspecialchars($item['nom_type']); ?></td>
                                                <td><?php echo htmlspecialchars($item['identifiant_unique']); ?></td>
                                                <td><?php echo htmlspecialchars($item['etat']); ?></td>
                                                <td><?php echo nl2br(htmlspecialchars($item['notes'])); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-warning btnModifierItemSpecific"
                                                            data-bs-toggle="modal" data-bs-target="#modalMaterielSpecifique"
                                                            data-id="<?php echo $item['id']; ?>"
                                                            data-type-id="<?php echo $item['type_materiel_id']; ?>"
                                                            data-identifiant="<?php echo htmlspecialchars($item['identifiant_unique']); ?>"
                                                            data-etat="<?php echo htmlspecialchars($item['etat']); ?>"
                                                            data-notes="<?php echo htmlspecialchars($item['notes']); ?>">
                                                        <i class="bi bi-pencil-square"></i> Modifier
                                                    </button>
                                                    <form method="POST" action="gestion_inventaire.php" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet item spécifique ? \nUn item emprunté ne peut pas être supprimé.');">
                                                        <input type="hidden" name="materiel_specifique_id_delete" value="<?php echo $item['id']; ?>">
                                                        <input type="hidden" name="action_delete_item_specific" value="delete_item_specific_confirm"> {/* Changé pour correspondre à la logique PHP */}
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="bi bi-trash"></i> Supprimer
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php elseif ($conn && !$erreur_requete_specifiques): ?>
                                        <tr>
                                            <td colspan="6" class="text-center">Aucun matériel spécifique trouvé.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php if (!$conn): ?>
                                         <tr>
                                            <td colspan="6" class="text-center text-danger">Connexion à la base de données non établie. Impossible de charger les données.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modale pour Ajout/Modification Type Matériel (existante) -->
    <div class="modal fade" id="modalTypeMateriel" tabindex="-1" aria-labelledby="modalTypeMaterielLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="gestion_inventaire.php" id="formTypeMateriel">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalTypeMaterielLabel">Ajouter/Modifier Type Matériel</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="type_materiel_id" id="type_materiel_id_modal">
                        <input type="hidden" name="action" id="action_type_modal" value="">

                        <div class="mb-3">
                            <label for="nom_type_modal" class="form-label">Nom du Type :</label>
                            <input type="text" class="form-control" id="nom_type_modal" name="nom_type" required>
                        </div>
                        <div class="mb-3">
                            <label for="description_type_modal" class="form-label">Description :</label>
                            <textarea class="form-control" id="description_type_modal" name="description_type" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="quantite_totale_modal" class="form-label">Quantité Totale :</label>
                            <input type="number" class="form-control" id="quantite_totale_modal" name="quantite_totale" min="0" required>
                            <small class="form-text text-muted">La quantité disponible sera initialement égale à la quantité totale pour un nouveau type.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Nouvelle Modale pour Ajout/Modification Matériel Spécifique -->
    <div class="modal fade" id="modalMaterielSpecifique" tabindex="-1" aria-labelledby="modalMaterielSpecifiqueLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="gestion_inventaire.php" id="formMaterielSpecifique">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalMaterielSpecifiqueLabel">Ajouter/Modifier Item Spécifique</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="materiel_specifique_id" id="materiel_specifique_id_modal">
                        <input type="hidden" name="action_item_specific" id="action_item_specific_modal" value=""> {/* Correspond à $_POST['action_item_specific'] */}

                        <div class="mb-3">
                            <label for="type_materiel_id_specific_modal" class="form-label">Type de Matériel :</label>
                            <select class="form-select" id="type_materiel_id_specific_modal" name="type_materiel_id_specific_modal" required>
                                <option value="">-- Choisissez un type --</option>
                                <?php if (!empty($liste_types_materiel)): ?>
                                    <?php foreach ($liste_types_materiel as $type): ?>
                                        <option value="<?php echo $type['id']; ?>"><?php echo htmlspecialchars($type['nom_type']); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="identifiant_unique_modal" class="form-label">Identifiant Unique :</label>
                            <input type="text" class="form-control" id="identifiant_unique_modal" name="identifiant_unique" required>
                        </div>
                        <div class="mb-3">
                            <label for="etat_specific_modal" class="form-label">État :</label>
                            <select class="form-select" id="etat_specific_modal" name="etat_specific_modal" required>
                                <option value="disponible">Disponible</option>
                                <option value="en maintenance">En Maintenance</option>
                                <option value="hors service">Hors Service</option>
                                <!-- 'emprunté' n'est pas gérable manuellement ici -->
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="notes_specific_modal" class="form-label">Notes :</label>
                            <textarea class="form-control" id="notes_specific_modal" name="notes_specific_modal" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // --- Gestion Modale Type Matériel (existante) ---
            const modalTypeMaterielEl = document.getElementById('modalTypeMateriel');
            if (modalTypeMaterielEl) { // Vérifier si l'élément existe avant de créer l'objet Modal
                const modalTypeMateriel = new bootstrap.Modal(modalTypeMaterielEl);
                const formTypeMateriel = document.getElementById('formTypeMateriel');
                const modalTypeMaterielLabel = document.getElementById('modalTypeMaterielLabel');
                
                const typeMaterielIdInput = document.getElementById('type_materiel_id_modal');
                const actionTypeInput = document.getElementById('action_type_modal'); // Renommé pour clarté
                const nomTypeInput = document.getElementById('nom_type_modal');
                const descriptionTypeInput = document.getElementById('description_type_modal');
                const quantiteTotaleInput = document.getElementById('quantite_totale_modal');

                const btnAjouterTypeMateriel = document.getElementById('btnAjouterTypeMateriel');
                if(btnAjouterTypeMateriel) {
                    btnAjouterTypeMateriel.addEventListener('click', function () {
                        formTypeMateriel.reset();
                        modalTypeMaterielLabel.textContent = 'Ajouter un type de matériel';
                        actionTypeInput.value = 'add_type';
                        typeMaterielIdInput.value = '';
                    });
                }

                document.querySelectorAll('.btnModifierType').forEach(button => {
                    button.addEventListener('click', function () {
                        modalTypeMaterielLabel.textContent = 'Modifier le type de matériel : ' + this.dataset.nom;
                        actionTypeInput.value = 'edit_type';
                        typeMaterielIdInput.value = this.dataset.id;
                        nomTypeInput.value = this.dataset.nom;
                        descriptionTypeInput.value = this.dataset.description;
                        quantiteTotaleInput.value = this.dataset.qteTotale;
                    });
                });
            }

            // --- Gestion Nouvelle Modale Matériel Spécifique ---
            const modalMaterielSpecifiqueEl = document.getElementById('modalMaterielSpecifique');
            if (modalMaterielSpecifiqueEl) {
                const modalMaterielSpecifique = new bootstrap.Modal(modalMaterielSpecifiqueEl);
                const formMaterielSpecifique = document.getElementById('formMaterielSpecifique');
                const modalMaterielSpecifiqueLabel = document.getElementById('modalMaterielSpecifiqueLabel');

                const materielSpecifiqueIdInput = document.getElementById('materiel_specifique_id_modal');
                const actionItemSpecificInput = document.getElementById('action_item_specific_modal');
                const typeMaterielIdSpecificSelect = document.getElementById('type_materiel_id_specific_modal');
                const identifiantUniqueInput = document.getElementById('identifiant_unique_modal');
                const etatSpecificSelect = document.getElementById('etat_specific_modal');
                const notesSpecificTextarea = document.getElementById('notes_specific_modal');

                const btnAjouterMaterielSpecifique = document.getElementById('btnAjouterMaterielSpecifique');
                if (btnAjouterMaterielSpecifique) {
                    btnAjouterMaterielSpecifique.addEventListener('click', function () {
                        formMaterielSpecifique.reset();
                        modalMaterielSpecifiqueLabel.textContent = 'Ajouter un item spécifique';
                        actionItemSpecificInput.value = 'add_item_specific';
                        materielSpecifiqueIdInput.value = '';
                    });
                }

                document.querySelectorAll('.btnModifierItemSpecific').forEach(button => {
                    button.addEventListener('click', function () {
                        modalMaterielSpecifiqueLabel.textContent = 'Modifier l\'item spécifique : ' + this.dataset.identifiant;
                        actionItemSpecificInput.value = 'edit_item_specific';
                        materielSpecifiqueIdInput.value = this.dataset.id;
                        typeMaterielIdSpecificSelect.value = this.dataset.typeId;
                        identifiantUniqueInput.value = this.dataset.identifiant;
                        etatSpecificSelect.value = this.dataset.etat;
                        notesSpecificTextarea.value = this.dataset.notes;
                    });
                });
            }
        });
    </script>
</body>
</html>
<?php
if ($conn) {
    $conn->close();
}
?>
