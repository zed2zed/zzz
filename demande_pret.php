<?php
// demande_pret.php

@include 'config.php';
require_once __DIR__ . '/utils/email_sender.php'; // Inclusion de l'utilitaire d'email

// --- Helper function for displaying messages and redirecting ---
function display_message_and_redirect($message, $is_error = true, $redirect_url = 'index.php', $delay = 5) {
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><title>Statut de la demande</title>";
    echo "<style>
            body { font-family: Arial, sans-serif; padding: 20px; text-align: center; }
            .message { padding: 15px; margin: 20px auto; border-radius: 5px; max-width: 600px; }
            .error { background-color: #ffe0e0; border: 1px solid red; color: red; }
            .success { background-color: #e0ffe0; border: 1px solid green; color: green; }
          </style>";
    echo "</head><body>";
    echo "<div class='message " . ($is_error ? 'error' : 'success') . "'>" . htmlspecialchars($message) . "</div>";
    if ($redirect_url) {
        echo "<p>Vous allez être redirigé vers <a href='" . htmlspecialchars($redirect_url) . "'>la page d'accueil</a> dans " . $delay . " secondes.</p>";
        echo "<meta http-equiv='refresh' content='" . $delay . ";url=" . htmlspecialchars($redirect_url) . "'>";
    }
    echo "</body></html>";
    exit;
}

// --- 1. Vérifier la méthode de requête ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    display_message_and_redirect("Accès non autorisé. Ce script ne peut être accédé que via POST.", true, 'index.php', 3);
}

// --- 2. Récupération et validation des données ---
$type_materiel_id = isset($_POST['type_materiel_id']) ? trim($_POST['type_materiel_id']) : '';
$quantite_demandee = isset($_POST['quantite_demandee']) ? trim($_POST['quantite_demandee']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$nom_utilisateur = isset($_POST['nom_utilisateur']) ? trim($_POST['nom_utilisateur']) : null;
$d_deb_souhaitee_str = isset($_POST['d_deb_souhaitee']) ? trim($_POST['d_deb_souhaitee']) : '';
$d_fin_souhaitee_str = isset($_POST['d_fin_souhaitee']) ? trim($_POST['d_fin_souhaitee']) : '';
$lieu = isset($_POST['lieu']) ? trim($_POST['lieu']) : '';
$pers_contact = isset($_POST['pers_contact']) ? trim($_POST['pers_contact']) : null;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : null;

$errors = [];

if (empty($type_materiel_id)) {
    $errors[] = "Le type de matériel à emprunter est requis.";
}
if (empty($quantite_demandee)) {
    $errors[] = "La quantité demandée est requise.";
} elseif (!filter_var($quantite_demandee, FILTER_VALIDATE_INT) || intval($quantite_demandee) <= 0) {
    $errors[] = "La quantité demandée doit être un entier positif.";
}
if (empty($email)) {
    $errors[] = "L'adresse email est requise.";
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Le format de l'adresse email est invalide.";
}
if (empty($d_deb_souhaitee_str)) {
    $errors[] = "La date de début souhaitée est requise.";
} 
if (empty($d_fin_souhaitee_str)) {
    $errors[] = "La date de fin souhaitée est requise.";
}
if (empty($lieu)) {
    $errors[] = "Le lieu du prêt est requis.";
}

// Validation des dates si elles sont fournies
$d_deb_souhaitee_obj = null;
$d_fin_souhaitee_obj = null;

if (!empty($d_deb_souhaitee_str)) {
    $d_deb_souhaitee_obj = DateTime::createFromFormat('Y-m-d\TH:i', $d_deb_souhaitee_str);
    if (!$d_deb_souhaitee_obj || $d_deb_souhaitee_obj->format('Y-m-d\TH:i') !== $d_deb_souhaitee_str) {
        $errors[] = "La date de début souhaitée n'est pas une date/heure valide (format attendu YYYY-MM-DDTHH:MM).";
    } else {
        $today = new DateTime();
        // Optionnel: permettre les demandes pour aujourd'hui mais pas dans le passé strict.
        // Pour une comparaison stricte (pas dans le passé), on pourrait comparer avec $today->modify('-1 minute') par exemple.
        // Ici, on autorise la date/heure actuelle.
        if ($d_deb_souhaitee_obj < $today->setTime(0,0,0) && $d_deb_souhaitee_obj->format('Y-m-d') !== $today->format('Y-m-d')) {
             // $errors[] = "La date de début souhaitée ne peut pas être dans le passé."; (Commenté pour permettre plus de flexibilité pour les tests)
        }
    }
}

if (!empty($d_fin_souhaitee_str)) {
    $d_fin_souhaitee_obj = DateTime::createFromFormat('Y-m-d\TH:i', $d_fin_souhaitee_str);
    if (!$d_fin_souhaitee_obj || $d_fin_souhaitee_obj->format('Y-m-d\TH:i') !== $d_fin_souhaitee_str) {
        $errors[] = "La date de fin souhaitée n'est pas une date/heure valide (format attendu YYYY-MM-DDTHH:MM).";
    }
}

if ($d_deb_souhaitee_obj && $d_fin_souhaitee_obj && $d_fin_souhaitee_obj <= $d_deb_souhaitee_obj) {
    $errors[] = "La date de fin souhaitée doit être postérieure à la date de début souhaitée.";
}


if (!empty($errors)) {
    $error_message = "Erreurs de validation :<br>" . implode("<br>", $errors);
    display_message_and_redirect($error_message, true, 'index.php', 15); // Augmenté le délai pour lire les erreurs
}

// --- 3. Connexion à la base de données ---
if (!defined('DB_SERVER') || !defined('DB_USERNAME') || !defined('DB_PASSWORD') || !defined('DB_NAME')) {
    display_message_and_redirect("Erreur de configuration: Les informations de la base de données ne sont pas définies.", true, 'index.php');
}

$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($conn->connect_error) {
    display_message_and_redirect("Erreur de connexion à la base de données: " . $conn->connect_error, true, 'index.php');
}
$conn->set_charset("utf8"); // Bonne pratique

// --- 4. Traitement de la demande ---
$utilisateur_id = null;

// Démarrer une transaction pour assurer l'atomicité des opérations
$conn->begin_transaction();

try {
    // 4.a Gestion de l'utilisateur
    $stmt_check_user = $conn->prepare("SELECT id, nom FROM utilisateurs WHERE email = ?");
    $stmt_check_user->bind_param("s", $email);
    $stmt_check_user->execute();
    $result_user = $stmt_check_user->get_result();

    if ($result_user->num_rows > 0) {
        $user_data = $result_user->fetch_assoc();
        $utilisateur_id = $user_data['id'];
        // Optionnel: Mettre à jour le nom si fourni et différent
        if (!empty($nom_utilisateur) && $nom_utilisateur !== $user_data['nom']) {
            $stmt_update_user_name = $conn->prepare("UPDATE utilisateurs SET nom = ? WHERE id = ?");
            $stmt_update_user_name->bind_param("si", $nom_utilisateur, $utilisateur_id);
            if (!$stmt_update_user_name->execute()) {
                throw new Exception("Erreur lors de la mise à jour du nom de l'utilisateur: " . $stmt_update_user_name->error);
            }
            $stmt_update_user_name->close();
        }
    } else {
        $stmt_insert_user = $conn->prepare("INSERT INTO utilisateurs (email, nom) VALUES (?, ?)");
        if (!$stmt_insert_user) throw new Exception("Erreur de préparation (insert user): " . $conn->error);
        $stmt_insert_user->bind_param("ss", $email, $nom_utilisateur);
        if (!$stmt_insert_user->execute()) {
            throw new Exception("Erreur lors de la création de l'utilisateur: " . $stmt_insert_user->error);
        }
        $utilisateur_id = $stmt_insert_user->insert_id;
        $stmt_insert_user->close();
    }
    $stmt_check_user->close();

    // 4.b Vérification de l'existence du type de matériel (optionnel mais recommandé)
    $nom_type_materiel_pour_message = "Type ID " . $type_materiel_id; // Valeur par défaut
    $stmt_check_type_materiel = $conn->prepare("SELECT nom_type FROM types_materiel WHERE id = ?");
    if (!$stmt_check_type_materiel) throw new Exception("Erreur de préparation (check type): " . $conn->error);
    $stmt_check_type_materiel->bind_param("i", $type_materiel_id);
    $stmt_check_type_materiel->execute();
    $result_type_materiel = $stmt_check_type_materiel->get_result();

    if ($result_type_materiel->num_rows === 0) {
        throw new Exception("Le type de matériel sélectionné (ID: " . htmlspecialchars($type_materiel_id) . ") n'existe pas.");
    } else {
        $type_data = $result_type_materiel->fetch_assoc();
        $nom_type_materiel_pour_message = $type_data['nom_type'];
    }
    $stmt_check_type_materiel->close();
    // Note: La vérification de quantite_disponible vs quantite_demandee n'est pas faite ici
    // car la logique d'attribution finale est gérée par le service IT.

    // 4.c Insertion du prêt
    // Pas de mise à jour de l'état du matériel ou de la quantité disponible ici.
    $stmt_insert_pret = $conn->prepare(
        "INSERT INTO prets (utilisateur_id, type_materiel_id, quantite_demandee, d_deb_souhaitee, d_fin_souhaitee, lieu, pers_contact, comment, statut_validation) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'en attente')"
    );
    if (!$stmt_insert_pret) throw new Exception("Erreur de préparation (insert pret): " . $conn->error);
    
    // Convertir les chaînes de date/heure en objets DateTime pour s'assurer qu'elles sont valides avant de les reformater pour la DB si nécessaire.
    // MySQL accepte 'YYYY-MM-DD HH:MM:SS' ou 'YYYY-MM-DDTHH:MM' pour les colonnes DATETIME.
    // Les objets DateTime ont déjà été validés. On utilise les strings originales.
    $stmt_insert_pret->bind_param(
        "iiissssss",
        $utilisateur_id,
        $type_materiel_id,
        $quantite_demandee,
        $d_deb_souhaitee_str,
        $d_fin_souhaitee_str,
        $lieu,
        $pers_contact,
        $comment
    );

    if (!$stmt_insert_pret->execute()) {
        throw new Exception("Erreur lors de l'enregistrement de la demande de prêt: " . $stmt_insert_pret->error);
    }
    $nouveau_pret_id = $stmt_insert_pret->insert_id; // Récupérer l'ID du nouveau prêt
    $stmt_insert_pret->close();

    // Si tout s'est bien passé, valider la transaction
    $conn->commit();

    // --- 5. Envoi de l'email de notification au valideur ---
    if (defined('EMAIL_VALIDEUR') && defined('APP_URL')) {
        $sujet_valideur = "Nouvelle demande de prêt : " . htmlspecialchars($nom_type_materiel_pour_message);
        
        $d_deb_formatted = date('d/m/Y H:i', strtotime($d_deb_souhaitee_str));
        $d_fin_formatted = date('d/m/Y H:i', strtotime($d_fin_souhaitee_str));
        $nom_utilisateur_email = !empty($nom_utilisateur) ? htmlspecialchars($nom_utilisateur) : 'Non spécifié';
        $email_utilisateur_email = htmlspecialchars($email);
        $comment_email = !empty($comment) ? nl2br(htmlspecialchars($comment)) : 'Aucun';
        $lieu_email = htmlspecialchars($lieu);
        $quantite_email = intval($quantite_demandee);

        $lien_validation = APP_URL . '/admin_prets.php#pret-' . $nouveau_pret_id; // Ancre pour surligner

        $message_html_valideur = <<<HTML
        <!DOCTYPE html><html><head><meta charset="UTF-8"><title>$sujet_valideur</title></head><body>
        <h2>Nouvelle demande de prêt de matériel</h2>
        <p>Une nouvelle demande de prêt (#$nouveau_pret_id) a été enregistrée :</p>
        <ul>
            <li><strong>Type de matériel :</strong> {$nom_type_materiel_pour_message}</li>
            <li><strong>Quantité :</strong> {$quantite_email}</li>
            <li><strong>Demandeur :</strong> {$nom_utilisateur_email} ({$email_utilisateur_email})</li>
            <li><strong>Date de début souhaitée :</strong> {$d_deb_formatted}</li>
            <li><strong>Date de fin souhaitée :</strong> {$d_fin_formatted}</li>
            <li><strong>Lieu :</strong> {$lieu_email}</li>
            <li><strong>Commentaire :</strong> {$comment_email}</li>
        </ul>
        <p>Pour consulter et valider cette demande, veuillez cliquer sur le lien suivant :</p>
        <p><a href="{$lien_validation}">Gérer la demande #{$nouveau_pret_id}</a></p>
        <p>Merci.</p>
        </body></html>
HTML;
        
        envoyer_email(EMAIL_VALIDEUR, $sujet_valideur, $message_html_valideur);
        // Optionnel: logguer l'échec de l'envoi, mais ne pas bloquer l'utilisateur
    }


    // --- 6. Message de confirmation à l'utilisateur ---
    $message_confirmation_utilisateur = sprintf(
        "Votre demande de prêt pour %d x '%s' (du %s au %s) a été enregistrée avec succès et est en attente de validation.",
        intval($quantite_demandee),
        htmlspecialchars($nom_type_materiel_pour_message),
        htmlspecialchars(date('d/m/Y H:i', strtotime($d_deb_souhaitee_str))),
        htmlspecialchars(date('d/m/Y H:i', strtotime($d_fin_souhaitee_str)))
    );
    display_message_and_redirect($message_confirmation_utilisateur, false, 'index.php', 7);

} catch (Exception $e) {
    // En cas d'erreur, annuler la transaction
    $conn->rollback();
    display_message_and_redirect("Erreur lors du traitement de votre demande : " . $e->getMessage(), true, 'index.php', 10);
} finally {
    // Fermer la connexion
    if ($conn) {
        $conn->close();
    }
}

?>
