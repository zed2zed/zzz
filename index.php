<?php
// index.php

// Inclusion du fichier de configuration
@include 'config.php'; // Utilisation de @ pour supprimer l'avertissement si le fichier n'est pas encore configuré

// Variables pour la connexion et les données
$conn = null;
$types_materiel_disponibles = []; // Changé de $materiels_disponibles
$erreur_connexion = "";
$erreur_requete_types = ""; // Changé de $erreur_requete_materiel

// Vérifier si les constantes de configuration sont définies
if (defined('DB_SERVER') && defined('DB_USERNAME') && defined('DB_PASSWORD') && defined('DB_NAME')) {
    // Tentative de connexion à la base de données
    try {
        $conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);
        if ($conn->connect_error) {
            $erreur_connexion = "Erreur de connexion à la base de données : " . $conn->connect_error;
        }
    } catch (Exception $e) {
        $erreur_connexion = "Erreur de connexion à la base de données : " . $e->getMessage();
    }

    if ($conn && !$erreur_connexion) {
        // Récupération des types de matériel disponibles
        $sql = "SELECT id, nom_type, description_type, quantite_disponible FROM types_materiel WHERE quantite_disponible > 0 ORDER BY nom_type ASC";
        $result = $conn->query($sql);

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $types_materiel_disponibles[] = $row;
            }
        } else {
            $erreur_requete_types = "Erreur lors de la récupération des types de matériel : " . $conn->error;
        }
    }
} else {
    $erreur_connexion = "Le fichier de configuration (config.php) n'est pas correctement configuré ou n'est pas inclus. Veuillez définir les constantes DB_SERVER, DB_USERNAME, DB_PASSWORD, et DB_NAME.";
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application de Prêt de Matériel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tempusdominus-bootstrap-5/6.7.7/css/tempus-dominus.min.css" integrity="sha512-PkcViZfH1DPELCqL6c7+jVccGSkH5W2XJC4uDq2BWRKDDgVjK3lQ7X87K3VwF3mC2fcmTwPV2_Ju5vB9f3G13A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Suppression du bloc <style> existant -->
</head>
<body>
    <div class="container mt-4">
        <header class="mb-4">
            <h1 class="display-4 text-center">Système de Prêt de Matériel</h1>
        </header>

        <?php if ($erreur_connexion): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Erreur de configuration :</strong> <?php echo htmlspecialchars($erreur_connexion); ?>
            </div>
        <?php endif; ?>

        <?php if (!$erreur_connexion && defined('DB_SERVER')): // Ne pas afficher le reste si la config n'est pas chargée ?>
            <div class="row">
                <div class="col-md-12">
                    <section id="types-materiel-disponible" class="mb-5">
                        <h2 class="mb-3">Types de Matériel Disponibles</h2>
                        <?php if ($erreur_requete_types): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars($erreur_requete_types); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($types_materiel_disponibles) && !$erreur_requete_types): ?>
                            <div class="alert alert-info" role="alert">
                                Aucun type de matériel n'est actuellement disponible avec du stock.
                            </div>
                        <?php else: ?>
                            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4">
                                <?php foreach ($types_materiel_disponibles as $type_materiel): ?>
                                    <div class="col">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h5 class="card-title"><?php echo htmlspecialchars($type_materiel['nom_type']); ?></h5>
                                                <p class="card-text"><?php echo htmlspecialchars($type_materiel['description_type']); ?></p>
                                            </div>
                                            <div class="card-footer">
                                                <small class="text-muted">Stock disponible : <?php echo htmlspecialchars($type_materiel['quantite_disponible']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>

            <hr class="my-5">

            <div class="row justify-content-center">
                <div class="col-md-8">
                    <section id="demande-pret">
                        <h2 class="mb-3">Faire une Demande de Prêt</h2>
                        <?php if (empty($types_materiel_disponibles) && !$erreur_requete_types): ?>
                             <div class="alert alert-warning" role="alert">
                                 Le formulaire de demande est désactivé car aucun type de matériel n'est disponible.
                             </div>
                        <?php elseif ($erreur_requete_types): ?>
                             <div class="alert alert-warning" role="alert">
                                 Le formulaire de demande est désactivé en raison d'une erreur de chargement des types de matériel.
                             </div>
                        <?php else: ?>
                            <form action="demande_pret.php" method="POST" class="needs-validation" novalidate>
                                <div class="mb-3">
                                    <label for="type_materiel_id" class="form-label">Type de matériel à emprunter :</label>
                                    <select class="form-select" name="type_materiel_id" id="type_materiel_id" required>
                                        <option value="" disabled selected>-- Choisissez un type de matériel --</option>
                                        <?php foreach ($types_materiel_disponibles as $type_materiel): ?>
                                            <option value="<?php echo htmlspecialchars($type_materiel['id']); ?>">
                                                <?php echo htmlspecialchars($type_materiel['nom_type']); ?> (Disponible: <?php echo htmlspecialchars($type_materiel['quantite_disponible']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback">Veuillez choisir un type de matériel.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="quantite_demandee" class="form-label">Quantité demandée :</label>
                                    <input type="number" class="form-control" name="quantite_demandee" id="quantite_demandee" min="1" value="1" required>
                                    <div class="invalid-feedback">Veuillez entrer une quantité valide (minimum 1).</div>
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="form-label">Votre Email :</label>
                                    <input type="email" class="form-control" name="email" id="email" required>
                                    <div class="invalid-feedback">Veuillez entrer une adresse email valide.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="nom_utilisateur" class="form-label">Votre Nom (optionnel) :</label>
                                    <input type="text" class="form-control" name="nom_utilisateur" id="nom_utilisateur">
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="d_deb_souhaitee_dp" class="form-label">Date et heure de début souhaitées :</label>
                                        <input type="text" class="form-control datetimepicker-input" name="d_deb_souhaitee" id="d_deb_souhaitee_dp" data-td-target="#d_deb_souhaitee_dp" required>
                                        <div class="invalid-feedback">Veuillez choisir une date et heure de début.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="d_fin_souhaitee_dp" class="form-label">Date et heure de fin souhaitées :</label>
                                        <input type="text" class="form-control datetimepicker-input" name="d_fin_souhaitee" id="d_fin_souhaitee_dp" data-td-target="#d_fin_souhaitee_dp" required>
                                        <div class="invalid-feedback">Veuillez choisir une date et heure de fin.</div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="lieu" class="form-label">Lieu du prêt (ex: Salle de réunion B, Bureau 101) :</label>
                                    <input type="text" class="form-control" name="lieu" id="lieu" required>
                                    <div class="invalid-feedback">Veuillez préciser le lieu du prêt.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="pers_contact" class="form-label">Personne de contact (optionnel) :</label>
                                    <input type="text" class="form-control" name="pers_contact" id="pers_contact">
                                </div>

                                <div class="mb-3">
                                    <label for="comment" class="form-label">Commentaire (optionnel) :</label>
                                    <textarea class="form-control" name="comment" id="comment" rows="3"></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary w-100">Envoyer la Demande</button>
                            </form>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        <?php elseif (!defined('DB_SERVER')): ?>
             <div class="alert alert-warning" role="alert">
                 Veuillez configurer le fichier <code>config.php</code> pour continuer.
             </div>
        <?php endif; ?>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tempusdominus-bootstrap-5/6.7.7/js/tempus-dominus.min.js" integrity="sha512-N130O1P/SXTAzA2djtyJoQyP7HwLwrRjPbm5N5_a2+tS/2xLd/wGjI3eH3uG8q3g9LajquB4HwN2hU3wQyqfQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialisation pour d_deb_souhaitee
            new tempusDominus.TempusDominus(document.getElementById('d_deb_souhaitee_dp'), {
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
            });

            // Initialisation pour d_fin_souhaitee
            new tempusDominus.TempusDominus(document.getElementById('d_fin_souhaitee_dp'), {
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
            });

            // Bootstrap form validation
            var forms = document.querySelectorAll('.needs-validation');
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    form.classList.add('was-validated');
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
