<?php
session_start();
require_once __DIR__ . '/../config.php';

$pdo = null;
$erreur_connexion_pdo = "";

if (defined('DB_SERVER') && defined('DB_USERNAME') && defined('DB_PASSWORD') && defined('DB_NAME')) {
    $dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
    } catch (PDOException $e) {
        $erreur_connexion_pdo = "Erreur de connexion PDO : " . $e->getMessage();
        // Sur une page publique, on ne voudrait pas afficher $e->getMessage() directement.
        // Pour un outil admin, c'est acceptable pour le débogage initial.
    }
} else {
    $erreur_connexion_pdo = "Les constantes de configuration de la base de données ne sont pas définies.";
}

// --- 1. Gestion de la plage de dates ---
$today = new DateTime();
$start_date_str = isset($_GET['start_date']) ? $_GET['start_date'] : $today->format('Y-m-d');

try {
    $current_start_date = new DateTime($start_date_str);
} catch (Exception $e) {
    // En cas de date invalide dans l'URL, revenir à aujourd'hui
    $current_start_date = new DateTime();
    $start_date_str = $current_start_date->format('Y-m-d');
}

$current_end_date = (clone $current_start_date)->modify('+6 days');

$prev_week_start_date = (clone $current_start_date)->modify('-7 days')->format('Y-m-d');
$next_week_start_date = (clone $current_start_date)->modify('+7 days')->format('Y-m-d');

// Formatage pour l'affichage du titre de la semaine
$formatter_jour_mois_annee = new IntlDateFormatter('fr_FR', IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'dd MMMM yyyy');
$start_date_display = $formatter_jour_mois_annee->format($current_start_date);
$end_date_display = $formatter_jour_mois_annee->format($current_end_date);


// --- 3. Récupération des données des prêts (et 4. Préparation) ---
$events_by_date = [];
$erreur_requete_prets = "";

if ($pdo) {
    $sql_prets_semaine = "SELECT 
                            p.id AS pret_id,
                            p.d_deb_effective,
                            p.d_fin_effective,
                            p.d_fin_souhaitee,
                            p.statut_validation,
                            u.nom AS utilisateur_nom,
                            tm.nom_type AS type_materiel_nom
                        FROM prets p
                        JOIN utilisateurs u ON p.utilisateur_id = u.id
                        JOIN types_materiel tm ON p.type_materiel_id = tm.id
                        WHERE 
                            (p.statut_validation = 'validé' OR p.statut_validation = 'retourné') AND
                            (
                                (p.d_deb_effective BETWEEN :start_date_sql AND :end_date_sql) OR
                                (p.d_fin_effective BETWEEN :start_date_sql AND :end_date_sql) OR
                                (p.d_deb_effective <= :start_date_sql AND (p.d_fin_effective IS NULL OR p.d_fin_effective >= :start_date_sql)) OR
                                (p.d_deb_effective <= :start_date_sql AND p.d_fin_effective IS NULL AND p.d_fin_souhaitee >= :start_date_sql AND p.statut_validation = 'validé')
                            )
                        ORDER BY p.d_deb_effective ASC";
    
    try {
        $stmt_prets_semaine = $pdo->prepare($sql_prets_semaine);
        $start_date_sql_param = $current_start_date->format('Y-m-d 00:00:00');
        $end_date_sql_param = $current_end_date->format('Y-m-d 23:59:59');
        $stmt_prets_semaine->bindParam(':start_date_sql', $start_date_sql_param);
        $stmt_prets_semaine->bindParam(':end_date_sql', $end_date_sql_param);
        $stmt_prets_semaine->execute();
        $prets_semaine = $stmt_prets_semaine->fetchAll();

        // Initialiser $events_by_date pour tous les jours de la semaine
        for ($i = 0; $i <= 6; $i++) {
            $day_key = (clone $current_start_date)->modify("+$i days")->format('Y-m-d');
            $events_by_date[$day_key] = [];
        }
        
        foreach ($prets_semaine as $pret) {
            $desc_base = htmlspecialchars($pret['type_materiel_nom']) . " par " . htmlspecialchars($pret['utilisateur_nom'] ?? 'N/A');

            // Check pour début de prêt
            if ($pret['d_deb_effective']) {
                $date_deb_obj = new DateTime($pret['d_deb_effective']);
                if ($date_deb_obj >= $current_start_date && $date_deb_obj <= $current_end_date) {
                    $day_key_deb = $date_deb_obj->format('Y-m-d');
                    $events_by_date[$day_key_deb][] = [
                        'type' => 'debut', 
                        'desc' => "Début: " . $desc_base, 
                        'pret_id' => $pret['pret_id']
                    ];
                }
            }

            // Check pour fin de prêt
            $date_fin_pertinente_str = $pret['d_fin_effective'] ?? ($pret['statut_validation'] === 'validé' ? $pret['d_fin_souhaitee'] : null);
            if ($date_fin_pertinente_str) {
                $date_fin_obj = new DateTime($date_fin_pertinente_str);
                 if ($date_fin_obj >= $current_start_date && $date_fin_obj <= $current_end_date) {
                    $day_key_fin = $date_fin_obj->format('Y-m-d');
                    $status_fin_desc = ($pret['d_fin_effective'] ? "Fin réelle" : "Fin souhaitée");
                    $events_by_date[$day_key_fin][] = [
                        'type' => 'fin', 
                        'desc' => $status_fin_desc . ": " . $desc_base,
                        'pret_id' => $pret['pret_id']
                    ];
                }
            }
        }

    } catch (PDOException $e) {
        $erreur_requete_prets = "Erreur lors de la récupération des prêts : " . $e->getMessage();
    }
}


?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendrier des Prêts - Gestion de Matériel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <!-- Plus tard, CSS pour FullCalendar sera ajouté ici -->
</head>
<body>
    <div class="container mt-4">
        <header class="mb-4 d-flex justify-content-between align-items-center">
            <h1>Calendrier des Prêts</h1>
            <a href="../admin_prets.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left-circle"></i> Retour à la gestion des prêts
            </a>
        </header>

        <?php if ($erreur_connexion_pdo): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Erreur de connexion à la base de données :</strong> <?php echo htmlspecialchars($erreur_connexion_pdo); ?>
                <p>Veuillez vérifier votre fichier <code>config.php</code> et que votre serveur MySQL est accessible.</p>
            </div>
        <?php elseif ($erreur_requete_prets): ?>
             <div class="alert alert-danger" role="alert">
                <strong>Erreur lors de la récupération des données :</strong> <?php echo htmlspecialchars($erreur_requete_prets); ?>
            </div>
        <?php endif; ?>

        <!-- 2. Boutons de Navigation -->
        <div class="row mb-3 text-center">
            <div class="col">
                <a href="?start_date=<?php echo $prev_week_start_date; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left"></i> Semaine précédente
                </a>
            </div>
            <div class="col-md-6">
                <h4 class="mb-0">Semaine du <?php echo $start_date_display; ?> au <?php echo $end_date_display; ?></h4>
            </div>
            <div class="col text-end">
                 <a href="?start_date=<?php echo $next_week_start_date; ?>" class="btn btn-outline-primary">
                    Semaine suivante <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>

        <!-- 5. Affichage du calendrier vertical -->
        <div class="row">
            <?php 
            $formatter_jour_complet = new IntlDateFormatter('fr_FR', IntlDateFormatter::FULL, IntlDateFormatter::NONE, null, null, 'EEEE dd MMMM yyyy');
            for ($i = 0; $i <= 6; $i++): 
                $jour_courant = (clone $current_start_date)->modify("+$i days");
                $jour_key = $jour_courant->format('Y-m-d');
            ?>
            <div class="col-12 mb-3">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><?php echo ucfirst($formatter_jour_complet->format($jour_courant)); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($events_by_date[$jour_key])): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($events_by_date[$jour_key] as $event): ?>
                                    <?php
                                        $item_class = 'list-group-item-light'; // Default
                                        $badge_class = 'bg-secondary';
                                        if ($event['type'] === 'debut') {
                                            $item_class_modifier = 'list-group-item-success opacity-75'; // Vert pour début
                                            $badge_class = 'bg-success';
                                        } elseif ($event['type'] === 'fin') {
                                            $item_class_modifier = 'list-group-item-danger opacity-75'; // Rouge pour fin
                                            $badge_class = 'bg-danger';
                                        } else {
                                            $item_class_modifier = 'list-group-item-light';
                                        }
                                    ?>
                                    <a href="../admin_prets.php?highlight_pret_id=<?php echo htmlspecialchars($event['pret_id']); ?>#pret-<?php echo htmlspecialchars($event['pret_id']); ?>" 
                                       class="list-group-item list-group-item-action <?php echo $item_class_modifier; ?>">
                                        <span class="badge <?php echo $badge_class; ?> me-2"><?php echo ucfirst($event['type']); ?></span>
                                        <?php echo htmlspecialchars($event['desc']); ?> (Prêt #<?php echo htmlspecialchars($event['pret_id']); ?>)
                                    </a>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">Aucun mouvement de prêt prévu pour ce jour.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Plus tard, JS pour FullCalendar et son initialisation seront ajoutés ici -->
</body>
</html>
<?php
// Fermer la connexion PDO n'est pas explicitement nécessaire avec PDO si le script se termine, 
// mais c'est une bonne pratique de le faire si le script continue après.
// $pdo = null; 
?>
