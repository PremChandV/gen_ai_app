<?php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header("Content-Type: application/json");

require "db.php";
require "ai_engine.php";
require "meta_qns.php";

$input = json_decode(file_get_contents("php://input"), true);
$question = trim($input["question"] ?? "");
$aiEnabled = $input["ai_enabled"] ?? true; // Get AI toggle state from frontend

if ($question === "") {
    echo json_encode(["error" => "Empty question"]);
    exit;
}

try {
    $db = getDb();
    if (!$db) {
        throw new Exception("Database not connected");
    }

    // ========== Check if this is a meta-question first ==========
    $metaResult = handleMetaQuestion($question, $db);
    
    if ($metaResult !== null) {
        // This is a meta-question, return the result directly
        if (isset($metaResult['error'])) {
            http_response_code(403); // 403 Forbidden for security restrictions, 500 for other errors
            echo json_encode([
                "error" => $metaResult['error'],
                "details" => $metaResult['details'] ?? null,
                "is_meta_question" => true
            ]);
        } else {
            echo json_encode([
                "answer" => $metaResult['answer'],
                "data" => $metaResult['data'] ?? [],
                "row_count" => $metaResult['row_count'] ?? count($metaResult['data'] ?? []),
                "is_meta_question" => true,
                "sql" => null // No SQL was generated for meta questions
            ]);
        }
        exit;
    }
    // ========== END NEW CODE ==========

    // Check if AI is disabled
    if (!$aiEnabled) {
        echo json_encode([
            "error" => "AI Agent is disabled",
            "details" => "Please enable the AI Agent to generate SQL queries automatically, or write SQL manually."
        ]);
        exit;
    }

    // 1. Get schema
    $schemaText = getDatabaseSchema($db);

    // 2. Generate SQL using AI
    $sqlRaw = generateSQLFromText($question, $schemaText);

    if (!$sqlRaw) {
        throw new Exception("AI did not return SQL");
    }

    // Extract SELECT statement
    preg_match('/SELECT\s.+?(?=;|$)/is', $sqlRaw, $matches);
    $sql = trim($matches[0] ?? "");

    // Safety check: If no TOP clause and user wants "all", add a reasonable max limit for safety
    // This prevents accidentally loading millions of rows
    $hasTopClause = preg_match('/SELECT\s+TOP\s+\d+/i', $sql);
    $questionLower = strtolower($question);
    $wantsAll = preg_match('/\b(all|every|complete|entire|full)\b/', $questionLower);

    if ($wantsAll && !$hasTopClause) {
        // User wants "all" but let's add a safety limit of 1000 rows
        // You can adjust this number based on your needs
        $sql = preg_replace('/^SELECT\s+/i', 'SELECT TOP 1000 ', $sql);
        
        // Log this safety measure
        file_put_contents(
            __DIR__ . "/sql_debug.log",
            date("c") . " - SAFETY: Added TOP 1000 to 'all' query\n",
            FILE_APPEND
        );
    }

    if ($sql === "") {
        throw new Exception("No valid SELECT statement generated");
    }

    // FALLBACK: Add schema prefix if missing
    // Check if table names are missing schema prefix
    if (!preg_match('/\w+\.\w+/', $sql)) {
        // Try to add sctcrb schema prefix to table names
        $sql = preg_replace('/FROM\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', 'FROM sctcrb.$1', $sql);
        $sql = preg_replace('/JOIN\s+([a-zA-Z_][a-zA-Z0-9_]*)/i', 'JOIN sctcrb.$1', $sql);
    }

    file_put_contents(
        __DIR__ . "/sql_debug.log",
        date("c") . "\n=== ORIGINAL ===\n" . $sqlRaw . 
        "\n\n=== CLEANED ===\n" . $sql . "\n\n",
        FILE_APPEND
    );

    // 3. Execute SQL
    $rows = executeReadOnlyQuery($db, $sql);

    // 4. Build answer with sample data
    $answer = count($rows) === 0
        ? "Query executed successfully, but no rows were returned."
        : "Found " . count($rows) . " row(s).";

    // Add column info
    if (count($rows) > 0) {
        $columns = array_keys($rows[0]);
        $answer .= "\nColumns: " . implode(", ", $columns);
    }

    echo json_encode([
        "sql" => $sql,
        "answer" => $answer,
        "data" => $rows,
        "row_count" => count($rows),
        "is_meta_question" => false
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    
    $errorMsg = $e->getMessage();
    $suggestion = "";
    
    // Better error messages
    if (strpos($errorMsg, "Invalid object name") !== false) {
        preg_match("/'([^']+)'/", $errorMsg, $matches);
        $tableName = $matches[1] ?? "unknown";
        
        $suggestion = "\n\n TIP: The table '$tableName' doesn't exist.\n";
        $suggestion .= "Available tables:\n";
        
        try {
            $stmt = $db->query("
                SELECT TABLE_SCHEMA + '.' + TABLE_NAME as FullName
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_TYPE = 'BASE TABLE'
                AND TABLE_SCHEMA = 'sctcrb'
                ORDER BY TABLE_NAME
            ");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $suggestion .= "  • " . implode("\n  • ", $tables);
        } catch (Exception $ex) {
            $suggestion .= "  (Could not fetch table list)";
        }
    }
    
    echo json_encode([
        "error" => "Query failed",
        "details" => $errorMsg,
        "suggestion" => $suggestion,
        "sql" => $sql ?? $sqlRaw ?? "No SQL generated"
    ]);
}