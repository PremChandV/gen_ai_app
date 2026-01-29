<?php

require "db.php";

$action = $_GET["action"] ?? "";

if ($action === "test") {
    try {
        $db = getDb();
        echo json_encode([
            "success" => $db !== null
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "error" => $e->getMessage()
        ]);
    }
}
