<?php
// admin_prets.php
session_start(); // Démarrer la session pour accéder aux messages de feedback

// Inclusion du fichier de configuration
@include 'config.php'; // Utilisation de @ pour supprimer l'avertissement si le fichier n'est pas encore configuré

// Variables pour la connexion et les données
$conn = null;
$prets_data = []; // Renommé pour clarté, contiendra les prêts et leurs détails
$erreur_connexion = "";
$erreur_requete_prets = "";
$message_info = "";

// Récupérer l'ID du prêt à surligner depuis l'URL, s'il existe
$highlight_pret_id = null;
if (isset($_GET['highlight_pret_id']) && !empty($_GET['highlight_pret_id'])) {
    $highlight_pret_id_temp = filter_var($_GET['highlight_pret_id'], FILTER_VALIDATE_INT);
    if ($highlight_pret_id_temp && $highlight_pret_id_temp > 0) {
        $highlight_pret_id = $highlight_pret_id_temp;
    }
}

// Fonction pour récupérer les matériels spécifiques disponibles pour un type de matériel
function get_materiels_specifiques_disponibles($db_conn, $type_materiel_id) {
    $materiels = [];
    $sql_materiels = "SELECT id, identifiant_unique FROM materiels_specifiques WHERE type_materiel_id = ? AND etat = 'disponible'";
    $stmt_materiels = $db_conn->prepare($sql_materiels);
    if ($stmt_materiels) {
        $stmt_materiels->bind_param("i", $type_materiel_id);
        $stmt_materiels->execute();
        $result_materiels = $stmt_materiels->get_result();
        while ($row = $result_materiels->fetch_assoc()) {
            $materiels[] = $row;
        }
        $stmt_materiels->close();
    }
    return $materiels;
}

// Fonction pour récupérer les matériels spécifiques assignés à un prêt
function get_materiels_assignes_a_pret($db_conn, $pret_id) {
    $materiels_assignes = [];
    $sql_assignes = "SELECT ms.identifiant_unique 
                     FROM details_pret_materiel_specifique dpms
                     JOIN materiels_specifiques ms ON dpms.materiel_specifique_id = ms.id
                     WHERE dpms.pret_id = ?";
    $stmt_assignes = $db_conn->prepare($sql_assignes);
    if ($stmt_assignes) {
        $stmt_assignes->bind_param("i", $pret_id);
        $stmt_assignes->execute();
        $result_assignes = $stmt_assignes->get_result();
        while ($row = $result_assignes->fetch_assoc()) {
            $materiels_assignes[] = $row['identifiant_unique'];
        }
        $stmt_assignes->close();
    }
    return $materiels_assignes;
}


// Vérifier si les constantes de configuration sont définies
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

    if ($conn && !$erreur_connexion) {
        $sql = "SELECT 
                    p.id AS pret_id,
                    p.utilisateur_id,
                    p.type_materiel_id,
                    p.quantite_demandee,
                    p.d_deb_souhaitee,
                    p.d_fin_souhaitee,
                    p.d_deb_effective,
                    p.d_fin_effective,
                    p.lieu,
                    p.pers_contact,
                    p.comment,
                    p.statut_validation,
                    p.date_demande,
                    tm.nom_type AS type_materiel_nom,
                    u.email AS utilisateur_email,
                    u.nom AS utilisateur_nom
                FROM prets p
                JOIN types_materiel tm ON p.type_materiel_id = tm.id
                JOIN utilisateurs u ON p.utilisateur_id = u.id
                ORDER BY p.statut_validation = 'en attente' DESC, p.date_demande DESC";
        
        $result_prets = $conn->query($sql);

        if ($result_prets) {
            if ($result_prets->num_rows > 0) {
                while ($pret = $result_prets->fetch_assoc()) {
                    // Pour chaque prêt, récupérer les détails nécessaires
                    if ($pret['statut_validation'] === 'en attente') {
                        $pret['materiels_specifiques_disponibles'] = get_materiels_specifiques_disponibles($conn, $pret['type_materiel_id']);
                    }
                    if ($pret['statut_validation'] === 'validé' || $pret['statut_validation'] === 'retourné') {
                        $pret['materiels_assignes'] = get_materiels_assignes_a_pret($conn, $pret['pret_id']);
                    }
                    $prets_data[] = $pret;
                }
            } else {
                $message_info = "Aucune demande de prêt n'a été trouvée.";
            }
        } else {
            $erreur_requete_prets = "Erreur lors de la récupération des demandes de prêt : " . $conn->error;
        }
    }
} else {
    $erreur_connexion = "Le fichier de configuration (config.php) n'est pas correctement configuré.";
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration des Prêts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tempusdominus-bootstrap-5/6.7.7/css/tempus-dominus.min.css" integrity="sha512-PkcViZfH1DPELCqL6c7+jVccGSkH5W2XJC4uDq2BWRKDDgVjK3lQ7X87K3VwF3mC2fcmTwPV2_Ju5vB9f3G13A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Suppression du bloc <style> existant -->
</head>
<body>
    <script>
        // Modifié pour intégration avec Bootstrap
        function validateCheckboxCount(form, expectedCount, feedbackElementId) {
            const checkboxes = form.querySelectorAll('input[name="materiels_assignes[]"]:checked');
            const actualCount = checkboxes.length;
            const feedbackElement = document.getElementById(feedbackElementId);
            // Cibler le conteneur des checkboxes pour ajouter/retirer la classe is-invalid.
            // On suppose que le div avec la classe 'border rounded p-2' est ce conteneur.
            const checkboxContainer = feedbackElement.previousElementSibling; // Ajustez si la structure change

            if (actualCount !== parseInt(expectedCount)) {
                if (feedbackElement) {
                    feedbackElement.textContent = `Veuillez sélectionner exactement ${expectedCount} matériel(s). Vous en avez sélectionné ${actualCount}.`;
                    feedbackElement.style.display = 'block'; // Bootstrap le cache par défaut
                }
                if (checkboxContainer) {
                    checkboxContainer.classList.add('is-invalid');
                }
                return false;
            } else {
                if (feedbackElement) {
                    feedbackElement.textContent = '';
                    feedbackElement.style.display = 'none';
                }
                if (checkboxContainer) {
                    checkboxContainer.classList.remove('is-invalid');
                }
                return true;
            }
        }
    </script>
    <div class="container mt-4">
        <h1 class="mb-4 display-5 text-center">Gestion des Demandes de Prêt</h1>

        <?php
        // Affichage des messages de feedback de la session
        if (isset($_SESSION['feedback_message']) && is_array($_SESSION['feedback_message'])) {
            $feedback = $_SESSION['feedback_message'];
            $alert_type = $feedback['type'] === 'success' ? 'alert-success' : 'alert-danger';
            echo '<div class="alert ' . $alert_type . ' alert-dismissible fade show" role="alert">' . 
                 htmlspecialchars($feedback['message']) . 
                 '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
            unset($_SESSION['feedback_message']); // Effacer le message après affichage
        }
        ?>

        <?php if ($erreur_connexion): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Erreur de configuration :</strong> <?php echo htmlspecialchars($erreur_connexion); ?>
            </div>
        <?php endif; ?>

        <?php if (!$erreur_connexion && defined('DB_SERVER')): ?>
            <?php if ($erreur_requete_prets): ?>
                <div class="alert alert-danger" role="alert">
                    <?php echo htmlspecialchars($erreur_requete_prets); ?>
                </div>
            <?php endif; ?>

            <?php if ($message_info): ?>
                 <div class="alert alert-info" role="alert">
                    <?php echo htmlspecialchars($message_info); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($prets_data)): ?>
                <?php foreach ($prets_data as $pret): ?>
                    <?php
                        $status_class = '';
                        $status_text_class = 'text-dark'; // Default text color
                        switch ($pret['statut_validation']) {
                            case 'en attente': $status_class = 'bg-warning'; $status_text_class = 'text-dark'; break;
                            case 'validé': $status_class = 'bg-success'; $status_text_class = 'text-white'; break;
                            case 'partiellement validé': $status_class = 'bg-info'; $status_text_class = 'text-dark'; break;
                            case 'retourné': $status_class = 'bg-secondary'; $status_text_class = 'text-white'; break;
                            case 'annulé': $status_class = 'bg-danger'; $status_text_class = 'text-white'; break;
                            default: $status_class = 'bg-light';
                        }

                        $card_extra_class = '';
                        if ($highlight_pret_id && $pret['pret_id'] == $highlight_pret_id) {
                            $card_extra_class = 'border border-primary border-3 shadow'; 
                        }
                    ?>
                    <div class="card mb-4 <?php echo $card_extra_class; ?>" id="pret-<?php echo htmlspecialchars($pret['pret_id']); ?>">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Demande de prêt #<?php echo htmlspecialchars($pret['pret_id']); ?></h5>
                            <span class="badge <?php echo $status_class; ?> <?php echo $status_text_class; ?> fs-6">
                                <?php echo htmlspecialchars(ucfirst($pret['statut_validation'])); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <dl class="row mb-0">
                                        <dt class="col-sm-5">Type Matériel:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars($pret['type_materiel_nom']); ?></dd>
                                        <dt class="col-sm-5">Quantité Demandée:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars($pret['quantite_demandee']); ?></dd>
                                        <dt class="col-sm-5">Utilisateur:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars($pret['utilisateur_email']); ?> (<?php echo htmlspecialchars($pret['utilisateur_nom'] ?? 'N/A'); ?>)</dd>
                                        <dt class="col-sm-5">Date Demande:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($pret['date_demande']))); ?></dd>
                                        <dt class="col-sm-5">Début Souhaité:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($pret['d_deb_souhaitee']))); ?></dd>
                                        <dt class="col-sm-5">Fin Souhaitée:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($pret['d_fin_souhaitee']))); ?></dd>
                                    </dl>
                                </div>
                                <div class="col-md-6">
                                    <dl class="row mb-0">
                                        <dt class="col-sm-5">Lieu:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars($pret['lieu']); ?></dd>
                                        <dt class="col-sm-5">Personne Contact:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars($pret['pers_contact'] ?? 'N/A'); ?></dd>
                                        <dt class="col-sm-5">Commentaire Client:</dt> <dd class="col-sm-7"><?php echo nl2br(htmlspecialchars($pret['comment'] ?? 'N/A')); ?></dd>
                                        <?php if ($pret['d_deb_effective']): ?>
                                            <dt class="col-sm-5">Début Effectif:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($pret['d_deb_effective']))); ?></dd>
                                        <?php endif; ?>
                                        <?php if ($pret['d_fin_effective']): ?>
                                            <dt class="col-sm-5">Fin Effective:</dt> <dd class="col-sm-7"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($pret['d_fin_effective']))); ?></dd>
                                        <?php endif; ?>
                                    </dl>
                                </div>
                            </div>

                            <?php if (!empty($pret['materiels_assignes'])): ?>
                                <hr class="my-3">
                                <h6 class="card-subtitle mb-2 text-muted">Matériels Assignés:</h6>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($pret['materiels_assignes'] as $identifiant_unique): ?>
                                        <li class="list-group-item py-1"><?php echo htmlspecialchars($identifiant_unique); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div class="card-footer bg-light">
                            <?php if ($pret['statut_validation'] == 'en attente'): ?>
                                <form action="gestion_pret.php" method="POST" class="p-3 border rounded bg-white needs-validation" 
                                      data-quantite-demandee="<?php echo htmlspecialchars($pret['quantite_demandee']); ?>"
                                      data-feedback-id="materiels_assignes_feedback_<?php echo $pret['pret_id']; ?>"
                                      novalidate>
                                    <h5 class="mb-3">Valider cette demande</h5>
                                    <input type="hidden" name="pret_id" value="<?php echo htmlspecialchars($pret['pret_id']); ?>">
                                    <input type="hidden" name="action" value="valider">

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="d_deb_effective_<?php echo $pret['pret_id']; ?>" class="form-label">Date de début effective :</label>
                                            <input type="text" class="form-control form-control-sm datetimepicker-input" name="d_deb_effective" id="d_deb_effective_<?php echo $pret['pret_id']; ?>" 
                                                   data-td-target="#d_deb_effective_<?php echo $pret['pret_id']; ?>"
                                                   value="<?php echo htmlspecialchars(date('d/m/Y H:i', $pret['d_deb_souhaitee'] ? strtotime($pret['d_deb_souhaitee']) : time())); ?>" required>
                                            <div class="invalid-feedback">Veuillez sélectionner une date de début effective.</div>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="d_fin_effective_<?php echo $pret['pret_id']; ?>" class="form-label">Date de fin effective (retour) :</label>
                                            <input type="text" class="form-control form-control-sm datetimepicker-input" name="d_fin_effective" id="d_fin_effective_<?php echo $pret['pret_id']; ?>"
                                                   data-td-target="#d_fin_effective_<?php echo $pret['pret_id']; ?>"
                                                   value="<?php echo htmlspecialchars($pret['d_fin_souhaitee'] ? date('d/m/Y H:i', strtotime($pret['d_fin_souhaitee'])) : ''); ?>">
                                            <!-- Pas de 'required' ici, mais on pourrait ajouter un invalid-feedback si on voulait valider le format si fourni -->
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Matériels spécifiques disponibles (Type: <?php echo htmlspecialchars($pret['type_materiel_nom']); ?>) - Cochez <?php echo htmlspecialchars($pret['quantite_demandee']); ?> :</label>
                                        <?php if (!empty($pret['materiels_specifiques_disponibles'])): ?>
                                            <div class="border rounded p-2 checkbox-list-container" style="max-height: 150px; overflow-y: auto;">
                                                <?php foreach ($pret['materiels_specifiques_disponibles'] as $materiel_specifique): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="materiels_assignes[]" value="<?php echo htmlspecialchars($materiel_specifique['id']); ?>" id="ms_<?php echo $pret['pret_id'] . '_' . $materiel_specifique['id']; ?>">
                                                        <label class="form-check-label" for="ms_<?php echo $pret['pret_id'] . '_' . $materiel_specifique['id']; ?>">
                                                            <?php echo htmlspecialchars($materiel_specifique['identifiant_unique']); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="invalid-feedback" id="materiels_assignes_feedback_<?php echo $pret['pret_id']; ?>"></div>
                                        <?php else: ?>
                                            <div class="alert alert-sm alert-warning">Aucun matériel spécifique de ce type n'est actuellement disponible.</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-success" <?php if (empty($pret['materiels_specifiques_disponibles']) && $pret['quantite_demandee'] > 0) echo 'disabled'; ?>>
                                        <i class="bi bi-check-circle"></i> Valider le Prêt
                                    </button>
                                     <button type="submit" name="action_annuler" value="annuler" formnovalidate class="btn btn-danger ms-2"> <!-- Changed name to avoid conflict, added formnovalidate -->
                                        <i class="bi bi-x-circle"></i> Annuler la Demande
                                    </button>
                                </form>
                                <!-- La logique d'annulation est maintenant gérée par le bouton ci-dessus. Si on voulait une soumission séparée, il faudrait un autre formulaire. -->

                            <?php elseif ($pret['statut_validation'] == 'validé'): ?>
                                <form action="gestion_pret.php" method="POST" class="p-3 border rounded bg-white needs-validation" novalidate>
                                     <h5 class="mb-3">Marquer comme retourné</h5>
                                    <input type="hidden" name="pret_id" value="<?php echo htmlspecialchars($pret['pret_id']); ?>">
                                    <input type="hidden" name="action" value="retourner">
                                     <div class="mb-3">
                                        <label for="d_fin_effective_retour_<?php echo $pret['pret_id']; ?>" class="form-label">Date de fin effective (retour) :</label>
                                        <input type="text" class="form-control form-control-sm datetimepicker-input" name="d_fin_effective" id="d_fin_effective_retour_<?php echo $pret['pret_id']; ?>" 
                                               data-td-target="#d_fin_effective_retour_<?php echo $pret['pret_id']; ?>"
                                               value="<?php echo htmlspecialchars(date('d/m/Y H:i', time())); ?>" required>
                                        <div class="invalid-feedback">Veuillez sélectionner une date de fin effective.</div>
                                    </div>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="bi bi-arrow-return-left"></i> Marquer Retourné
                                    </button>
                                </form>
                            <?php elseif ($pret['statut_validation'] == 'retourné' || $pret['statut_validation'] == 'annulé'): ?>
                                <p class="text-muted mb-0"><em>Aucune action supplémentaire pour ce prêt.</em></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php elseif (!$message_info && !$erreur_requete_prets): ?>
                 <div class="alert alert-light" role="alert">Chargement des données des prêts...</div>
            <?php endif; ?>
        <?php elseif (!defined('DB_SERVER')): ?>
             <div class="alert alert-warning" role="alert">
                Veuillez configurer le fichier <code>config.php</code> pour que cette page fonctionne.
             </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tempusdominus-bootstrap-5/6.7.7/js/tempus-dominus.min.js" integrity="sha512-N130O1P/SXTAzA2djtyJoQyP7HwLwrRjPbm5N5_a2+tS/2xLd/wGjI3eH3uG8q3g9LajquB4HwN2hU3wQyqfQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-scroll to highlighted loan
            const highlightId = <?php echo json_encode($highlight_pret_id ?? 0); ?>;
            if (highlightId) {
                const elementToHighlight = document.getElementById('pret-' + highlightId);
                if (elementToHighlight) {
                    elementToHighlight.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    // Optional: Remove highlight after a delay
                    // setTimeout(() => {
                    //    elementToHighlight.classList.remove('border', 'border-primary', 'border-3', 'shadow');
                    // }, 5000); 
                }
            }

            const datePickerOptions = { // Common options for all pickers
                display: {
                    components: {
                        calendar: true, date: true, month: true, year: true, decades: true,
                        clock: true, hours: true, minutes: true, seconds: false
                    },
                    theme: 'light'
                },
                localization: {
                    format: 'dd/MM/yyyy HH:mm'
                },
                useCurrent: false
            };

            // Initialisation pour les champs de date dans les formulaires de validation
            document.querySelectorAll('input[name="d_deb_effective"].datetimepicker-input').forEach(function(element) {
                new tempusDominus.TempusDominus(element, datePickerOptions);
            });
            document.querySelectorAll('input[name="d_fin_effective"].datetimepicker-input').forEach(function(element) {
                new tempusDominus.TempusDominus(element, datePickerOptions);
            });
            document.querySelectorAll('input[name="d_fin_effective"][id^="d_fin_effective_retour_"].datetimepicker-input').forEach(function(element) {
                new tempusDominus.TempusDominus(element, datePickerOptions);
            });

            // Bootstrap form validation
            var forms = document.querySelectorAll('.needs-validation');
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    let checkboxValidationPassed = true;
                    // Check if this is the validation form
                    if (form.querySelector('input[name="action"][value="valider"]')) {
                        const quantiteDemandee = form.dataset.quantiteDemandee;
                        const feedbackId = form.dataset.feedbackId;
                        if (quantiteDemandee && feedbackId) { // Only run if data attributes are set
                           checkboxValidationPassed = validateCheckboxCount(form, quantiteDemandee, feedbackId);
                        }
                    }

                    if (!form.checkValidity() || !checkboxValidationPassed) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    
                    form.classList.add('was-validated');
                    
                    // If checkbox validation failed, ensure its container also shows 'is-invalid'
                    // (validateCheckboxCount already does this, but this is a fallback visual cue)
                    if (!checkboxValidationPassed && form.querySelector('input[name="action"][value="valider"]')) {
                        const feedbackId = form.dataset.feedbackId;
                        const feedbackElement = document.getElementById(feedbackId);
                        if (feedbackElement && feedbackElement.previousElementSibling) {
                             feedbackElement.previousElementSibling.classList.add('is-invalid');
                        }
                    }

                }, false);
            });
        });
    </script>
</body>
</html>
<?php
// Fermer la connexion si elle a été établie
if ($conn && method_exists($conn, 'close')) {
    $conn->close();
}
?>
