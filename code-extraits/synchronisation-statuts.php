<?php
/**
 * DRENA4 - Synchronisation des statuts des cartes scolaires
 * Technologies : PHP / PDO / MySQL
 *
 * Extrait simplifié de la logique métier.
 */

function recalculerStatutsEleves(PDO $pdo, int $idEtablissement): array
{
    // Récupération du nombre total de cartes distribuées
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(nb_cartes_distribuees), 0)
        FROM distribution_eleves
        WHERE id_etablissement = ?
    ");

    $stmt->execute([$idEtablissement]);
    $nbCartesDistribuees = (int) $stmt->fetchColumn();

    // Identification des élèves de l'établissement
    $stmt = $pdo->prepare("
        SELECT el.id_eleve
        FROM eleve el
        JOIN classe cl ON el.id_classe = cl.id_classe
        WHERE cl.id_etablissement = ?
        ORDER BY el.id_eleve
    ");

    $stmt->execute([$idEtablissement]);
    $eleves = $stmt->fetchAll();

    // Initialisation : tous les élèves sont en attente
    $pdo->prepare("
        UPDATE eleve el
        JOIN classe cl ON el.id_classe = cl.id_classe
        SET el.statut_carte = 'En attente de retrait'
        WHERE cl.id_etablissement = ?
    ")->execute([$idEtablissement]);

    // Identification des cartes disponibles
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            GREATEST(
                p.nombre_cartes - COALESCE(d.nb_distribuees, 0),
                0
            )
        ), 0)
        FROM paquet p
        LEFT JOIN (
            SELECT id_paquet,
                   SUM(nb_cartes_distribuees) AS nb_distribuees
            FROM distribution_eleves
            GROUP BY id_paquet
        ) d ON d.id_paquet = p.id_paquet
        WHERE p.id_etablissement = ?
    ");

    $stmt->execute([$idEtablissement]);
    $cartesDisponibles = (int) $stmt->fetchColumn();

    // Si aucune carte n'est disponible, les élèves
    // encore en attente passent au statut non produit.
    if ($cartesDisponibles === 0) {
        $pdo->prepare("
            UPDATE eleve el
            JOIN classe cl ON el.id_classe = cl.id_classe
            SET el.statut_carte = 'Carte non produite'
            WHERE cl.id_etablissement = ?
            AND el.statut_carte = 'En attente de retrait'
        ")->execute([$idEtablissement]);
    }

    return [
        'cartes_distribuees' => $nbCartesDistribuees,
        'eleves_enregistres' => count($eleves),
        'cartes_disponibles' => $cartesDisponibles
    ];
}
