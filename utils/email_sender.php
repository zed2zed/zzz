<?php
// utils/email_sender.php

if (!defined('EMAIL_EXPEDITEUR_NO_REPLY')) {
    // Fallback au cas où config.php ne serait pas chargé ou la constante manquante
    // Cela ne devrait pas arriver en utilisation normale.
    define('EMAIL_EXPEDITEUR_NO_REPLY', 'noreply@example.com');
}

/**
 * Envoie un email.
 *
 * @param string $destinataire L'adresse email du destinataire.
 * @param string $sujet Le sujet de l'email.
 * @param string $message_html Le contenu HTML de l'email.
 * @return bool True si l'email a été accepté pour livraison, False sinon.
 */
function envoyer_email($destinataire, $sujet, $message_html) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: <' . EMAIL_EXPEDITEUR_NO_REPLY . '>' . "\r\n";
    // Potentiellement ajouter d'autres headers si nécessaire (Cc, Bcc, Reply-To)

    // Pour éviter les problèmes d'encodage du sujet avec certains clients mail
    $sujet_encode = mb_encode_mimeheader($sujet, 'UTF-8', 'B');

    if (mail($destinataire, $sujet_encode, $message_html, $headers)) {
        return true;
    } else {
        // Log l'erreur si possible, ou au moins retourner false
        // error_log("Erreur lors de l'envoi de l'email à $destinataire avec le sujet: $sujet");
        return false;
    }
}
?>
