<?php
/**
 * DRENA4 - Extrait de gestion des paquets
 * Technologies : PHP / PDO / MySQL
 *
 * Exemple simplifié du traitement d'enregistrement.
 * Ce fichier est un extrait pédagogique non autonome.
 */

// Récupération des données du formulaire
$numero = trim($_POST['numero_paquet'] ?? '');
$nbCartes = (int) ($_POST['nombre_cartes'] ?? 0);
$dateEnvoi = $_POST['date_envoi'] ?? '';
$idEtablissement = (int) ($_POST['id_etablissement'] ?? 0);
$observations = trim($_POST['observations'] ?? '');

// Validation des champs obligatoires
if (
    $numero === '' ||
    $nbCartes <= 0 ||
    $dateEnvoi === '' ||
    $idEtablissement <= 0
) {
    throw new InvalidArgumentException(
        "Veuillez remplir tous les champs obligatoires."
    );
}

try {
    // Démarrage d'une transaction SQL
    $pdo->beginTransaction();

    // Vérification de l'établissement destinataire
    $stmt = $pdo->prepare("
        SELECT id_etablissement
        FROM etablissement
        WHERE id_etablissement = ?
        FOR UPDATE
    ");

    $stmt->execute([$idEtablissement]);

    if (!$stmt->fetchColumn()) {
        throw new DomainException("Établissement introuvable.");
    }

    // Enregistrement du paquet
    $stmt = $pdo->prepare("
        INSERT INTO paquet (
            numero_paquet,
            nombre_cartes,
            date_envoi,
            id_etablissement,
            statut,
            observations
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $numero,
        $nbCartes,
        $dateEnvoi,
        $idEtablissement,
        'En attente',
        $observations
    ]);

    // Validation de la transaction
    $pdo->commit();

} catch (Throwable $e) {

    // Annulation en cas d'erreur
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $e;
}
