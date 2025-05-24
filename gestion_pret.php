<?php
// gestion_pret.php
session_start(); // Pour stocker les messages de feedback

@include 'config.php';
require_once __DIR__ . '/utils/email_sender.php'; // Inclusion de l'utilitaire d'email

// --- Helper function for setting session message and redirecting ---
function set_session_message_and_redirect($message, $is_error = true, $redirect_url = 'admin_prets.php') {
    $_SESSION['feedback_message'] = [
        'message' => $message,
        'type' => $is_error ? 'error' : 'success'
    ];
    header('Location: ' . $redirect_url);
    exit;
}

// --- 1. Réception des données et validation de la méthode ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_session_message_and_redirect("Accès non autorisé.", true);
}

// --- 2. Validation des données ---
$pret_id = isset($_POST['pret_id']) ? filter_var(trim($_POST['pret_id']), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$allowed_actions = ['valider', 'annuler', 'retourner'];

// Validation de base
if (!$pret_id) {
    set_session_message_and_redirect("ID de prêt invalide ou manquant.", true);
}
if (empty($action) || !in_array($action, $allowed_actions)) {
    set_session_message_and_redirect("Action invalide ou manquante.", true);
}

// Champs spécifiques à l'action
$d_deb_effective_str = null;
$d_fin_effective_str = null;
$materiels_assignes = [];

if ($action === 'valider') {
    $d_deb_effective_str = isset($_POST['d_deb_effective']) ? trim($_POST['d_deb_effective']) : '';
    $d_fin_effective_str = isset($_POST['d_fin_effective']) ? trim($_POST['d_fin_effective']) : null; // Peut être vide
    $materiels_assignes = isset($_POST['materiels_assignes']) && is_array($_POST['materiels_assignes']) ? $_POST['materiels_assignes'] : [];

    if (empty($d_deb_effective_str)) {
        set_session_message_and_redirect("La date de début effective est requise pour la validation.", true);
    }
    // Valider format datetime-local YYYY-MM-DDTHH:MM
    $d_deb_effective_obj = DateTime::createFromFormat('Y-m-d\TH:i', $d_deb_effective_str);
    if (!$d_deb_effective_obj || $d_deb_effective_obj->format('Y-m-d\TH:i') !== $d_deb_effective_str) {
        set_session_message_and_redirect("Format de date de début effective invalide.", true);
    }

    if (!empty($d_fin_effective_str)) {
        $d_fin_effective_obj = DateTime::createFromFormat('Y-m-d\TH:i', $d_fin_effective_str);
        if (!$d_fin_effective_obj || $d_fin_effective_obj->format('Y-m-d\TH:i') !== $d_fin_effective_str) {
            set_session_message_and_redirect("Format de date de fin effective invalide.", true);
        }
        if ($d_fin_effective_obj <= $d_deb_effective_obj) {
            set_session_message_and_redirect("La date de fin effective doit être postérieure à la date de début effective.", true);
        }
    }
    if (empty($materiels_assignes)) {
        set_session_message_and_redirect("Aucun matériel spécifique n'a été sélectionné pour la validation.", true);
    }
    // Les autres validations (quantité, disponibilité, type) se feront dans la transaction
} elseif ($action === 'retourner') {
    $d_fin_effective_str = isset($_POST['d_fin_effective']) ? trim($_POST['d_fin_effective']) : '';
    if (empty($d_fin_effective_str)) {
        set_session_message_and_redirect("La date de fin effective est requise pour le retour.", true);
    }
    $d_fin_effective_obj = DateTime::createFromFormat('Y-m-d\TH:i', $d_fin_effective_str);
    if (!$d_fin_effective_obj || $d_fin_effective_obj->format('Y-m-d\TH:i') !== $d_fin_effective_str) {
        set_session_message_and_redirect("Format de date de fin effective invalide pour le retour.", true);
    }
}

// --- 3. Connexion à la base de données ---
if (!defined('DB_SERVER') || !defined('DB_USERNAME') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
    set_session_message_and_redirect("Erreur de configuration: Les informations de la base de données ne sont pas définies.", true);
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    set_session_message_and_redirect("Erreur de connexion à la base de données: " . $conn->connect_error, true);
}
$conn->set_charset("utf8");

// --- 4. Traitement des actions sur le prêt ---
$conn->begin_transaction();

try {
    // Récupérer les informations actuelles du prêt
    $stmt_check_pret = $conn->prepare("SELECT type_materiel_id, quantite_demandee, statut_validation FROM prets WHERE id = ?");
    if (!$stmt_check_pret) throw new Exception("Erreur de préparation (check pret): " . $conn->error);
    $stmt_check_pret->bind_param("i", $pret_id);
    $stmt_check_pret->execute();
    $result_pret = $stmt_check_pret->get_result();

    if ($result_pret->num_rows === 0) {
        throw new Exception("Le prêt ID " . htmlspecialchars($pret_id) . " n'existe pas.");
    }
    $pret_data = $result_pret->fetch_assoc();
    $current_status = $pret_data['statut_validation'];
    $type_materiel_id_pret = $pret_data['type_materiel_id'];
    $quantite_demandee_pret = $pret_data['quantite_demandee'];
    $stmt_check_pret->close();

    $message_succes = "";

    switch ($action) {
        //======================================================================
        // ACTION: VALIDER UN PRET
        //======================================================================
        case 'valider':
            if ($current_status !== 'en attente') {
                throw new Exception("Ce prêt n'est pas en attente de validation. Statut actuel: " . htmlspecialchars($current_status));
            }
            // Vérifier que le nombre d'items assignés correspond à la quantité demandée
            if (count($materiels_assignes) != $quantite_demandee_pret) {
                throw new Exception("Nombre de matériels assignés (" . count($materiels_assignes) . ") ne correspond pas à la quantité demandée (" . $quantite_demandee_pret . ").");
            }

            // Vérifier la validité et la disponibilité de chaque matériel spécifique assigné
            $materiels_valides_ids = [];
            foreach ($materiels_assignes as $ms_id) {
                $ms_id_int = filter_var($ms_id, FILTER_VALIDATE_INT);
                if (!$ms_id_int) throw new Exception("ID de matériel spécifique invalide : " . htmlspecialchars($ms_id));

                $stmt_check_ms = $conn->prepare("SELECT type_materiel_id, etat FROM materiels_specifiques WHERE id = ?");
                if (!$stmt_check_ms) throw new Exception("Erreur prep (check ms): " . $conn->error);
                $stmt_check_ms->bind_param("i", $ms_id_int);
                $stmt_check_ms->execute();
                $result_ms = $stmt_check_ms->get_result();
                if ($result_ms->num_rows === 0) {
                    throw new Exception("Matériel spécifique ID " . $ms_id_int . " non trouvé.");
                }
                $ms_data = $result_ms->fetch_assoc();
                if ($ms_data['type_materiel_id'] != $type_materiel_id_pret) {
                    throw new Exception("Matériel spécifique ID " . $ms_id_int . " n'est pas du bon type.");
                }
                if ($ms_data['etat'] !== 'disponible') {
                    throw new Exception("Matériel spécifique ID " . $ms_id_int . " n'est pas disponible (état: " . htmlspecialchars($ms_data['etat']) . ").");
                }
                $materiels_valides_ids[] = $ms_id_int; // Ajoute l'ID validé
                $stmt_check_ms->close();
            }
            
            // 1. Mettre à jour le statut et les dates effectives du prêt
            $sql_update_pret = "UPDATE prets SET statut_validation = 'validé', d_deb_effective = ?, d_fin_effective = ? WHERE id = ?";
            $stmt_valider_pret = $conn->prepare($sql_update_pret);
            if (!$stmt_valider_pret) throw new Exception("Erreur prep (valider pret): " . $conn->error);
            $stmt_valider_pret->bind_param("ssi", $d_deb_effective_str, $d_fin_effective_str, $pret_id);
            if (!$stmt_valider_pret->execute()) throw new Exception("Erreur exec (valider pret): " . $stmt_valider_pret->error);
            $stmt_valider_pret->close();

            // 2. Lier les matériels spécifiques au prêt et mettre à jour leur état
            $stmt_insert_detail = $conn->prepare("INSERT INTO details_pret_materiel_specifique (pret_id, materiel_specifique_id) VALUES (?, ?)");
            if (!$stmt_insert_detail) throw new Exception("Erreur prep (insert detail): " . $conn->error);
            $stmt_update_ms_etat = $conn->prepare("UPDATE materiels_specifiques SET etat = 'emprunté' WHERE id = ?");
            if (!$stmt_update_ms_etat) throw new Exception("Erreur prep (update ms etat): " . $conn->error);

            foreach ($materiels_valides_ids as $ms_id_valid) {
                $stmt_insert_detail->bind_param("ii", $pret_id, $ms_id_valid);
                if (!$stmt_insert_detail->execute()) throw new Exception("Erreur exec (insert detail pour ID ". $ms_id_valid ."): " . $stmt_insert_detail->error);
                
                $stmt_update_ms_etat->bind_param("i", $ms_id_valid);
                if (!$stmt_update_ms_etat->execute()) throw new Exception("Erreur exec (update ms etat pour ID ". $ms_id_valid ."): " . $stmt_update_ms_etat->error);
            }
            $stmt_insert_detail->close();
            $stmt_update_ms_etat->close();
            
            // 3. Mettre à jour la quantité disponible pour le type de matériel concerné
            $stmt_update_type_qte = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible - ? WHERE id = ? AND quantite_disponible >= ?");
            if (!$stmt_update_type_qte) throw new Exception("Erreur prep (update type qte): " . $conn->error);
            $stmt_update_type_qte->bind_param("iii", $quantite_demandee_pret, $type_materiel_id_pret, $quantite_demandee_pret);
            if (!$stmt_update_type_qte->execute()) throw new Exception("Erreur exec (update type qte): " . $stmt_update_type_qte->error);
            // Vérifier si la mise à jour a bien eu lieu (si le stock était suffisant)
            if ($stmt_update_type_qte->affected_rows === 0) {
                throw new Exception("Impossible de décrémenter la quantité disponible pour le type de matériel. Vérifiez le stock ou l'ID du type.");
            }
            $stmt_update_type_qte->close();
            
            $message_succes = "Le prêt ID " . htmlspecialchars($pret_id) . " a été validé avec succès.";

            // Après le commit, envoyer l'email de notification au demandeur
            // Récupérer l'email du demandeur et les détails du prêt pour l'email
            $stmt_info_email = $conn->prepare(
                "SELECT u.email AS email_demandeur, tm.nom_type, p.quantite_demandee, p.d_deb_effective, p.d_fin_effective
                 FROM prets p
                 JOIN utilisateurs u ON p.utilisateur_id = u.id
                 JOIN types_materiel tm ON p.type_materiel_id = tm.id
                 WHERE p.id = ?"
            );
            if (!$stmt_info_email) throw new Exception("Erreur prep (info email): " . $conn->error);
            $stmt_info_email->bind_param("i", $pret_id);
            $stmt_info_email->execute();
            $result_info_email = $stmt_info_email->get_result();
            $info_email_data = $result_info_email->fetch_assoc();
            $stmt_info_email->close();

            if ($info_email_data) {
                $email_demandeur = $info_email_data['email_demandeur'];
                $nom_type_email = htmlspecialchars($info_email_data['nom_type']);
                $quantite_email = intval($info_email_data['quantite_demandee']);
                $d_deb_effective_email = htmlspecialchars(date('d/m/Y H:i', strtotime($info_email_data['d_deb_effective'])));
                $d_fin_effective_email_str = $info_email_data['d_fin_effective'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($info_email_data['d_fin_effective']))) : 'Non spécifiée (confirmée au retour)';

                // Récupérer les identifiants uniques des matériels assignés
                $stmt_items_assignes = $conn->prepare(
                    "SELECT ms.identifiant_unique 
                     FROM details_pret_materiel_specifique dpms
                     JOIN materiels_specifiques ms ON dpms.materiel_specifique_id = ms.id
                     WHERE dpms.pret_id = ?"
                );
                if (!$stmt_items_assignes) throw new Exception("Erreur prep (items assignes email): " . $conn->error);
                $stmt_items_assignes->bind_param("i", $pret_id);
                $stmt_items_assignes->execute();
                $result_items_assignes = $stmt_items_assignes->get_result();
                $liste_items_html = "<ul>";
                if ($result_items_assignes->num_rows > 0) {
                    while($item_row = $result_items_assignes->fetch_assoc()) {
                        $liste_items_html .= "<li>" . htmlspecialchars($item_row['identifiant_unique']) . "</li>";
                    }
                } else {
                    $liste_items_html .= "<li>Information non disponible.</li>";
                }
                $liste_items_html .= "</ul>";
                $stmt_items_assignes->close();

                $sujet_demandeur = "Votre demande de prêt de matériel a été validée (Prêt #$pret_id)";
                $message_html_demandeur = <<<HTML
                <!DOCTYPE html><html><head><meta charset="UTF-8"><title>$sujet_demandeur</title></head><body>
                <h2>Confirmation de votre demande de prêt</h2>
                <p>Bonjour,</p>
                <p>Bonne nouvelle ! Votre demande de prêt de matériel (#$pret_id) a été validée.</p>
                <h3>Détails du prêt :</h3>
                <ul>
                    <li><strong>Type de matériel :</strong> $nom_type_email</li>
                    <li><strong>Quantité :</strong> $quantite_email</li>
                    <li><strong>Date de début effective :</strong> $d_deb_effective_email</li>
                    <li><strong>Date de fin prévue/effective :</strong> $d_fin_effective_email_str</li>
                </ul>
                <h4>Matériel(s) spécifique(s) attribué(s) :</h4>
                $liste_items_html
                <p>Merci de vous présenter pour récupérer le matériel aux dates convenues.</p>
                <p>Cordialement,<br>Le Service Informatique</p>
                </body></html>
HTML;
                if (defined('EMAIL_EXPEDITEUR_NO_REPLY')) { // Vérifier si la constante est définie (devrait l'être via config)
                    envoyer_email($email_demandeur, $sujet_demandeur, $message_html_demandeur);
                    // Optionnel: logguer l'échec de l'envoi, mais ne pas bloquer le flux principal
                } else {
                    // Optionnel: logguer que EMAIL_EXPEDITEUR_NO_REPLY n'est pas défini
                }
            } else {
                // Optionnel: logguer l'impossibilité de récupérer les infos pour l'email
            }
            break;

        //======================================================================
        // ACTION: ANNULER UN PRET (avant validation)
        //======================================================================
        case 'annuler':
            if ($current_status !== 'en attente') {
                throw new Exception("Ce prêt n'est pas en attente de validation. Statut actuel: " . htmlspecialchars($current_status));
            }
            $stmt_annuler_pret = $conn->prepare("UPDATE prets SET statut_validation = 'annulé' WHERE id = ?");
            if (!$stmt_annuler_pret) throw new Exception("Erreur prep (annuler pret): " . $conn->error);
            $stmt_annuler_pret->bind_param("i", $pret_id);
            if (!$stmt_annuler_pret->execute()) throw new Exception("Erreur exec (annuler pret): " . $stmt_annuler_pret->error);
            $stmt_annuler_pret->close();
            // Aucune modification de stock n'est nécessaire car le matériel n'avait pas encore été décrémenté de `quantite_disponible`.
            $message_succes = "Le prêt ID " . htmlspecialchars($pret_id) . " a été annulé.";
            break;

        //======================================================================
        // ACTION: MARQUER UN PRET COMME RETOURNE
        //======================================================================
        case 'retourner':
            if ($current_status !== 'validé') {
                throw new Exception("Ce prêt n'est pas validé. Statut actuel: " . htmlspecialchars($current_status));
            }

            // 1. Mettre à jour le statut et la date de retour effective du prêt
            $stmt_retourner_pret = $conn->prepare("UPDATE prets SET statut_validation = 'retourné', d_fin_effective = ? WHERE id = ?");
            if (!$stmt_retourner_pret) throw new Exception("Erreur prep (retourner pret): " . $conn->error);
            $stmt_retourner_pret->bind_param("si", $d_fin_effective_str, $pret_id);
            if (!$stmt_retourner_pret->execute()) throw new Exception("Erreur exec (retourner pret): " . $stmt_retourner_pret->error);
            $stmt_retourner_pret->close();

            // 2. Récupérer les IDs des matériels spécifiques qui étaient liés à ce prêt
            $stmt_get_details = $conn->prepare("SELECT materiel_specifique_id FROM details_pret_materiel_specifique WHERE pret_id = ?");
            if (!$stmt_get_details) throw new Exception("Erreur prep (get details): " . $conn->error);
            $stmt_get_details->bind_param("i", $pret_id);
            $stmt_get_details->execute();
            $result_details = $stmt_get_details->get_result();
            $materiels_retournes_ids = [];
            while($row = $result_details->fetch_assoc()) {
                $materiels_retournes_ids[] = $row['materiel_specifique_id'];
            }
            $stmt_get_details->close();

            if (!empty($materiels_retournes_ids)) {
                // 3. Mettre à jour l'état de ces matériels spécifiques à 'disponible'
                $stmt_update_ms_etat_retour = $conn->prepare("UPDATE materiels_specifiques SET etat = 'disponible' WHERE id = ?");
                if (!$stmt_update_ms_etat_retour) throw new Exception("Erreur prep (update ms etat retour): " . $conn->error);
                foreach ($materiels_retournes_ids as $ms_id_retour) {
                    $stmt_update_ms_etat_retour->bind_param("i", $ms_id_retour);
                    if (!$stmt_update_ms_etat_retour->execute()) throw new Exception("Erreur exec (update ms etat retour pour ID ". $ms_id_retour ."): " . $stmt_update_ms_etat_retour->error);
                }
                $stmt_update_ms_etat_retour->close();

                // 4. Mettre à jour la quantité disponible pour le type de matériel
                // On utilise la quantité initialement demandée pour le prêt pour l'incrémentation.
                // Cela suppose que tous les items assignés pour la quantité demandée sont retournés.
                $stmt_update_type_qte_retour = $conn->prepare("UPDATE types_materiel SET quantite_disponible = quantite_disponible + ? WHERE id = ?");
                if (!$stmt_update_type_qte_retour) throw new Exception("Erreur prep (update type qte retour): " . $conn->error);
                $stmt_update_type_qte_retour->bind_param("ii", $quantite_demandee_pret, $type_materiel_id_pret);
                if (!$stmt_update_type_qte_retour->execute()) throw new Exception("Erreur exec (update type qte retour): " . $stmt_update_type_qte_retour->error);
                $stmt_update_type_qte_retour->close();
            }
            
            $message_succes = "Le prêt ID " . htmlspecialchars($pret_id) . " a été marqué comme retourné.";
            break;

        default: // Normalement, ne devrait pas être atteint à cause de la validation en amont
            throw new Exception("Action non reconnue.");
    }

    $conn->commit();
    set_session_message_and_redirect($message_succes, false);

} catch (Exception $e) {
    $conn->rollback();
    set_session_message_and_redirect("Erreur lors du traitement de la demande : " . $e->getMessage(), true);
} finally {
    if ($conn) {
        $conn->close();
    }
}

?>
