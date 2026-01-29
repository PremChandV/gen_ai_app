<?php
function getDb() {
    $server = "localhost";
    $database = "sctcrb";
    $user = "sa";
    $password = "<Your-DB-Password-Place-Here>";

    $dsn = "sqlsrv:Server=$server;Database=$database";

    try {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Test the connection
        $pdo->query("SELECT 1");
        
        return $pdo;
    } catch (Exception $e) {
        error_log("Database connection failed: " . $e->getMessage());
        return null;
    }
}

function getDatabaseSchema(PDO $db) {
    $sql = "
    SELECT 
        t.TABLE_SCHEMA,
        t.TABLE_NAME,
        c.COLUMN_NAME,
        c.DATA_TYPE,
        c.IS_NULLABLE,
        c.CHARACTER_MAXIMUM_LENGTH
    FROM INFORMATION_SCHEMA.TABLES t
    INNER JOIN INFORMATION_SCHEMA.COLUMNS c 
        ON t.TABLE_NAME = c.TABLE_NAME 
        AND t.TABLE_SCHEMA = c.TABLE_SCHEMA
    WHERE t.TABLE_TYPE = 'BASE TABLE'
        AND t.TABLE_SCHEMA NOT IN ('sys', 'INFORMATION_SCHEMA')
    ORDER BY t.TABLE_SCHEMA, t.TABLE_NAME, c.ORDINAL_POSITION
    ";

    try {
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return "No tables found in database";
        }

        $schema = [];
        $tableList = [];
        
        foreach ($rows as $row) {
            // Use schema.tablename format
            $fullTableName = $row["TABLE_SCHEMA"] . "." . $row["TABLE_NAME"];
            $tableList[$fullTableName] = true;
            
            $columnInfo = $row["COLUMN_NAME"] . " (" . $row["DATA_TYPE"];
            
            if ($row["CHARACTER_MAXIMUM_LENGTH"]) {
                $columnInfo .= "(" . $row["CHARACTER_MAXIMUM_LENGTH"] . ")";
            }
            
            $columnInfo .= ")";
            
            $schema[$fullTableName][] = $columnInfo;
        }

        // Build schema text for AI
        $text = "IMPORTANT: Always use the full table name format 'schema.tablename' in your SQL queries.\n\n";
        $text .= "AVAILABLE TABLES (" . count($tableList) . " total):\n";
        $text .= str_repeat("=", 27) . "\n\n";
        
        foreach ($schema as $table => $columns) {
            $text .= "TABLE: $table\n";
            $text .= "Columns: " . implode(", ", $columns) . "\n\n";
        }

        // Log for debugging
        file_put_contents(
            __DIR__ . "/schema_debug.log",
            date("c") . "\n" . $text . "\n" . str_repeat("=", 70) . "\n\n",
            FILE_APPEND
        );

        return $text;
        
    } catch (Exception $e) {
        error_log("Schema fetch failed: " . $e->getMessage());
        return "Error fetching schema: " . $e->getMessage();
    }
}

function executeReadOnlyQuery(PDO $db, $sql) {
    // Basic safety gate
    $sql = trim($sql);
    
    if (!preg_match('/^\s*select/i', $sql)) {
        throw new Exception("Only SELECT queries are allowed");
    }

    // Additional safety checks
    $forbiddenKeywords = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE', 'EXEC'];
    foreach ($forbiddenKeywords as $keyword) {
        if (stripos($sql, $keyword) !== false) {
            throw new Exception("Query contains forbidden keyword: $keyword");
        }
    }

    try {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        throw new Exception("Query execution failed: " . $e->getMessage());
    }
}
