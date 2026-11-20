<?php
/**
 * DRENA4 - Synchronisation des statuts des cartes scolaires
 * Technologies : PHP / PDO / MySQL
 *
 * Recalcule les statuts des élèves à partir des distributions
 * enregistrées, des niveaux scolaires et des cartes disponibles.
 *
 * Cette fonction doit être appelée dans une transaction SQL.
 */

function recalculerStatutsEleves(PDO $pdo, int $idEtablissement): array
{
    $niveauxValides = ['6ème', '5ème', '4ème', '3ème', '2nde', '1ère', 'Tle'];

    // 1. Calcul du nombre total de cartes distribuées
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(nb_cartes_distribuees), 0)
        FROM distribution_eleves
        WHERE id_etablissement = ?
    ");
    $stmt->execute([$idEtablissement]);
    $nbCartesDistribuees = (int) $stmt->fetchColumn();

    // 2. Récupération des distributions détaillées par niveau
    $stmt = $pdo->prepare("
        SELECT dn.niveau, SUM(dn.nb_cartes_distribuees) AS nb_cartes
        FROM distribution_eleves d
        JOIN distribution_niveaux dn
            ON dn.id_distribution = d.id_distribution
        WHERE d.id_etablissement = ?
        GROUP BY dn.niveau
    ");
    $stmt->execute([$idEtablissement]);

    $cartesParNiveau = [];
    $nbCartesDetaillees = 0;

    foreach ($stmt->fetchAll() as $ligne) {
        $niveau = $ligne['niveau'] === 'Terminale'
            ? 'Tle'
            : $ligne['niveau'];

        if (in_array($niveau, $niveauxValides, true)) {
            $cartesParNiveau[$niveau] =
                ($cartesParNiveau[$niveau] ?? 0)
                + (int) $ligne['nb_cartes'];

            $nbCartesDetaillees += (int) $ligne['nb_cartes'];
        }
    }

    // 3. Prise en compte des anciennes distributions sans détail par niveau
    $cartesHistoriquesSansNiveau = max(
        0,
        $nbCartesDistribuees - $nbCartesDetaillees
    );

    // 4. Récupération des élèves classés par niveau
    $stmt = $pdo->prepare("
        SELECT el.id_eleve, cl.niveau
        FROM eleve el
        JOIN classe cl ON el.id_classe = cl.id_classe
        WHERE cl.id_etablissement = ?
        ORDER BY el.id_eleve
        FOR UPDATE
    ");
    $stmt->execute([$idEtablissement]);

    $elevesParNiveau = [];
    $elevesParIdentifiant = [];

    foreach ($stmt->fetchAll() as $eleve) {
        $idEleve = (int) $eleve['id_eleve'];

        $niveau = $eleve['niveau'] === 'Terminale'
            ? 'Tle'
            : $eleve['niveau'];

        if (in_array($niveau, $niveauxValides, true)) {
            $elevesParNiveau[$niveau][] = $idEleve;
        }

        $elevesParIdentifiant[] = $idEleve;
    }

    // 5. Initialisation des élèves au statut En attente de retrait
    $pdo->prepare("
        UPDATE eleve el
        JOIN classe cl ON el.id_classe = cl.id_classe
        SET el.statut_carte = 'En attente de retrait'
        WHERE cl.id_etablissement = ?
    ")->execute([$idEtablissement]);

    // 6. Attribution des cartes distribuées selon les niveaux scolaires
    $elevesAvecCarte = [];

    foreach ($cartesParNiveau as $niveau => $nombreCartes) {
        foreach (
            array_slice($elevesParNiveau[$niveau] ?? [], 0, $nombreCartes)
            as $idEleve
        ) {
            $elevesAvecCarte[$idEleve] = true;
        }
    }

    // Prise en compte des distributions historiques sans niveau
    foreach ($elevesParIdentifiant as $idEleve) {
        if (count($elevesAvecCarte) >= $nbCartesDistribuees) {
            break;
        }

        if ($cartesHistoriquesSansNiveau <= 0) {
            break;
        }

        if (!isset($elevesAvecCarte[$idEleve])) {
            $elevesAvecCarte[$idEleve] = true;
            $cartesHistoriquesSansNiveau--;
        }
    }

    // 7. Mise à jour des élèves ayant reçu leur carte
    foreach (array_chunk(array_keys($elevesAvecCarte), 500) as $lot) {
        $placeholders = implode(',', array_fill(0, count($lot), '?'));

        $pdo->prepare("
            UPDATE eleve
            SET statut_carte = 'Carte remise'
            WHERE id_eleve IN ($placeholders)
        ")->execute($lot);
    }

    // 8. Calcul du nombre de cartes encore disponibles
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(GREATEST(
            p.nombre_cartes - COALESCE(d.nb_distribuees, 0), 0
        )), 0)
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

    // 9. Si aucune carte ne reste disponible,
    // les élèves encore en attente passent au statut Carte non produite.
    if ($cartesDisponibles === 0) {
        $pdo->prepare("
            UPDATE eleve el
            JOIN classe cl ON el.id_classe = cl.id_classe
            SET el.statut_carte = 'Carte non produite'
            WHERE cl.id_etablissement = ?
            AND el.statut_carte = 'En attente de retrait'
        ")->execute([$idEtablissement]);
    }

    // 10. Retour des statistiques du traitement
    return [
        'distribuees' => count($elevesAvecCarte),
        'eleves_enregistres' => count($elevesParIdentifiant),
        'sans_eleve_enregistre' => max(
            0,
            $nbCartesDistribuees - count($elevesAvecCarte)
        ),
        'disponibles' => $cartesDisponibles,
        'en_attente' => max(
            0,
            count($elevesParIdentifiant) - count($elevesAvecCarte)
        ),
    ];
}
