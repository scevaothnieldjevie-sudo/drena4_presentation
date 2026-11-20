<?php
/**
 * DRENA4 - Connexion à la base de données
 * Technologie : PHP / PDO / MySQL
 */

$host = 'localhost';
$dbname = 'nom_de_votre_base';
$user = 'votre_utilisateur';
$pass = 'votre_mot_de_passe';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $pass
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit("Erreur de connexion à la base de données.");
}
