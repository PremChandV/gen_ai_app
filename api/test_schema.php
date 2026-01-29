<?php
require "db.php";

header("Content-Type: application/json");

try {
    $db = getDb();
    
    if (!$db) {
        echo json_encode(["error" => "Database connection failed"]);
        exit;
    }

    $schema = getDatabaseSchema($db);
    
    // Get full table names
    $stmt = $db->query("
        SELECT 
            TABLE_SCHEMA + '.' + TABLE_NAME as FullTableName,
            TABLE_NAME as TableName,
            TABLE_SCHEMA as SchemaName
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_TYPE = 'BASE TABLE' 
        AND TABLE_SCHEMA = 'sctcrb'
        ORDER BY TABLE_NAME
    ");
    
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        "success" => true,
        "schema_text" => $schema,
        "tables" => $tables,
        "table_count" => count($tables)
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
